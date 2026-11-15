<?php

namespace App\Services\Runtime\Handlers;

use App\Enums\FlowFailureCode;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageType;
use App\Events\ContactUpdated;
use App\Events\MessageCreated;
use App\Models\FlowExecution;
use App\Models\FlowNode;
use App\Models\Message;
use App\Services\Geo\CountryNormalizer;
use App\Services\Runtime\Contracts\NodeHandler;
use App\Services\Runtime\ExecutionContext;
use App\Services\Runtime\Results\NodeExecutionResult;
use App\Services\Runtime\VariableInterpolator;
use Illuminate\Support\Facades\DB;

class QuestionNodeHandler implements NodeHandler
{
    public function __construct(
        protected VariableInterpolator $interpolator
    ) {}

    public function execute(FlowNode $node, FlowExecution $execution, ExecutionContext $context): NodeExecutionResult
    {
        $config = $node->configuration ?? [];
        $rawText = $config['text'] ?? null;
        $variable = $config['variable'] ?? null;

        if (empty($rawText) || ! is_string($rawText)) {
            return NodeExecutionResult::failed(
                FlowFailureCode::INVALID_NODE_CONFIG->value,
                "El nodo de pregunta '{$node->node_key}' no contiene texto configurado."
            );
        }

        if (empty($variable) || ! is_string($variable)) {
            return NodeExecutionResult::failed(
                FlowFailureCode::INVALID_NODE_CONFIG->value,
                "El nodo de pregunta '{$node->node_key}' debe especificar una variable para almacenar la respuesta."
            );
        }

        $interpolated = $this->interpolator->interpolate($rawText, $context);
        $inputType = $config['input_type'] ?? 'text';
        $rawChoices = $config['choices'] ?? ($config['options'] ?? []);
        $isChoice = $inputType === 'choice' && ! empty($rawChoices);
        $options = $isChoice ? $this->buildOptionsArray($rawChoices) : [];
        $choiceLabels = $isChoice ? array_column($options, 'title') : null;

        $message = Message::create([
            'conversation_id' => $execution->conversation_id,
            'flow_execution_id' => $execution->id,
            'flow_node_id' => $node->id,
            'sender_type' => MessageSenderType::BOT,
            'sender_id' => null,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => $isChoice ? MessageType::INTERACTIVE : MessageType::TEXT,
            'content' => $interpolated['text'],
            'payload' => $isChoice ? [
                'options' => $options,
            ] : null,
            'metadata' => array_filter([
                'node_key' => $node->node_key,
                'target_variable' => $variable,
                'input_type' => $inputType,
                'choices' => $choiceLabels ?? ($config['choices'] ?? null),
                'options' => $isChoice ? $options : null,
                'used_variables' => $interpolated['used_variables'],
                'missing_variables' => $interpolated['missing_variables'],
            ], fn ($val) => $val !== null),
            'sent_at' => now(),
        ]);

        $execution->conversation?->update(['last_message_at' => now()]);

        DB::afterCommit(function () use ($message) {
            broadcast(new MessageCreated($message));
        });

        return NodeExecutionResult::waitingInput(
            output: [
                'question_message_id' => $message->id,
                'text' => $interpolated['text'],
            ],
            metadata: [
                'variable' => $variable,
                'input_type' => $config['input_type'] ?? 'text',
            ]
        );
    }

