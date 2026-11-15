<?php

namespace App\Services\Runtime\Channels\Contracts;

use App\Models\Channel;
use App\Models\Message;
use App\Services\Runtime\Channels\ChannelCapabilities;
use App\Services\Runtime\Channels\InboundMessage;

interface ChannelAdapter
{
    /**
     * Get the channel type this adapter handles.
     */
    public function getChannelType(): string;

    /**
     * Get the technical and UI capabilities supported by this channel.
     */
    public function getCapabilities(): ChannelCapabilities;

    /**
     * Normalizes a raw inbound channel request/payload into a unified InboundMessage DTO.
     *
     * @param  array<string, mixed>  $rawPayload
     */
    public function normalizeInbound(Channel $channel, array $rawPayload): InboundMessage;

    /**
     * Formats an outbound Message model for delivery through this channel.
     *
     * @return array<string, mixed>
     */
    public function formatOutbound(Message $message): array;

    /**
     * Dispatches an outbound message to the external channel provider API, or returns null if not applicable (e.g. Webchat).
     *
     * @return array<string, mixed>|null
     */
    public function sendOutbound(Message $message): ?array;
}
