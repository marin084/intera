<?php

namespace App\Services\Runtime\Channels\Adapters;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Message;
use App\Services\Runtime\Channels\ChannelCapabilities;
use App\Services\Runtime\Channels\Contracts\ChannelAdapter;
use App\Services\Runtime\Channels\InboundMessage;

class WebchatChannelAdapter implements ChannelAdapter
{
    public function getChannelType(): string
    {
        return ChannelType::WEBCHAT->value;
    }

    public function getCapabilities(): ChannelCapabilities
    {
        return ChannelCapabilities::webchat();
    }

    public function normalizeInbound(Channel $channel, array $rawPayload): InboundMessage
    {
        return InboundMessage::fromPayload(
            channel: $channel,
            contactPayload: $rawPayload['contact'] ?? [],
            content: (string) ($rawPayload['content'] ?? ''),
            externalMessageId: $rawPayload['client_message_id'] ?? ($rawPayload['external_message_id'] ?? null),
            metadata: $rawPayload['metadata'] ?? [],
            correlationId: $rawPayload['correlation_id'] ?? null
        );
    }

    public function formatOutbound(Message $message): array
    {
        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_type' => $message->sender_type->value,
            'direction' => $message->direction->value,
            'message_type' => $message->message_type->value,
            'content' => $message->content,
            'payload' => $message->payload ?? [],
            'metadata' => $message->metadata,
            'sent_at' => $message->sent_at?->toIso8601String(),
        ];
    }

    public function sendOutbound(Message $message): ?array
    {
        // Webchat delivers in real-time via WebSocket (Reverb) broadcast on MessageCreated.
        // No external HTTP API dispatch is required.
        return null;
    }
}
