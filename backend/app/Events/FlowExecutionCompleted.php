<?php

namespace App\Events;

use App\Models\FlowExecution;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FlowExecutionCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly FlowExecution $execution
    ) {}
}
