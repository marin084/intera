<?php

namespace App\Enums;

enum MessageSenderType: string
{
    case CONTACT = 'contact';
    case BOT = 'bot';
    case AGENT = 'agent';
    case SYSTEM = 'system';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
