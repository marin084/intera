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
use App\Services\Runtime\Contracts\NodeHandler;
use App\Services\Runtime\ExecutionContext;
use App\Services\Runtime\Results\NodeExecutionResult;
use App\Services\Runtime\VariableInterpolator;
use Illuminate\Support\Facades\DB;

class TransferToAgentNodeHandler implements NodeHandler
{
    public function __construct(
        protected VariableInterpolator $interpolator
    ) {}

    public function execute(FlowNode $node, FlowExecution $execution, ExecutionContext $context): NodeExecutionResult
    {
        $config = $node->configuration ?? [];
        $rawMessage = $config['message'] ?? null;
        $createdMessageId = null;

        $isSimulation = (bool) ($execution->conversation?->is_simulation ?? false);

        if ($isSimulation) {
            $simulatedNotice = 'El bot transferiría esta conversación a un agente.';
            $transferContent = ! empty($rawMessage) && is_string($rawMessage)
                ? $this->interpolator->interpolate($rawMessage, $context)['text']."\n\n".$simulatedNotice
                : $simulatedNotice;

            $msg = Message::create([
                'conversation_id' => $execution->conversation_id,
                'flow_execution_id' => $execution->id,
                'flow_node_id' => $node->id,
                'sender_type' => MessageSenderType::BOT,
                'sender_id' => null,
                'direction' => MessageDirection::OUTBOUND,
                'message_type' => MessageType::TEXT,
                'content' => $transferContent,
                'metadata' => [
                    'node_key' => $node->node_key,
                    'is_simulated_transfer' => true,
                    'reason' => $config['reason'] ?? ($config['target_queue'] ?? 'Transferencia a agente simulada'),
                    'context_available' => $context->toArray(),
                ],
                'sent_at' => now(),
            ]);

            $execution->conversation?->update(['last_message_at' => now()]);

            return NodeExecutionResult::transferred(
                output: [
                    'message_id' => $msg->id,
                    'simulated_transfer' => true,
                    'message' => $simulatedNotice,
                    'reason' => $config['reason'] ?? ($config['target_queue'] ?? null),
                    'context_available' => $context->toArray(),
                    'resulting_status' => 'completed',
                ],
                metadata: [
                    'node_key' => $node->node_key,
                    'simulated' => true,
                    'reason' => $config['reason'] ?? ($config['target_queue'] ?? null),
                ]
            );
        }

        if (! empty($rawMessage) && is_string($rawMessage)) {
            $interpolated = $this->interpolator->interpolate($rawMessage, $context);

            $msg = Message::create([
                'conversation_id' => $execution->conversation_id,
                'flow_execution_id' => $execution->id,
                'flow_node_id' => $node->id,
                'sender_type' => MessageSenderType::BOT,
                'sender_id' => null,
                'direction' => MessageDirection::OUTBOUND,
                'message_type' => MessageType::TEXT,
                'content' => $interpolated['text'],
                'metadata' => [
                    'node_key' => $node->node_key,
                    'is_transfer_announcement' => true,
                ],
                'sent_at' => now(),
            ]);

            $execution->conversation?->update(['last_message_at' => now()]);
            $createdMessageId = $msg->id;

            DB::afterCommit(function () use ($msg) {
                broadcast(new MessageCreated($msg));
            });
        }

        return NodeExecutionResult::transferred(
            output: [
                'message_id' => $createdMessageId,
                'transferred_to' => 'human_inbox',
            ],
            metadata: [
                'node_key' => $node->node_key,
            ]
        );
    }

    public function resume(FlowNode $node, FlowExecution $execution, ExecutionContext $context, Message $message): NodeExecutionResult
    {
        return NodeExecutionResult::failed(
            FlowFailureCode::EXECUTION_STATE_ERROR->value,
            'La conversación ya fue transferida a un agente y no espera respuestas de flujo.'
        );
    }
}
