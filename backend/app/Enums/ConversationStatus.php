<?php

namespace App\Enums;

enum ConversationStatus: string
{
    case BOT = 'bot';
    case WAITING_AGENT = 'waiting_agent';
    case ASSIGNED = 'assigned';
    case CLOSED = 'closed';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
