<?php

namespace App\Events;

use App\Broadcasting\WorkspaceChannel;
use App\Models\Conversation;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationClosed implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Conversation $conversation
    ) {}

    public function broadcastOn(): array
    {
        $workspaceId = (string) $this->conversation->workspace_id;

        return [
            WorkspaceChannel::inbox($workspaceId),
            WorkspaceChannel::conversation($workspaceId, $this->conversation->id),
            new PrivateChannel("webchat.conversation.{$this->conversation->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ConversationClosed';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->conversation->id,
            'status' => 'closed',
            'closed_at' => $this->conversation->closed_at?->toIso8601String() ?? now()->toIso8601String(),
        ];
    }
}
