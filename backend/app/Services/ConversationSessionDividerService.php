<?php

namespace App\Services;

use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\MessageCreated;
use App\Events\MessageStatusUpdated;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ConversationSessionDividerService
{
    public function ensureClosedSessionDivider(
        Conversation $conversation,
        string $source,
        ?CarbonInterface $closedAt = null
    ): Message {
        $timestamp = $closedAt ? Carbon::instance($closedAt) : now();
        $closedAtEpoch = (string) $timestamp->getTimestamp();

        $existing = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('metadata->event', 'session_divider')
            ->where('metadata->closed_at_epoch', $closedAtEpoch)
            ->first();

        if ($existing) {
            return $existing;
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::SYSTEM,
            'sender_id' => 'system',
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::SYSTEM,
            'visibility' => 'internal',
            'status' => MessageStatus::SENT,
            'content' => '── Sesión finalizada ──',
            'metadata' => [
                'event' => 'session_divider',
                'timestamp' => $timestamp->toIso8601String(),
                'closed_at_epoch' => $closedAtEpoch,
                'source' => $source,
            ],
            'sent_at' => $timestamp,
        ]);

        DB::afterCommit(fn () => broadcast(new MessageCreated($message)));

        return $message;
    }

    public function hasClosedSessionDivider(Conversation $conversation, ?CarbonInterface $closedAt): bool
    {
        if (! $closedAt) {
            return false;
        }

        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('metadata->event', 'session_divider')
            ->where('metadata->closed_at_epoch', (string) $closedAt->getTimestamp())
            ->exists();
    }

    public function create(Conversation $conversation, string $source): Message
    {
        $timestamp = now();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::SYSTEM,
            'sender_id' => 'system',
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::SYSTEM,
            'visibility' => 'internal',
            'status' => MessageStatus::SENT,
            'content' => '── Nueva sesión ──',
            'metadata' => [
                'event' => 'session_divider',
                'timestamp' => $timestamp->toIso8601String(),
                'source' => $source,
            ],
            'sent_at' => $timestamp,
        ]);

        DB::afterCommit(fn () => broadcast(new MessageCreated($message)));

        return $message;
    }

    public function markAsSessionStart(Message $message, string $source): void
    {
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if (isset($metadata['session_divider_before'])) {
            return;
        }

        $metadata['session_divider_before'] = [
            'event' => 'session_divider',
            'timestamp' => now()->toIso8601String(),
            'source' => $source,
        ];

        $message->update(['metadata' => $metadata]);
        $updated = $message->fresh() ?? $message;

        DB::afterCommit(fn () => broadcast(new MessageStatusUpdated($updated)));
    }
}
