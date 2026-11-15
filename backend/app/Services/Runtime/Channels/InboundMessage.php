<?php

namespace App\Services\Runtime\Channels;

use App\Enums\MessageType;
use App\Models\Channel;
use Carbon\Carbon;
use Illuminate\Support\Str;

class InboundMessage
{
    public function __construct(
        public readonly Channel $channel,
        public readonly string $content,
        public readonly ?string $externalMessageId = null,
        public readonly ?string $contactExternalIdentifier = null,
        public readonly ?string $contactDisplayName = null,
        public readonly ?string $contactEmail = null,
        public readonly ?string $contactPhone = null,
        public readonly array $contactMetadata = [],
        public readonly array $messageMetadata = [],
        public readonly string $correlationId = '',
        public readonly string|MessageType $messageType = MessageType::TEXT,
        public readonly array $payload = [],
        public readonly ?Carbon $sourceSentAt = null,
    ) {}

    public static function fromPayload(
        Channel $channel,
        array $contactPayload,
        string $content,
        ?string $externalMessageId = null,
        array $metadata = [],
        ?string $correlationId = null,
        string|MessageType $messageType = MessageType::TEXT,
        array $payload = [],
        ?Carbon $sourceSentAt = null
    ): self {
        return new self(
            channel: $channel,
            content: $content,
            externalMessageId: $externalMessageId,
            contactExternalIdentifier: $contactPayload['external_identifier'] ?? null,
            contactDisplayName: $contactPayload['display_name'] ?? null,
            contactEmail: $contactPayload['email'] ?? null,
            contactPhone: $contactPayload['phone'] ?? null,
            contactMetadata: $contactPayload['metadata'] ?? [],
            messageMetadata: $metadata,
            correlationId: $correlationId ?: (string) Str::uuid(),
            messageType: $messageType,
            payload: $payload,
            sourceSentAt: $sourceSentAt,
        );
    }

    public function toContactPayload(): array
    {
        return array_filter([
            'external_identifier' => $this->contactExternalIdentifier,
            'display_name' => $this->contactDisplayName,
            'email' => $this->contactEmail,
            'phone' => $this->contactPhone,
            'metadata' => $this->contactMetadata,
        ], fn ($val) => $val !== null);
    }
}
