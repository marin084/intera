<?php

namespace App\Services\Runtime\Handlers;

use App\Enums\FlowFailureCode;
use App\Models\FlowExecution;
use App\Models\FlowNode;
use App\Models\Message;
use App\Services\Runtime\Contracts\NodeHandler;
use App\Services\Runtime\ExecutionContext;
use App\Services\Runtime\Results\NodeExecutionResult;

class GotoNodeHandler implements NodeHandler
{
    public function execute(FlowNode $node, FlowExecution $execution, ExecutionContext $context): NodeExecutionResult
    {
        $config = $node->configuration ?? [];
        $targetKey = $config['target_node_key'] ?? null;

        if (empty($targetKey) || ! is_string($targetKey) || trim($targetKey) === '') {
            return NodeExecutionResult::failed(
                FlowFailureCode::INVALID_NODE_CONFIG->value,
                "El bloque 'Ir a Bloque' ({$node->node_key}) no tiene un nodo de destino configurado."
            );
        }

        return NodeExecutionResult::completed(
            output: [
                'target_node_key' => trim($targetKey),
            ],
            outgoingHandle: 'goto_'.trim($targetKey),
            metadata: [
                'is_goto' => true,
                'target_node_key' => trim($targetKey),
            ]
        );
    }

    public function resume(FlowNode $node, FlowExecution $execution, ExecutionContext $context, Message $message): NodeExecutionResult
    {
        return NodeExecutionResult::failed(
            FlowFailureCode::EXECUTION_STATE_ERROR->value,
            'El nodo Goto no espera interacción de mensajes.'
        );
    }
}
