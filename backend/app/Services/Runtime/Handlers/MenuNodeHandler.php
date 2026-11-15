<?php

namespace App\Services\Runtime\Handlers;

use App\Enums\FlowFailureCode;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageType;
use App\Events\MessageCreated;
use App\Models\FlowExecution;
use App\Models\FlowNode;
use App\Models\Message;
use App\Services\Presence\AgentAvailabilityService;
use App\Services\Runtime\Contracts\NodeHandler;
use App\Services\Runtime\ExecutionContext;
use App\Services\Runtime\Results\NodeExecutionResult;
use App\Services\Runtime\VariableInterpolator;
use Illuminate\Support\Facades\DB;

class MenuNodeHandler implements NodeHandler
{
    public function __construct(
        protected VariableInterpolator $interpolator
    ) {}

    public function execute(FlowNode $node, FlowExecution $execution, ExecutionContext $context): NodeExecutionResult
    {
        $config = $node->configuration ?? [];
        $rawText = $config['text'] ?? ($config['title'] ?? 'Selecciona una opción:');
        $options = $config['options'] ?? [];

        if (empty($rawText) || ! is_string($rawText)) {
            return NodeExecutionResult::failed(
                FlowFailureCode::INVALID_NODE_CONFIG->value,
                "El nodo de menú '{$node->node_key}' debe contener un texto o pregunta."
            );
        }

        if (! is_array($options) || empty($options)) {
            return NodeExecutionResult::failed(
                FlowFailureCode::INVALID_NODE_CONFIG->value,
                "El nodo de menú '{$node->node_key}' debe configurar al menos una opción."
            );
        }

        $interpolated = $this->interpolator->interpolate($rawText, $context);
        $normalizedOptions = $this->buildOptionsArray($options);
        $visibleOptions = array_values(array_filter($normalizedOptions, fn ($opt) => empty($opt['is_hidden'])));
        $choiceLabels = array_column($visibleOptions, 'title');

        $message = Message::create([
            'conversation_id' => $execution->conversation_id,
            'flow_execution_id' => $execution->id,
            'flow_node_id' => $node->id,
            'sender_type' => MessageSenderType::BOT,
            'sender_id' => null,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::INTERACTIVE,
            'content' => $interpolated['text'],
            'payload' => [
                'options' => $visibleOptions,
            ],
            'metadata' => [
                'node_key' => $node->node_key,
                'is_menu' => true,
                'choices' => $choiceLabels,
                'options' => $visibleOptions,
                'target_variable' => $config['variable'] ?? null,
                'used_variables' => $interpolated['used_variables'],
                'missing_variables' => $interpolated['missing_variables'],
            ],
            'sent_at' => now(),
        ]);

        $execution->conversation?->update(['last_message_at' => now()]);

        DB::afterCommit(function () use ($message) {
            broadcast(new MessageCreated($message));
        });

        return NodeExecutionResult::waitingInput(
            output: [
                'menu_message_id' => $message->id,
                'text' => $interpolated['text'],
                'options_count' => count($visibleOptions),
                'total_options_count' => count($options),
            ],
            metadata: [
                'variable' => $config['variable'] ?? null,
                'options' => $options,
            ]
        );
    }

    public function resume(FlowNode $node, FlowExecution $execution, ExecutionContext $context, Message $message): NodeExecutionResult
    {
        $config = $node->configuration ?? [];
        $options = $config['options'] ?? [];
        $content = trim($message->content ?? '');

        if (empty($options)) {
            return NodeExecutionResult::failed(
                FlowFailureCode::INVALID_NODE_CONFIG->value,
                "El nodo de menú '{$node->node_key}' no contiene opciones configuradas."
            );
        }

        $normalizedOptions = $this->buildOptionsArray($options);
        $matchedOption = $this->findMatchingOption($content, $normalizedOptions);

        // Fallback matching using interactive button/list id if not matched by content text
        if (! $matchedOption) {
            $interactiveId = $message->metadata['button_reply_id']
                ?? $message->metadata['list_reply_id']
                ?? $message->metadata['interactive_reply']['id']
                ?? $message->payload['button_reply_id']
                ?? $message->payload['list_reply_id']
                ?? $message->payload['interactive_reply']['id']
                ?? null;
            if ($interactiveId !== null) {
                $matchedOption = $this->findMatchingOption((string) $interactiveId, $normalizedOptions);
            }
        }

        if (! $matchedOption) {
            $visibleOptions = array_values(array_filter($normalizedOptions, fn ($opt) => empty($opt['is_hidden'])));
            $choiceLabels = array_column($visibleOptions, 'title');
            $formattedChoices = implode(', ', $choiceLabels);
            $invalidText = $config['invalid_message'] ?? "Por favor, elige una de las siguientes opciones: {$formattedChoices}.";

            $retryMsg = Message::create([
                'conversation_id' => $execution->conversation_id,
                'flow_execution_id' => $execution->id,
                'flow_node_id' => $node->id,
                'sender_type' => MessageSenderType::BOT,
                'sender_id' => null,
                'direction' => MessageDirection::OUTBOUND,
                'message_type' => MessageType::INTERACTIVE,
                'content' => $invalidText,
                'payload' => [
                    'options' => $visibleOptions,
                    'validation_error' => true,
                ],
                'metadata' => [
                    'node_key' => $node->node_key,
                    'is_menu_retry' => true,
                    'is_menu' => true,
                    'is_validation_retry' => true,
                    'choices' => $choiceLabels,
                    'options' => $visibleOptions,
                    'attempted_input' => $content,
                ],
                'sent_at' => now(),
            ]);

            $execution->conversation?->update(['last_message_at' => now()]);

            DB::afterCommit(function () use ($retryMsg) {
                broadcast(new MessageCreated($retryMsg));
            });

            return NodeExecutionResult::waitingInput(
                metadata: [
                    'validation_failed' => true,
                    'attempted_input' => $content,
                ]
            );
        }

        // Store into target variable if configured
        $targetVar = $config['variable'] ?? null;
        if (! empty($targetVar) && is_string($targetVar)) {
            $valueToStore = $matchedOption['value'] ?? $matchedOption['text'];
            $context->set($targetVar, $valueToStore);
        }

        $handle = 'opt_'.($matchedOption['id'] ?? 'default');

        return NodeExecutionResult::completed(
            output: [
                'selected_option_id' => $matchedOption['id'] ?? null,
                'selected_option_text' => $matchedOption['text'] ?? '',
                'selected_option_value' => $matchedOption['value'] ?? ($matchedOption['text'] ?? ''),
                'target_node_key' => $matchedOption['target_node_key'] ?? null,
            ],
            outgoingHandle: $handle,
            metadata: [
                'option' => $matchedOption,
            ]
        );
    }

