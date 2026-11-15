<?php

namespace App\Services\Runtime\Channels;

class ChannelCapabilities
{
    public function __construct(
        public readonly bool $supportsButtons = true,
        public readonly bool $supportsLists = true,
        public readonly bool $supportsMarkdown = true,
        public readonly bool $supportsQuickReplies = true,
        public readonly bool $supportsAttachments = false,
        public readonly int $maxMessageLength = 4000,
        public readonly int $maxButtonOptions = 10,
    ) {}

    public static function webchat(): self
    {
        return new self(
            supportsButtons: true,
            supportsLists: true,
            supportsMarkdown: true,
            supportsQuickReplies: true,
            supportsAttachments: false,
            maxMessageLength: 4000,
            maxButtonOptions: 10,
        );
    }

    public static function whatsapp(): self
    {
        return new self(
            supportsButtons: true,
            supportsLists: true,
            supportsMarkdown: true,
            supportsQuickReplies: true,
            supportsAttachments: true,
            maxMessageLength: 4096,
            maxButtonOptions: 3, // WhatsApp interactive buttons limit
        );
    }

    public static function facebookMessenger(): self
    {
        return new self(
            supportsButtons: true,
            supportsLists: true,
            supportsMarkdown: false,
            supportsQuickReplies: true,
            supportsAttachments: false, // Inbound attachments normalized as informative notice; no local disk persistence pipeline in this phase
            maxMessageLength: 2000,
            maxButtonOptions: 3,
        );
    }

    public static function instagram(): self
    {
        return new self(
            supportsButtons: true,
            supportsLists: true,
            supportsMarkdown: false,
            supportsQuickReplies: true,
            supportsAttachments: false, // Inbound attachments normalized as informative notice; no local disk persistence pipeline in this phase
            maxMessageLength: 1000,
            maxButtonOptions: 3,
        );
    }

    public static function telegram(): self
    {
        return new self(
            supportsButtons: true,
            supportsLists: true,
            supportsMarkdown: true,
            supportsQuickReplies: true,
            supportsAttachments: false, // Inbound media normalized with notice/caption; file_id retained without persistent storage pipeline
            maxMessageLength: 4096,
            maxButtonOptions: 8,
        );
    }
}
