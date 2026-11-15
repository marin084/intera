<?php

namespace App\Enums;

enum FlowFailureCode: string
{
    case NODE_NOT_FOUND = 'NODE_NOT_FOUND';
    case HANDLER_NOT_FOUND = 'HANDLER_NOT_FOUND';
    case INVALID_NODE_CONFIG = 'INVALID_NODE_CONFIG';
    case INVALID_EDGE = 'INVALID_EDGE';
    case MAX_STEPS_EXCEEDED = 'MAX_STEPS_EXCEEDED';
    case INFINITE_LOOP_DETECTED = 'INFINITE_LOOP_DETECTED';
    case CONDITION_ERROR = 'CONDITION_ERROR';
    case HTTP_REQUEST_ERROR = 'HTTP_REQUEST_ERROR';
    case EXECUTION_STATE_ERROR = 'EXECUTION_STATE_ERROR';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
