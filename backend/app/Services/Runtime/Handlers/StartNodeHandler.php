<?php

namespace App\Services\Runtime\Handlers;

use App\Enums\FlowFailureCode;
use App\Models\FlowExecution;
use App\Models\FlowNode;
use App\Models\Message;
use App\Services\Runtime\Contracts\NodeHandler;
use App\Services\Runtime\ExecutionContext;
use App\Services\Runtime\Results\NodeExecutionResult;

class StartNodeHandler implements NodeHandler
{
    public function execute(FlowNode $node, FlowExecution $execution, ExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::completed();
    }

    public function resume(FlowNode $node, FlowExecution $execution, ExecutionContext $context, Message $message): NodeExecutionResult
    {
        return NodeExecutionResult::failed(
            FlowFailureCode::EXECUTION_STATE_ERROR->value,
            'El nodo de inicio (Start) no espera respuestas de usuario.'
        );
    }
}
