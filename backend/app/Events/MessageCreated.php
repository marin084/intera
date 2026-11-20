<?php

namespace App\Events;

use App\Broadcasting\WorkspaceChannel;
use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageCreated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Message $message
    ) {}

    public function broadcastOn(): array
    {
        $workspaceId = (string) $this->message->conversation->workspace_id;
        $channels = [
            WorkspaceChannel::inbox($workspaceId),
            WorkspaceChannel::conversation($workspaceId, $this->message->conversation_id),
        ];

        $visibility = $this->message->visibility ?? 'public';
        if ($visibility === 'public' || $visibility === 'external') {
            $channels[] = new PrivateChannel("webchat.conversation.{$this->message->conversation_id}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'MessageCreated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'client_message_id' => $this->message->external_message_id ?? $this->message->metadata['client_message_id'] ?? null,
            'conversation_id' => $this->message->conversation_id,
            'sender_type' => is_object($this->message->sender_type) ? $this->message->sender_type->value : $this->message->sender_type,
            'sender_id' => $this->message->sender_id,
            'direction' => is_object($this->message->direction) ? $this->message->direction->value : $this->message->direction,
            'message_type' => is_object($this->message->message_type) ? $this->message->message_type->value : $this->message->message_type,
            'visibility' => $this->message->visibility,
            'status' => is_object($this->message->status) ? $this->message->status->value : ($this->message->status ?? 'sent'),
            'content' => $this->message->content,
            'payload' => $this->message->payload ?? [],
            'metadata' => $this->message->metadata,
            'sent_at' => $this->message->sent_at?->toIso8601String(),
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
