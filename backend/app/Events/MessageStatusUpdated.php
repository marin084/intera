<?php

namespace App\Events;

use App\Broadcasting\WorkspaceChannel;
use App\Models\Message;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Message $message
    ) {}

    public function broadcastOn(): array
    {
        return [
            WorkspaceChannel::conversation((string) $this->message->conversation->workspace_id, $this->message->conversation_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'MessageStatusUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'status' => is_object($this->message->status) ? $this->message->status->value : ($this->message->status ?? 'sent'),
            'external_message_id' => $this->message->external_message_id,
            'metadata' => $this->message->metadata ?? [],
            'updated_at' => $this->message->updated_at?->toIso8601String(),
        ];
    }
}
