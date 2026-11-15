<?php

namespace App\Services\Runtime\Handlers;

use App\Enums\FlowFailureCode;
use App\Models\FlowExecution;
use App\Models\FlowNode;
use App\Models\Message;
use App\Services\Runtime\Contracts\NodeHandler;
use App\Services\Runtime\ExecutionContext;
use App\Services\Runtime\Results\NodeExecutionResult;
use App\Services\Runtime\VariableInterpolator;

class SetVariableNodeHandler implements NodeHandler
{
    public function __construct(
        protected VariableInterpolator $interpolator
    ) {}

    public function execute(FlowNode $node, FlowExecution $execution, ExecutionContext $context): NodeExecutionResult
    {
        $config = $node->configuration ?? [];
        $variable = $config['variable'] ?? $config['variable_name'] ?? null;
        $operation = $config['operation'] ?? 'set';
        $rawValue = $config['value'] ?? null;
        $type = $config['data_type'] ?? $config['type'] ?? 'text';

        if (empty($variable) || ! is_string($variable) || trim($variable) === '') {
            return NodeExecutionResult::failed(
                FlowFailureCode::INVALID_NODE_CONFIG->value,
                "El nodo 'Establecer Variable' ({$node->node_key}) debe especificar el nombre de una variable."
            );
        }

        $varName = ltrim(trim($variable), '$');

        if ($operation === 'clear') {
            $context->remove($varName);

            return NodeExecutionResult::completed(output: ['variable' => $varName, 'cleared' => true]);
        }

        // Interpolate value if it's a string
        $finalValue = $rawValue;
        if (is_string($rawValue)) {
            $interp = $this->interpolator->interpolate($rawValue, $context);
            $finalValue = $interp['text'];
        }

        // Handle numeric operations
        if ($operation === 'increment' || $operation === 'decrement') {
            $current = (float) $context->get($varName, 0);
            $step = is_numeric($finalValue) ? (float) $finalValue : 1;
            $finalValue = $operation === 'increment' ? ($current + $step) : ($current - $step);
        } else {
            // Type conversion if specified
            if ($type === 'number' && is_numeric($finalValue)) {
                $finalValue = str_contains((string) $finalValue, '.') ? (float) $finalValue : (int) $finalValue;
            } elseif ($type === 'boolean') {
                $finalValue = filter_var($finalValue, FILTER_VALIDATE_BOOLEAN);
            } elseif ($type === 'json' && is_string($finalValue)) {
                $decoded = json_decode($finalValue, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $finalValue = $decoded;
                }
            }
        }

        $context->set($varName, $finalValue);

        return NodeExecutionResult::completed(
            output: [
                'variable' => $varName,
                'value' => $finalValue,
            ],
            metadata: [
                'operation' => $operation,
            ]
        );
    }

    public function resume(FlowNode $node, FlowExecution $execution, ExecutionContext $context, Message $message): NodeExecutionResult
    {
        return NodeExecutionResult::failed(
            FlowFailureCode::EXECUTION_STATE_ERROR->value,
            'El nodo Establecer Variable no espera mensajes.'
        );
    }
}
