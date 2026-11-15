<?php

namespace App\Services\Runtime\Handlers;

use App\Enums\FlowFailureCode;
use App\Exceptions\ConditionEvaluationException;
use App\Models\FlowExecution;
use App\Models\FlowNode;
use App\Models\Message;
use App\Services\Runtime\ConditionEvaluator;
use App\Services\Runtime\Contracts\NodeHandler;
use App\Services\Runtime\ExecutionContext;
use App\Services\Runtime\Results\NodeExecutionResult;

class ConditionNodeHandler implements NodeHandler
{
    public function __construct(
        protected ConditionEvaluator $evaluator
    ) {}

    public function execute(FlowNode $node, FlowExecution $execution, ExecutionContext $context): NodeExecutionResult
    {
        $config = $node->configuration ?? [];

        try {
            $result = $this->evaluator->evaluate($config, $context);
            $varName = ltrim(trim((string) ($config['variable'] ?? '')), '$');

            return NodeExecutionResult::completed(
                outgoingHandle: $result ? 'true' : 'false',
                output: [
                    'evaluation' => $result,
                    'variable' => $config['variable'] ?? null,
                    'operator' => $config['operator'] ?? null,
                    'expected' => $config['value'] ?? null,
                    'actual' => $varName !== '' ? $context->get($varName) : null,
                ]
            );
        } catch (ConditionEvaluationException $e) {
            return NodeExecutionResult::failed(
                FlowFailureCode::CONDITION_ERROR->value,
                "Error al evaluar condición en nodo '{$node->node_key}': {$e->getMessage()}"
            );
        }
    }

    public function resume(FlowNode $node, FlowExecution $execution, ExecutionContext $context, Message $message): NodeExecutionResult
    {
        return NodeExecutionResult::failed(
            FlowFailureCode::EXECUTION_STATE_ERROR->value,
            'El nodo de condición no espera respuestas.'
        );
    }
}