    public function resume(FlowNode $node, FlowExecution $execution, ExecutionContext $context, Message $message): NodeExecutionResult
    {
        $config = $node->configuration ?? [];
        $variable = $config['variable'] ?? null;
        $inputType = $config['input_type'] ?? 'text';
        $content = trim($message->content ?? '');

        if (empty($variable)) {
            return NodeExecutionResult::failed(
                FlowFailureCode::INVALID_NODE_CONFIG->value,
                "El nodo de pregunta '{$node->node_key}' no tiene configurada la variable de destino."
            );
        }

        $validation = $this->validateInput($content, $inputType, $config);

        if (! $validation['valid']) {
            // Send invalid response message and keep waiting_input
            $invalidText = $config['invalid_message'] ?? $validation['default_message'];

            $isChoice = $inputType === 'choice';
            $rawChoices = $config['choices'] ?? ($config['options'] ?? []);
            $options = $isChoice ? $this->buildOptionsArray($rawChoices) : [];
            $choiceLabels = $isChoice ? array_column($options, 'title') : null;

            $payload = null;
            if ($isChoice && ! empty($options)) {
                $payload = [
                    'options' => $options,
                    'validation_error' => true,
                ];
            }

            $retryMsg = Message::create([
                'conversation_id' => $execution->conversation_id,
                'flow_execution_id' => $execution->id,
                'flow_node_id' => $node->id,
                'sender_type' => MessageSenderType::BOT,
                'sender_id' => null,
                'direction' => MessageDirection::OUTBOUND,
                'message_type' => ($isChoice && ! empty($options)) ? MessageType::INTERACTIVE : MessageType::TEXT,
                'content' => $invalidText,
                'payload' => $payload,
                'metadata' => array_filter([
                    'node_key' => $node->node_key,
                    'is_validation_retry' => true,
                    'attempted_input' => $content,
                    'choices' => $choiceLabels ?? ($config['choices'] ?? null),
                    'options' => $options ?: null,
                ], fn ($val) => $val !== null),
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
                    'error' => $validation['error'],
                ]
            );
        }

        // Store valid value in context
        $parsedValue = $validation['value'];
        $context->set($variable, $parsedValue);

        // Sync to Contact if contact_field is mapped
        $contactField = $config['contact_field'] ?? ($config['sync_contact_field'] ?? 'none');
        $this->syncContactField($execution, $contactField, $parsedValue);

        return NodeExecutionResult::completed(
            output: [
                'variable' => $variable,
                'value' => $parsedValue,
            ],
            metadata: [
                'input_type' => $inputType,
                'contact_field' => $contactField,
            ]
        );
    }

    protected function syncContactField(FlowExecution $execution, ?string $contactField, mixed $value): void
    {
        if (! $contactField || $contactField === 'none' || ! in_array($contactField, ['display_name', 'email', 'phone', 'country'], true)) {
            return;
        }

        $conversation = $execution->conversation;
        if (! $conversation) {
            return;
        }

        $contact = $conversation->contact;
        if (! $contact) {
            return;
        }

        $stringVal = trim((string) $value);

        if ($contactField === 'display_name') {
            if ($stringVal === '') {
                return;
            }
            // Max length 255
            $cleanName = mb_substr($stringVal, 0, 255);
            $contact->display_name = $cleanName;
            $contact->save();
        } elseif ($contactField === 'email') {
            $filteredEmail = filter_var($stringVal, FILTER_VALIDATE_EMAIL);
            if (! $filteredEmail) {
                return;
            }
            $contact->email = mb_substr($filteredEmail, 0, 255);
            $contact->save();
        } elseif ($contactField === 'phone') {
            $normalizedPhone = preg_replace('/[^\d+]/', '', $stringVal);
            if (! $normalizedPhone || strlen($normalizedPhone) < 6 || strlen($normalizedPhone) > 30) {
                return;
            }
            $contact->phone = $normalizedPhone;
            $contact->save();
        } elseif ($contactField === 'country') {
            $iso = CountryNormalizer::normalize($stringVal) ?? (strlen($stringVal) === 2 ? strtoupper($stringVal) : null);
            if (! $iso) {
                return;
            }
            $contact->country = $iso;
            $contact->save();
        }

        DB::afterCommit(function () use ($conversation, $contact) {
            broadcast(new ContactUpdated($conversation, $contact));
        });
    }

    /**
     * @return array{valid: bool, value?: mixed, error?: string, default_message?: string}
     */
    protected function validateInput(string $content, string $inputType, array $config): array
    {
        if ($content === '') {
            return [
                'valid' => false,
                'error' => 'Input is empty',
                'default_message' => 'Por favor, introduce una respuesta válida.',
            ];
        }

        return match ($inputType) {
            'number' => $this->validateNumber($content),
            'email' => $this->validateEmail($content),
            'choice' => $this->validateChoice($content, $config['choices'] ?? []),
            'country' => $this->validateCountry($content),
            default => [
                'valid' => true,
                'value' => $content,
            ],
        };
    }

