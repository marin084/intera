<?php

namespace App\Enums;

enum MessageDirection: string
{
    case INBOUND = 'inbound';
    case OUTBOUND = 'outbound';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
