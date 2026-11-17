<?php

namespace App\Services\Routing;

use App\Enums\ConversationStatus;
use App\Events\ConversationStatusChanged;
use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\ConversationQueueEntry;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

class ConversationQueueService
{
    public function defaultTeam(): Team
    {
        return Team::firstOrCreate(
            ['is_default' => true],
            ['name' => 'Equipo General', 'description' => 'Equipo inicial para la atención existente.', 'is_active' => true]
        );
    }

    public function teamForChannel(string $channelId): Team
    {
        return Team::query()
            ->whereHas('channels', fn ($query) => $query->where('channels.id', $channelId))
            ->where('is_active', true)
            ->first() ?? $this->defaultTeam();
    }

    /** Start or preserve the current queue interval, measuring from the handoff. */
    public function enter(Conversation|string $conversation, ?string $targetTeamId = null): ?Conversation
    {
        $conversationId = $conversation instanceof Conversation ? (string) $conversation->id : $conversation;

        return DB::transaction(function () use ($conversationId, $targetTeamId) {
            $locked = Conversation::query()->whereKey($conversationId)->lockForUpdate()->first();
            if (! $locked || $locked->status === ConversationStatus::CLOSED || $locked->assigned_user_id !== null) {
                return $locked;
            }

            $team = $targetTeamId
                ? Team::query()->whereKey($targetTeamId)->where('is_active', true)->first()
                : null;
            $team ??= $this->teamForChannel((string) $locked->channel_id);
            $activeEntry = ConversationQueueEntry::query()
                ->where('conversation_id', $locked->id)
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->latest('entered_at')
                ->first();

            if ($activeEntry && (string) $activeEntry->team_id === (string) $team->id) {
                $enteredAt = $activeEntry->entered_at;
            } else {
                if ($activeEntry) {
                    $activeEntry->update(['ended_at' => now(), 'exit_reason' => 'routed']);
                }

                $enteredAt = now();
                ConversationQueueEntry::create([
                    'conversation_id' => $locked->id,
                    'team_id' => $team->id,
                    'entered_at' => $enteredAt,
                ]);
            }

            $locked->update([
                'team_id' => $team->id,
                'queued_at' => $enteredAt,
                'status' => ConversationStatus::WAITING_AGENT,
                'assigned_user_id' => null,
            ]);

            return $locked->fresh(['team']);
        });
    }

    public function leave(Conversation|string $conversation, string $reason, ?int $assignedUserId = null): void
    {
        $conversationId = $conversation instanceof Conversation ? (string) $conversation->id : $conversation;
        DB::transaction(function () use ($conversationId, $reason, $assignedUserId) {
            $locked = Conversation::query()->whereKey($conversationId)->lockForUpdate()->first();
            if (! $locked) return;

            $entry = ConversationQueueEntry::query()
                ->where('conversation_id', $locked->id)
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->latest('entered_at')
                ->first();

            if ($entry) {
                $endedAt = now();
                $entry->update([
                    'assigned_at' => $reason === 'assigned' ? $endedAt : null,
                    'assigned_user_id' => $reason === 'assigned' ? $assignedUserId : null,
                    'ended_at' => $endedAt,
                    'exit_reason' => $reason,
                ]);
            }

            if ($locked->queued_at !== null) {
                $locked->update(['queued_at' => null]);
            }
        });
    }

    public function routeWaitingForChannels(array $channelIds): void
    {
        if ($channelIds === []) return;

        Conversation::query()
            ->whereIn('channel_id', $channelIds)
            ->where('status', ConversationStatus::WAITING_AGENT)
            ->whereNull('assigned_user_id')
            ->orderBy('queued_at')
            ->select('id')
            ->chunkById(100, function ($conversations) {
                foreach ($conversations as $conversation) {
                    $routed = $this->enter((string) $conversation->id);
                    if ($routed) {
                        DB::afterCommit(function () use ($routed) {
                            $fresh = $routed->fresh(['team', 'channel', 'contact', 'assignedUser']);
                            if (! $fresh) return;
                            broadcast(new ConversationUpdated($fresh));
                            broadcast(new ConversationStatusChanged($fresh));
                        });
                    }
                }
            });
    }
}
