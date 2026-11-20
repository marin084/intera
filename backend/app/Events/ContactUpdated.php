<?php

namespace App\Events;

use App\Broadcasting\WorkspaceChannel;
use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContactUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public Contact $contact
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
        return 'ContactUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'contact' => [
                'id' => $this->contact->id,
                'display_name' => $this->contact->display_name,
                'email' => $this->contact->email,
                'phone' => $this->contact->phone,
                'country' => $this->contact->country,
                'external_identifier' => $this->contact->external_identifier,
            ],
        ];
    }
}
