<?php

namespace App\Events;

use App\Models\FlowExecution;
use App\Models\FlowExecutionStep;
use App\Models\FlowNode;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FlowNodeEntered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly FlowExecution $execution,
        public readonly FlowNode $node,
        public readonly FlowExecutionStep $step
    ) {}
}
