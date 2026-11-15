<?php

namespace App\Events;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationAssigned implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public ?User $assignedUser = null,
        public ?string $reason = null
    ) {}

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('inbox'),
            new PrivateChannel("conversation.{$this->conversation->id}"),
            new PrivateChannel("webchat.conversation.{$this->conversation->id}"),
        ];

        if ($this->conversation->assigned_user_id) {
            $channels[] = new PrivateChannel("user.{$this->conversation->assigned_user_id}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'ConversationAssigned';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->conversation->id,
            'status' => is_object($this->conversation->status) ? $this->conversation->status->value : $this->conversation->status,
            'assigned_user_id' => $this->conversation->assigned_user_id,
            'assigned_user' => $this->assignedUser ? [
                'id' => $this->assignedUser->id,
                'name' => $this->assignedUser->name,
                'email' => $this->assignedUser->email,
            ] : null,
            'reason' => $this->reason,
        ];
    }
}
