<?php

namespace App\Services\Analytics;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationQueueEntry;
use App\Models\Message;
use App\Models\Team;
use App\Models\WebchatSession;
use App\Services\Presence\AgentAvailabilityService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class RealtimeAnalyticsService
{
    /**
     * Compute realtime dashboard metrics.
     *
     * @param string|null $channelId
     * @return array<string, mixed>
     */
    public function getRealtimeMetrics(?string $channelId = null): array
    {
        $now = now();
        $oneHourAgo = $now->copy()->subMinutes(60);
        $twoHoursAgo = $now->copy()->subMinutes(120);

        // 1. Webchat active visitors in the last 15 minutes
        $webchatVisitorsQuery = WebchatSession::query()
            ->where('last_seen_at', '>=', $now->copy()->subMinutes(15))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $now);

        if ($channelId) {
            $webchatVisitorsQuery->where('channel_id', $channelId);
        }

        $activeWebchatVisitors = $webchatVisitorsQuery->distinct('contact_id')->count('contact_id');

        // 2. Unread / Waiting Response Chats
        // Active conversations not closed, either waiting_agent or last message is from contact
        $unreadChats = $this->countUnreadOrWaitingChats($channelId);

        // 3. Chats in the last hour + trend vs previous hour
        $chatsCurrentHour = Conversation::query()
            ->when($channelId, fn (Builder $q) => $q->where('channel_id', $channelId))
            ->where('started_at', '>=', $oneHourAgo)
            ->count();

        $chatsPreviousHour = Conversation::query()
            ->when($channelId, fn (Builder $q) => $q->where('channel_id', $channelId))
            ->where('started_at', '>=', $twoHoursAgo)
            ->where('started_at', '<', $oneHourAgo)
            ->count();

        $chatsTrendPercentage = $chatsPreviousHour > 0
            ? round((($chatsCurrentHour - $chatsPreviousHour) / $chatsPreviousHour) * 100, 1)
            : ($chatsCurrentHour > 0 ? 100.0 : 0.0);

        // 4. Messages in the last hour (Sent / Received)
        $messagesQuery = Message::query()
            ->where('sent_at', '>=', $oneHourAgo)
            ->whereHas('conversation', function (Builder $q) use ($channelId) {
                if ($channelId) {
                    $q->where('channel_id', $channelId);
                }
            });

        $messagesSent = (clone $messagesQuery)
            ->where('direction', MessageDirection::OUTBOUND->value)
            ->count();

        $messagesReceived = (clone $messagesQuery)
            ->where('direction', MessageDirection::INBOUND->value)
            ->count();

        $messagesTotal = $messagesSent + $messagesReceived;

        // 5. Operational times and queues
        $avgQueueWaitSeconds = $this->calculateAvgQueueWaitSeconds($channelId);
        $avgFirstResponseSeconds = $this->calculateAvgFirstResponseSeconds($channelId);
        $avgNextResponseSeconds = $this->calculateAvgNextResponseSeconds($channelId);
        $avgResolutionSeconds = $this->calculateAvgResolutionSeconds($channelId);

        // 6. Agent conversations status
        $ongoingConversations = Conversation::query()
            ->when($channelId, fn (Builder $q) => $q->where('channel_id', $channelId))
            ->where('status', ConversationStatus::ASSIGNED->value)
            ->count();

        $closedTodayConversations = Conversation::query()
            ->when($channelId, fn (Builder $q) => $q->where('channel_id', $channelId))
            ->where('status', ConversationStatus::CLOSED->value)
            ->where('closed_at', '>=', $now->copy()->startOfDay())
            ->count();

        $onlineAgentsCount = AgentAvailabilityService::getOnlineAgents()->count();

        // 7. Channel summary breakdown
        $channelsSummary = $this->buildChannelsSummary();

        return [
            'last_hour_kpis' => [
                'active_webchat_visitors' => $activeWebchatVisitors,
                'unread_chats' => $unreadChats,
                'chats_last_hour' => $chatsCurrentHour,
                'chats_previous_hour' => $chatsPreviousHour,
                'chats_trend_percentage' => $chatsTrendPercentage,
                'messages_last_hour' => [
                    'sent' => $messagesSent,
                    'received' => $messagesReceived,
                    'total' => $messagesTotal,
                ],
            ],
            'operating_times' => [
                'avg_queue_wait_seconds' => $avgQueueWaitSeconds,
                'avg_queue_wait_formatted' => $this->formatSeconds($avgQueueWaitSeconds),
                'avg_first_response_seconds' => $avgFirstResponseSeconds,
                'avg_first_response_formatted' => $this->formatSeconds($avgFirstResponseSeconds),
                'avg_next_response_seconds' => $avgNextResponseSeconds,
                'avg_next_response_formatted' => $this->formatSeconds($avgNextResponseSeconds),
                'avg_resolution_seconds' => $avgResolutionSeconds,
                'avg_resolution_formatted' => $this->formatSeconds($avgResolutionSeconds),
            ],
            'agent_conversations' => [
                'ongoing' => $ongoingConversations,
                'closed_today' => $closedTodayConversations,
                'active_agents_count' => $onlineAgentsCount,
            ],
            'channels_summary' => $channelsSummary,
            'team_sla_summary' => $this->buildTeamSlaSummary($channelId),
            'updated_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Count open conversations that are either in waiting_agent or where last message was inbound.
     */
    protected function countUnreadOrWaitingChats(?string $channelId = null): int
    {
        return Conversation::query()
            ->when($channelId, fn (Builder $q) => $q->where('channel_id', $channelId))
            ->where('status', '!=', ConversationStatus::CLOSED->value)
            ->where(function (Builder $q) {
                $q->where('status', ConversationStatus::WAITING_AGENT->value)
                    ->orWhere(function (Builder $sub) {
                        $sub->where('status', ConversationStatus::ASSIGNED->value)
                            ->whereExists(function ($ex) {
                                $ex->selectRaw(1)
                                    ->from('messages')
                                    ->whereColumn('messages.conversation_id', 'conversations.id')
                                    ->where('messages.direction', MessageDirection::INBOUND->value)
                                    ->where('messages.sent_at', '>=', function ($inner) {
                                        $inner->selectRaw("COALESCE(MAX(m2.sent_at), '1970-01-01')")
                                            ->from('messages as m2')
                                            ->whereColumn('m2.conversation_id', 'conversations.id')
                                            ->where('m2.sender_type', MessageSenderType::AGENT->value);
                                    });
                            });
                    });
            })
            ->count();
    }

    /**
     * Calculate human queue wait in seconds from handoff to assignment (or now for active waits).
     */
    protected function calculateAvgQueueWaitSeconds(?string $channelId = null): int
    {
        $today = now()->startOfDay();
        $waitTimes = [];

        $assignments = ConversationQueueEntry::query()
            ->whereNotNull('assigned_at')
            ->where('assigned_at', '>=', $today)
            ->where('exit_reason', 'assigned')
            ->when($channelId, fn (Builder $q) => $q->whereHas('conversation', fn ($conversation) => $conversation->where('channel_id', $channelId)))
            ->get(['entered_at', 'assigned_at']);

        foreach ($assignments as $entry) {
            $start = Carbon::parse($entry->entered_at);
            $assigned = Carbon::parse($entry->assigned_at);
            $diff = max(0, (int) $start->diffInSeconds($assigned, false));
            if ($diff <= 86400 * 2) {
                $waitTimes[] = $diff;
            }
        }

        // Active waiting conversations count from the most recent queue entry.
        $currentlyWaiting = ConversationQueueEntry::query()
            ->whereNull('ended_at')
            ->whereHas('conversation', fn (Builder $q) => $q
                ->where('status', ConversationStatus::WAITING_AGENT->value)
                ->whereNull('assigned_user_id')
                ->when($channelId, fn (Builder $channelQuery) => $channelQuery->where('channel_id', $channelId)))
            ->get(['entered_at']);

        foreach ($currentlyWaiting as $entry) {
            $diff = max(0, (int) Carbon::parse($entry->entered_at)->diffInSeconds(now(), false));
            if ($diff <= 86400 * 2) {
                $waitTimes[] = $diff;
            }
        }

        return count($waitTimes) > 0 ? (int) round(array_sum($waitTimes) / count($waitTimes)) : 0;
    }

    /** Calculate first human response from the human handoff, including time spent waiting in the queue. */
    protected function calculateAvgFirstResponseSeconds(?string $channelId = null): int
    {
        $today = now()->startOfDay();
        $entries = ConversationQueueEntry::query()
            ->where('exit_reason', 'assigned')
            ->whereNotNull('assigned_at')
            ->where('assigned_at', '>=', $today)
            ->whereHas('conversation', fn (Builder $query) => $query->when($channelId, fn ($q) => $q->where('channel_id', $channelId)))
            ->get(['conversation_id', 'entered_at']);

        return $this->averageHumanResponseSeconds($entries);
    }

    /** Average time from the latest customer reply in a turn to the next public human reply. */
    protected function calculateAvgNextResponseSeconds(?string $channelId = null): int
    {
        $today = now()->startOfDay();
        $entries = ConversationQueueEntry::query()
            ->where('exit_reason', 'assigned')
            ->whereNotNull('assigned_at')
            ->where('assigned_at', '>=', $today)
            ->whereHas('conversation', fn (Builder $query) => $query->when($channelId, fn ($q) => $q->where('channel_id', $channelId)))
            ->get(['conversation_id', 'entered_at']);

        if ($entries->isEmpty()) return 0;
        $messages = $this->messagesSinceQueueEntry($entries, includeInbound: true);
        $durations = [];

        foreach ($entries as $entry) {
            $firstHumanSeen = false;
            $latestInboundAt = null;
            foreach ($messages->get($entry->conversation_id, collect()) as $message) {
                $sentAt = Carbon::parse($message->sent_at);
                if ($sentAt->lessThan(Carbon::parse($entry->entered_at))) continue;

                if ($message->sender_type === MessageSenderType::AGENT) {
                    if (! $firstHumanSeen) {
                        $firstHumanSeen = true;
                        continue;
                    }
                    if ($latestInboundAt) {
                        $seconds = max(0, $sentAt->getTimestamp() - $latestInboundAt->getTimestamp());
                        if ($seconds <= 86400) $durations[] = $seconds;
                        $latestInboundAt = null;
                    }
                } elseif ($firstHumanSeen && $message->sender_type === MessageSenderType::CONTACT
                    && $message->direction === MessageDirection::INBOUND) {
                    $latestInboundAt = $sentAt;
                }
            }
        }

        return $durations ? (int) round(array_sum($durations) / count($durations)) : 0;
    }

    /** @param \Illuminate\Support\Collection<int, ConversationQueueEntry> $entries */
    protected function averageHumanResponseSeconds($entries): int
    {
        if ($entries->isEmpty()) return 0;
        $messages = $this->messagesSinceQueueEntry($entries, includeInbound: false);
        $durations = [];
        foreach ($entries as $entry) {
            $start = Carbon::parse($entry->entered_at);
            $first = $messages->get($entry->conversation_id, collect())
                ->first(fn ($message) => Carbon::parse($message->sent_at)->greaterThanOrEqualTo($start));
            if (! $first) continue;
            $seconds = Carbon::parse($first->sent_at)->getTimestamp() - $start->getTimestamp();
            if ($seconds >= 0 && $seconds <= 86400) $durations[] = $seconds;
        }

        return $durations ? (int) round(array_sum($durations) / count($durations)) : 0;
    }

    /** Fetch only public human replies (and optionally customer messages) for queue intervals in one query. */
    protected function messagesSinceQueueEntry($entries, bool $includeInbound): \Illuminate\Support\Collection
    {
        $conversationIds = $entries->pluck('conversation_id')->unique()->values();
        if ($conversationIds->isEmpty()) return collect();

        $query = Message::query()
            ->whereIn('conversation_id', $conversationIds)
            ->where(function (Builder $query) use ($includeInbound) {
                $query->where(fn ($human) => $human
                    ->where('sender_type', MessageSenderType::AGENT->value)
                    ->where('direction', MessageDirection::OUTBOUND->value)
                    ->whereIn('visibility', ['public', 'external']));
                if ($includeInbound) {
                    $query->orWhere(fn ($contact) => $contact
                        ->where('sender_type', MessageSenderType::CONTACT->value)
                        ->where('direction', MessageDirection::INBOUND->value));
                }
            });

        $earliestStart = $entries->min(fn ($entry) => Carbon::parse($entry->entered_at)->getTimestamp());
        if ($earliestStart) $query->where('sent_at', '>=', Carbon::createFromTimestamp($earliestStart));

        return $query->orderBy('sent_at')->get(['conversation_id', 'sender_type', 'direction', 'sent_at'])->groupBy('conversation_id');
    }

    /** Current queue and response objective performance grouped by team for the selected channel. */
    protected function buildTeamSlaSummary(?string $channelId = null): array
    {
        $teams = Team::query()->orderBy('name')->get();
        $today = now()->startOfDay();
        $rows = [];

        foreach ($teams as $team) {
            $queueTarget = (int) ($team->queue_wait_target_seconds ?? config('teams.service_targets.queue_wait_seconds', 120));
            $firstTarget = (int) ($team->first_response_target_seconds ?? config('teams.service_targets.first_response_seconds', 300));
            $entries = ConversationQueueEntry::query()
                ->where('team_id', $team->id)
                ->where('exit_reason', 'assigned')
                ->whereNotNull('assigned_at')
                ->where('assigned_at', '>=', $today)
                ->whereHas('conversation', fn (Builder $query) => $query->when($channelId, fn ($q) => $q->where('channel_id', $channelId)))
                ->get(['id', 'conversation_id', 'entered_at', 'assigned_at']);

            $queueDurations = $entries->map(fn ($entry) => max(0, Carbon::parse($entry->assigned_at)->getTimestamp() - Carbon::parse($entry->entered_at)->getTimestamp()));
            $activeEntries = ConversationQueueEntry::query()
                ->where('team_id', $team->id)
                ->whereHas('conversation', fn (Builder $query) => $query
                    ->where('team_id', $team->id)
                    ->whereIn('status', [ConversationStatus::WAITING_AGENT->value, ConversationStatus::ASSIGNED->value])
                    ->when($channelId, fn ($channelQuery) => $channelQuery->where('channel_id', $channelId)))
                ->orderByDesc('entered_at')
                ->orderByDesc('id')
                ->get(['id', 'conversation_id', 'entered_at', 'assigned_at'])
                ->groupBy('conversation_id')
                ->map(fn ($conversationEntries) => $conversationEntries->first())
                ->values();
            $responseEntries = $entries->concat($activeEntries)->unique('id')->values();
            $firstMessages = $this->messagesSinceQueueEntry($responseEntries, includeInbound: false);
            $firstDurations = [];
            $firstResponseMissed = [];
            foreach ($entries as $entry) {
                $start = Carbon::parse($entry->entered_at);
                $message = $firstMessages->get($entry->conversation_id, collect())
                    ->first(fn ($candidate) => Carbon::parse($candidate->sent_at)->greaterThanOrEqualTo($start));
                if ($message) {
                    $seconds = Carbon::parse($message->sent_at)->getTimestamp() - $start->getTimestamp();
                    if ($seconds >= 0 && $seconds <= 86400) $firstDurations[] = $seconds;
                } elseif ($start->getTimestamp() + $firstTarget < now()->timestamp) {
                    $firstResponseMissed[$entry->id] = true;
                }
            }
            foreach ($activeEntries as $entry) {
                $start = Carbon::parse($entry->entered_at);
                $message = $firstMessages->get($entry->conversation_id, collect())
                    ->first(fn ($candidate) => Carbon::parse($candidate->sent_at)->greaterThanOrEqualTo($start));
                if (! $message && $start->getTimestamp() + $firstTarget < now()->timestamp) {
                    $firstResponseMissed[$entry->id] = true;
                }
            }

            $waiting = ConversationQueueEntry::query()
                ->where('team_id', $team->id)
                ->whereNull('ended_at')
                ->whereHas('conversation', fn (Builder $query) => $query
                    ->where('team_id', $team->id)
                    ->where('status', ConversationStatus::WAITING_AGENT->value)
                    ->whereNull('assigned_user_id')
                    ->when($channelId, fn ($q) => $q->where('channel_id', $channelId)))
                ->get(['entered_at']);
            $overdue = $waiting->filter(fn ($entry) => Carbon::parse($entry->entered_at)->getTimestamp() + $queueTarget < now()->timestamp)->count();

            if ($entries->isEmpty() && $waiting->isEmpty() && $activeEntries->isEmpty()) continue;
            $rows[] = [
                'team_id' => (string) $team->id,
                'team_name' => (string) $team->name,
                'waiting_count' => $waiting->count(),
                'queue_overdue_count' => $overdue,
                'queue_target_seconds' => $queueTarget,
                'queue_sla_met_percent' => $queueDurations->isNotEmpty()
                    ? (int) round($queueDurations->filter(fn ($seconds) => $seconds <= $queueTarget)->count() * 100 / $queueDurations->count())
                    : null,
                'first_response_target_seconds' => $firstTarget,
                'first_response_overdue_count' => count($firstResponseMissed),
                'first_response_sla_met_percent' => (count($firstDurations) + count($firstResponseMissed)) > 0
                    ? (int) round(count(array_filter($firstDurations, fn ($seconds) => $seconds <= $firstTarget)) * 100 / (count($firstDurations) + count($firstResponseMissed)))
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * Calculate average resolution duration for conversations closed today.
     */
    protected function calculateAvgResolutionSeconds(?string $channelId = null): int
    {
        $closedToday = Conversation::query()
            ->when($channelId, fn (Builder $q) => $q->where('channel_id', $channelId))
            ->where('status', ConversationStatus::CLOSED->value)
            ->where('closed_at', '>=', now()->startOfDay())
            ->get(['id', 'closed_at']);

        $durations = [];
        $firstHandoffs = ConversationQueueEntry::query()
            ->whereIn('conversation_id', $closedToday->pluck('id'))
            ->orderBy('entered_at')
            ->get(['conversation_id', 'entered_at'])
            ->groupBy('conversation_id')
            ->map(fn ($entries) => $entries->first());
        foreach ($closedToday as $conv) {
            $handoff = $firstHandoffs->get($conv->id);
            if (! $handoff) continue;
            $start = Carbon::parse($handoff->entered_at);
            $closed = Carbon::parse($conv->closed_at);
            $diff = $closed->getTimestamp() - $start->getTimestamp();
            if ($diff <= 86400 * 7) {
                $durations[] = $diff;
            }
        }

        return count($durations) > 0 ? (int) round(array_sum($durations) / count($durations)) : 0;
    }

    /**
     * Build summary per channel / queue.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildChannelsSummary(): array
    {
        $channels = Channel::query()->orderBy('name')->get();
        $today = now()->startOfDay();

        return $channels->map(function (Channel $ch) use ($today) {
            $ongoing = Conversation::query()
                ->where('channel_id', $ch->id)
                ->where('status', ConversationStatus::ASSIGNED->value)
                ->count();

            $closed = Conversation::query()
                ->where('channel_id', $ch->id)
                ->where('status', ConversationStatus::CLOSED->value)
                ->where('closed_at', '>=', $today)
                ->count();

            $waiting = $this->countUnreadOrWaitingChats($ch->id);

            $avgResp = $this->calculateAvgFirstResponseSeconds($ch->id);

            return [
                'channel_id' => (string) $ch->id,
                'channel_name' => (string) $ch->name,
                'channel_type' => (string) ($ch->type?->value ?? $ch->type),
                'is_active' => (bool) $ch->is_active,
                'ongoing_conversations' => $ongoing,
                'closed_conversations' => $closed,
                'waiting_response_count' => $waiting,
                'avg_response_time_seconds' => $avgResp,
                'avg_response_time_formatted' => $this->formatSeconds($avgResp),
            ];
        })->values()->all();
    }

    /**
     * Format seconds into human readable duration string (e.g. 45s, 2m 15s, 1h 20m).
     */
    public function formatSeconds(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }

        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = intdiv($seconds, 60);
        $remSeconds = $seconds % 60;

        if ($minutes < 60) {
            return $remSeconds > 0 ? "{$minutes}m {$remSeconds}s" : "{$minutes}m";
        }

        $hours = intdiv($minutes, 60);
        $remMinutes = $minutes % 60;

        return $remMinutes > 0 ? "{$hours}h {$remMinutes}m" : "{$hours}h";
    }
}
