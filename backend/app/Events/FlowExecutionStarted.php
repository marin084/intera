<?php

namespace App\Events;

use App\Models\FlowExecution;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FlowExecutionStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly FlowExecution $execution
    ) {}
}
