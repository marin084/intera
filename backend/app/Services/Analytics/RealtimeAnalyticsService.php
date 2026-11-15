<?php

namespace App\Services\Analytics;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\Message;
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
                'avg_resolution_seconds' => $avgResolutionSeconds,
                'avg_resolution_formatted' => $this->formatSeconds($avgResolutionSeconds),
            ],
            'agent_conversations' => [
                'ongoing' => $ongoingConversations,
                'closed_today' => $closedTodayConversations,
                'active_agents_count' => $onlineAgentsCount,
            ],
            'channels_summary' => $channelsSummary,
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
     * Calculate average queue wait time in seconds (for assignments made today or conversations waiting in queue).
     */
    protected function calculateAvgQueueWaitSeconds(?string $channelId = null): int
    {
        $today = now()->startOfDay();
        $waitTimes = [];

        // Assignments made today
        $assignments = ConversationAssignment::query()
            ->join('conversations', 'conversation_assignments.conversation_id', '=', 'conversations.id')
            ->when($channelId, fn (Builder $q) => $q->where('conversations.channel_id', $channelId))
            ->where('conversation_assignments.assigned_at', '>=', $today)
            ->selectRaw('conversation_assignments.assigned_at, conversations.started_at, conversations.created_at')
            ->get();

        foreach ($assignments as $row) {
            $start = Carbon::parse($row->started_at ?? $row->created_at);
            $assigned = Carbon::parse($row->assigned_at);
            $diff = abs((int) $assigned->diffInSeconds($start));
            if ($diff <= 86400 * 2) {
                $waitTimes[] = $diff;
            }
        }

        // Active waiting_agent conversations
        $currentlyWaiting = Conversation::query()
            ->when($channelId, fn (Builder $q) => $q->where('channel_id', $channelId))
            ->where('status', ConversationStatus::WAITING_AGENT->value)
            ->get(['started_at', 'created_at']);

        foreach ($currentlyWaiting as $row) {
            $start = Carbon::parse($row->started_at ?? $row->created_at);
            $diff = abs((int) now()->diffInSeconds($start));
            if ($diff <= 86400 * 2) {
                $waitTimes[] = $diff;
            }
        }

        return count($waitTimes) > 0 ? (int) round(array_sum($waitTimes) / count($waitTimes)) : 0;
    }

    /**
     * Calculate average time from assignment to first agent message.
     */
    protected function calculateAvgFirstResponseSeconds(?string $channelId = null): int
    {
        $today = now()->startOfDay();

        $assignments = ConversationAssignment::query()
            ->join('conversations', 'conversation_assignments.conversation_id', '=', 'conversations.id')
            ->when($channelId, fn (Builder $q) => $q->where('conversations.channel_id', $channelId))
            ->where('conversation_assignments.assigned_at', '>=', $today)
            ->select('conversation_assignments.conversation_id', 'conversation_assignments.assigned_at')
            ->get();

        $firstResponseTimes = [];
        foreach ($assignments as $assign) {
            $firstAgentMsg = Message::query()
                ->where('conversation_id', $assign->conversation_id)
                ->where('sender_type', MessageSenderType::AGENT->value)
                ->where('sent_at', '>=', $assign->assigned_at)
                ->orderBy('sent_at')
                ->first(['sent_at']);

            if ($firstAgentMsg) {
                $assignTime = Carbon::parse($assign->assigned_at);
                $msgTime = Carbon::parse($firstAgentMsg->sent_at);
                $diff = abs((int) $msgTime->diffInSeconds($assignTime));
                if ($diff <= 86400) {
                    $firstResponseTimes[] = $diff;
                }
            }
        }

        return count($firstResponseTimes) > 0 ? (int) round(array_sum($firstResponseTimes) / count($firstResponseTimes)) : 0;
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
            ->get(['started_at', 'created_at', 'closed_at']);

        $durations = [];
        foreach ($closedToday as $conv) {
            $start = Carbon::parse($conv->started_at ?? $conv->created_at);
            $closed = Carbon::parse($conv->closed_at);
            $diff = abs((int) $closed->diffInSeconds($start));
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
