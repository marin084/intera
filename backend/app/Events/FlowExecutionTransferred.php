<?php

namespace App\Events;

use App\Models\FlowExecution;
use App\Models\FlowNode;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FlowExecutionTransferred
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly FlowExecution $execution,
        public readonly FlowNode $node
    ) {}
}
