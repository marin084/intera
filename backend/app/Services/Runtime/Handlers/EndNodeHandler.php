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

class EndNodeHandler implements NodeHandler
{
    public function __construct(
        protected VariableInterpolator $interpolator
    ) {}

    public function execute(FlowNode $node, FlowExecution $execution, ExecutionContext $context): NodeExecutionResult
    {
        $config = $node->configuration ?? [];
        $rawMessage = $config['message'] ?? null;
        $createdMessageId = null;

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
                    'is_flow_closing_message' => true,
                ],
                'sent_at' => now(),
            ]);

            $execution->conversation?->update(['last_message_at' => now()]);
            $createdMessageId = $msg->id;

            DB::afterCommit(function () use ($msg) {
                broadcast(new MessageCreated($msg));
            });
        }

        return NodeExecutionResult::flowCompleted(
            output: [
                'message_id' => $createdMessageId,
                'closed' => true,
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
            'El flujo ya ha finalizado y no procesa mensajes adicionales.'
        );
    }
}
