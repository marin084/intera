<?php

namespace App\Events;

use App\Broadcasting\WorkspaceChannel;
use App\Models\Conversation;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Conversation $conversation
    ) {}

    public function broadcastOn(): array
    {
        $workspaceId = (string) $this->conversation->workspace_id;
        $channels = [
            WorkspaceChannel::inbox($workspaceId),
            WorkspaceChannel::conversation($workspaceId, $this->conversation->id),
        ];

        if ($this->conversation->assigned_user_id) {
            $channels[] = WorkspaceChannel::user($workspaceId, $this->conversation->assigned_user_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'ConversationUpdated';
    }

    public function broadcastWith(): array
    {
        $channel = $this->conversation->channel;
        $contact = $this->conversation->contact;
        $assignedUser = $this->conversation->assignedUser;

        return [
            'id' => $this->conversation->id,
            'channel_id' => $this->conversation->channel_id,
            'channel' => $channel ? [
                'id' => $channel->id,
                'name' => $channel->name,
                'type' => is_object($channel->type) ? $channel->type->value : $channel->type,
            ] : null,
            'contact_id' => $this->conversation->contact_id,
            'contact' => $contact ? [
                'id' => $contact->id,
                'display_name' => $contact->display_name,
                'phone' => $contact->phone,
                'email' => $contact->email,
            ] : null,
            'status' => is_object($this->conversation->status) ? $this->conversation->status->value : $this->conversation->status,
            'assigned_user_id' => $this->conversation->assigned_user_id,
            'assigned_user' => $assignedUser ? [
                'id' => $assignedUser->id,
                'name' => $assignedUser->name,
                'email' => $assignedUser->email,
            ] : null,
            'started_at' => $this->conversation->started_at?->toIso8601String(),
            'last_message_at' => $this->conversation->last_message_at?->toIso8601String(),
            'closed_at' => $this->conversation->closed_at?->toIso8601String(),
            'updated_at' => $this->conversation->updated_at?->toIso8601String(),
            'whatsapp_window' => $this->conversation->whatsapp_window,
            'meta_window' => $this->conversation->meta_window,
            'closing_assigned_user_id' => $this->conversation->closing_assigned_user_id,
        ];
    }
}