    /**
     * @param array<mixed> $options
     * @return array<int, array<string, mixed>>
     */
    public function buildOptionsArray(array $options): array
    {
        return array_values(array_map(function ($opt, $idx) {
            if (is_array($opt)) {
                $id = (string) ($opt['id'] ?? ($idx + 1));
                $title = (string) ($opt['title'] ?? ($opt['text'] ?? ($opt['value'] ?? $id)));
                $text = (string) ($opt['text'] ?? $title);
                $isHidden = isset($opt['is_hidden'])
                    ? (bool) $opt['is_hidden']
                    : (isset($opt['visible']) ? ! (bool) $opt['visible'] : false);

                $hideIfNoAgents = ! empty($opt['hide_if_no_agents']);
                if ($hideIfNoAgents && ! AgentAvailabilityService::hasOnlineAgents()) {
                    $isHidden = true;
                }

                return array_merge($opt, [
                    'id' => $id,
                    'title' => $title,
                    'text' => $text,
                    'is_hidden' => $isHidden,
                    'hide_if_no_agents' => $hideIfNoAgents,
                ]);
            }

            $str = (string) $opt;

            return [
                'id' => (string) ($idx + 1),
                'title' => $str,
                'text' => $str,
                'value' => $str,
                'is_hidden' => false,
            ];
        }, array_values($options), array_keys(array_values($options))));
    }

    /**
     * Finds matching option by text, value, id, or 1-based index among visible options,
     * or by exact secret match on hidden options.
     *
     * @param  array<int, array<string, mixed>>  $options
     * @return array<string, mixed>|null
     */
    protected function findMatchingOption(string $input, array $options): ?array
    {
        $normalizedInput = mb_strtolower(trim($input));
        if ($normalizedInput === '') {
            return null;
        }

        $visibleOptions = array_values(array_filter($options, fn ($opt) => empty($opt['is_hidden'])));
        $hiddenOptions = array_values(array_filter($options, fn ($opt) => ! empty($opt['is_hidden'])));

        // 1. Match visible options by text or title
        foreach ($visibleOptions as $opt) {
            $optText = mb_strtolower(trim((string) ($opt['text'] ?? ($opt['title'] ?? ''))));
            if ($optText === $normalizedInput) {
                return $opt;
            }
        }

        // 2. Match visible options by id or explicit value
        foreach ($visibleOptions as $opt) {
            $optId = mb_strtolower(trim((string) ($opt['id'] ?? '')));
            $optVal = mb_strtolower(trim((string) ($opt['value'] ?? '')));
            if ($optId === $normalizedInput || ($optVal !== '' && $optVal === $normalizedInput)) {
                return $opt;
            }
        }

        // 3. Match visible options by 1-based numeric index (e.g. "1" for 1st visible option)
        if (ctype_digit($normalizedInput)) {
            $index = ((int) $normalizedInput) - 1;
            if (isset($visibleOptions[$index])) {
                return $visibleOptions[$index];
            }
        }

        // 4. Secret shortcut: match hidden options by exact text, title, id, or value
        foreach ($hiddenOptions as $opt) {
            $optText = mb_strtolower(trim((string) ($opt['text'] ?? ($opt['title'] ?? ''))));
            $optId = mb_strtolower(trim((string) ($opt['id'] ?? '')));
            $optVal = mb_strtolower(trim((string) ($opt['value'] ?? '')));

            if ($optText === $normalizedInput
                || $optId === $normalizedInput
                || ($optVal !== '' && $optVal === $normalizedInput)) {
                return $opt;
            }
        }

        return null;
    }
}
