<?php

namespace App\Events;

use App\Broadcasting\WorkspaceChannel;
use App\Models\Message;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageReactionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): array
    {
        return [WorkspaceChannel::conversation((string) $this->message->conversation->workspace_id, $this->message->conversation_id)];
    }

    public function broadcastAs(): string
    {
        return 'MessageReactionUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'reactions' => $this->message->metadata['reactions'] ?? [],
        ];
    }
}
