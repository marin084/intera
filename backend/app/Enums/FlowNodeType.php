<?php

namespace App\Enums;

enum FlowNodeType: string
{
    case START = 'start';
    case MESSAGE = 'message';
    case IMAGE = 'image';
    case FILE = 'file';
    case QUESTION = 'question';
    case CONDITION = 'condition';
    case MENU = 'menu';
    case HTTP_REQUEST = 'http_request';
    case GOTO = 'goto';
    case SET_VARIABLE = 'set_variable';
    case TRANSFER_TO_AGENT = 'transfer_to_agent';
    case END = 'end';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
