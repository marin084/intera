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

class MessageNodeHandler implements NodeHandler
{
    public function __construct(
        protected VariableInterpolator $interpolator
    ) {}

    public function execute(FlowNode $node, FlowExecution $execution, ExecutionContext $context): NodeExecutionResult
    {
        $config = $node->configuration ?? [];
        $rawText = $config['text'] ?? null;

        if ($rawText === null || ! is_string($rawText)) {
            return NodeExecutionResult::failed(
                FlowFailureCode::INVALID_NODE_CONFIG->value,
                "El nodo de mensaje '{$node->node_key}' no contiene texto configurado."
            );
        }

        $interpolated = $this->interpolator->interpolate($rawText, $context);

        $message = Message::create([
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
                'used_variables' => $interpolated['used_variables'],
                'missing_variables' => $interpolated['missing_variables'],
            ],
            'sent_at' => now(),
        ]);

        $execution->conversation?->update(['last_message_at' => now()]);

        DB::afterCommit(function () use ($message) {
            broadcast(new MessageCreated($message));
        });

        return NodeExecutionResult::completed(
            output: [
                'message_id' => $message->id,
                'text' => $interpolated['text'],
            ],
            metadata: [
                'used_variables' => $interpolated['used_variables'],
                'missing_variables' => $interpolated['missing_variables'],
            ]
        );
    }

    public function resume(FlowNode $node, FlowExecution $execution, ExecutionContext $context, Message $message): NodeExecutionResult
    {
        return NodeExecutionResult::failed(
            FlowFailureCode::EXECUTION_STATE_ERROR->value,
            'El nodo de mensaje no espera respuestas.'
        );
    }
}
