<?php

namespace App\Enums;

enum FlowExecutionStatus: string
{
    case RUNNING = 'running';
    case WAITING_INPUT = 'waiting_input';
    case WAITING_AGENT = 'waiting_agent';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
