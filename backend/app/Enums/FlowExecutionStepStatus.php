<?php

namespace App\Enums;

enum FlowExecutionStepStatus: string
{
    case ENTERED = 'entered';
    case COMPLETED = 'completed';
    case WAITING = 'waiting';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
