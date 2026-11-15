<?php

namespace App\Enums;

enum MessageType: string
{
    case TEXT = 'text';
    case SYSTEM = 'system';
    case IMAGE = 'image';
    case FILE = 'file';
    case VIDEO = 'video';
    case AUDIO = 'audio';
    case DOCUMENT = 'document';
    case LOCATION = 'location';
    case TEMPLATE = 'template';
    case INTERACTIVE = 'interactive';

    public static function supportedInPhase1(): array
    {
        return [self::TEXT->value, self::SYSTEM->value];
    }
}
