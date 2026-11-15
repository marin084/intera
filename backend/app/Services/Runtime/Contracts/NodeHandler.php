<?php

namespace App\Services\Runtime\Contracts;

use App\Models\FlowExecution;
use App\Models\FlowNode;
use App\Models\Message;
use App\Services\Runtime\ExecutionContext;
use App\Services\Runtime\Results\NodeExecutionResult;

interface NodeHandler
{
    /**
     * Executes the node when entered.
     */
    public function execute(FlowNode $node, FlowExecution $execution, ExecutionContext $context): NodeExecutionResult;

    /**
     * Resumes the node upon receiving an inbound message (used by interactive nodes such as Question).
     */
    public function resume(FlowNode $node, FlowExecution $execution, ExecutionContext $context, Message $message): NodeExecutionResult;
}
