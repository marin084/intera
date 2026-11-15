<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public ?string $previousStatus = null
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('inbox'),
            new PrivateChannel("conversation.{$this->conversation->id}"),
            new PrivateChannel("webchat.conversation.{$this->conversation->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ConversationStatusChanged';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->conversation->id,
            'status' => is_object($this->conversation->status) ? $this->conversation->status->value : $this->conversation->status,
            'previous_status' => $this->previousStatus,
            'assigned_user_id' => $this->conversation->assigned_user_id,
            'last_message_at' => $this->conversation->last_message_at?->toIso8601String(),
        ];
    }
}