    protected function validateCountry(string $content): array
    {
        $iso = CountryNormalizer::normalize($content);

        if ($iso === null) {
            return [
                'valid' => false,
                'error' => 'Input is not a recognized country',
                'default_message' => 'Por favor, escribe un país válido (ej. Costa Rica, Colombia, México).',
            ];
        }

        return [
            'valid' => true,
            'value' => $iso,
        ];
    }

    protected function validateNumber(string $content): array
    {
        if (! is_numeric($content)) {
            return [
                'valid' => false,
                'error' => 'Input is not a number',
                'default_message' => 'Por favor, ingresa un número válido.',
            ];
        }

        $numericValue = str_contains($content, '.') ? (float) $content : (int) $content;

        return [
            'valid' => true,
            'value' => $numericValue,
        ];
    }

    protected function validateEmail(string $content): array
    {
        $filtered = filter_var($content, FILTER_VALIDATE_EMAIL);

        if ($filtered === false) {
            return [
                'valid' => false,
                'error' => 'Input is not a valid email',
                'default_message' => 'Por favor, ingresa una dirección de correo electrónico válida.',
            ];
        }

        return [
            'valid' => true,
            'value' => $filtered,
        ];
    }

    /**
     * @param array<mixed> $choices
     * @return array<int, array{id: string, title: string, text: string, value: string}>
     */
    public function buildOptionsArray(array $choices): array
    {
        return array_values(array_map(function ($choice, $idx) {
            if (is_array($choice)) {
                $id = (string) ($choice['id'] ?? ($idx + 1));
                $title = (string) ($choice['title'] ?? ($choice['text'] ?? ($choice['value'] ?? $id)));
                $text = (string) ($choice['text'] ?? $title);
                $value = (string) ($choice['value'] ?? $title);

                return [
                    'id' => $id,
                    'title' => $title,
                    'text' => $text,
                    'value' => $value,
                ];
            }

            $str = (string) $choice;

            return [
                'id' => (string) ($idx + 1),
                'title' => $str,
                'text' => $str,
                'value' => $str,
            ];
        }, array_values($choices), array_keys(array_values($choices))));
    }

    protected function validateChoice(string $content, array $choices): array
    {
        $normalizedInput = mb_strtolower(trim($content));

        foreach ($choices as $idx => $choice) {
            if (is_array($choice)) {
                $choiceTitle = mb_strtolower(trim((string) ($choice['title'] ?? ($choice['text'] ?? ($choice['value'] ?? '')))));
                $choiceId = mb_strtolower(trim((string) ($choice['id'] ?? '')));
                $choiceVal = mb_strtolower(trim((string) ($choice['value'] ?? '')));

                if ($normalizedInput === $choiceTitle || $normalizedInput === $choiceId || ($choiceVal !== '' && $normalizedInput === $choiceVal) || (string) ($idx + 1) === $normalizedInput) {
                    return [
                        'valid' => true,
                        'value' => $choice['value'] ?? ($choice['title'] ?? ($choice['text'] ?? $choice['id'])),
                    ];
                }
            } else {
                if (mb_strtolower(trim((string) $choice)) === $normalizedInput || (string) ($idx + 1) === $normalizedInput) {
                    return [
                        'valid' => true,
                        'value' => $choice,
                    ];
                }
            }
        }

        $formattedChoices = implode(', ', array_map(function ($c) {
            return is_array($c) ? ($c['title'] ?? ($c['text'] ?? ($c['value'] ?? ''))) : (string) $c;
        }, $choices));

        return [
            'valid' => false,
            'error' => 'Input does not match choices',
            'default_message' => "Por favor, elige una de las opciones válidas: {$formattedChoices}.",
        ];
    }
}
