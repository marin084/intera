<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationWaitingAgent implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Conversation $conversation
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
        return 'ConversationWaitingAgent';
    }

    public function broadcastWith(): array
    {
        $contact = $this->conversation->contact;
        $channel = $this->conversation->channel;

        return [
            'id' => $this->conversation->id,
            'channel_id' => $this->conversation->channel_id,
            'channel' => $channel ? [
                'id' => $channel->id,
                'name' => $channel->name,
                'type' => is_object($channel->type) ? $channel->type->value : $channel->type,
            ] : null,
            'status' => 'waiting_agent',
            'contact_id' => $this->conversation->contact_id,
            'contact' => $contact ? [
                'id' => $contact->id,
                'display_name' => $contact->display_name,
            ] : null,
            'started_at' => $this->conversation->started_at?->toIso8601String(),
            'last_message_at' => $this->conversation->last_message_at?->toIso8601String(),
            'created_at' => $this->conversation->created_at?->toIso8601String(),
            'waiting_since' => now()->toIso8601String(),
        ];
    }
}
