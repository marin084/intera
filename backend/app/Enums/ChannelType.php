<?php

namespace App\Enums;

enum ChannelType: string
{
    case WEBCHAT = 'webchat';
    case WHATSAPP = 'whatsapp';
    case FACEBOOK_MESSENGER = 'facebook_messenger';
    case INSTAGRAM = 'instagram';
    case TELEGRAM = 'telegram';

    public static function supportedInPhase1(): array
    {
        return [
            self::WEBCHAT->value,
            self::WHATSAPP->value,
            self::FACEBOOK_MESSENGER->value,
            self::INSTAGRAM->value,
            self::TELEGRAM->value,
        ];
    }

    public static function supportedTypes(): array
    {
        return [
            self::WEBCHAT->value,
            self::WHATSAPP->value,
            self::FACEBOOK_MESSENGER->value,
            self::INSTAGRAM->value,
            self::TELEGRAM->value,
        ];
    }
}
