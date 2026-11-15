<?php

namespace App\Services\Analytics;

use App\Enums\ConversationStatus;
use App\Enums\MessageSenderType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SessionAnalyticsService
{
    /**
     * Get aggregated metrics for Users and Sessions.
     *
     * @param  array{from?: string|Carbon|null, to?: string|Carbon|null, channel_id?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function getUsersAndSessions(array $filters = []): array
    {
        $from = isset($filters['from']) && ! empty($filters['from'])
            ? Carbon::parse($filters['from'])->startOfDay()
            : Carbon::now()->subDays(30)->startOfDay();

        $to = isset($filters['to']) && ! empty($filters['to'])
            ? Carbon::parse($filters['to'])->endOfDay()
            : Carbon::now()->endOfDay();

        if ($from->greaterThan($to)) {
            $temp = $from;
            $from = $to->copy()->startOfDay();
            $to = $temp->copy()->endOfDay();
        }

        $channelId = ! empty($filters['channel_id']) ? (string) $filters['channel_id'] : null;
        $tagId = ! empty($filters['tag_id']) ? (string) $filters['tag_id'] : null;

        // Base query for conversations (excluding simulation sessions)
        $conversationsQuery = Conversation::query()
            ->where('is_simulation', false)
            ->whereBetween('started_at', [$from, $to]);

        if ($channelId) {
            $conversationsQuery->where('channel_id', $channelId);
        }

        if ($tagId) {
            $conversationsQuery->whereHas('tags', fn ($tq) => $tq->where('tags.id', $tagId));
        }

        $totalSessions = (clone $conversationsQuery)->count();

        // Unique contacts served
        $uniqueContacts = (clone $conversationsQuery)
            ->whereNotNull('contact_id')
            ->distinct('contact_id')
            ->count('contact_id');

        // Agent sessions: assigned to a user OR has at least one message sent by an agent
        $agentSessions = (clone $conversationsQuery)
            ->where(function (Builder $q) {
                $q->whereNotNull('assigned_user_id')
                    ->orWhereHas('messages', function (Builder $mq) {
                        $mq->where('sender_type', MessageSenderType::AGENT->value);
                    });
            })
            ->count();

        $botOnlySessions = max(0, $totalSessions - $agentSessions);

        // Sessions closed by agents
        $closedByAgents = (clone $conversationsQuery)
            ->where('status', ConversationStatus::CLOSED->value)
            ->where(function (Builder $q) {
                $q->whereNotNull('assigned_user_id')
                    ->orWhereHas('messages', function (Builder $mq) {
                        $mq->where('sender_type', MessageSenderType::AGENT->value);
                    });
            })
            ->count();

        $agentSessionPercentage = $totalSessions > 0
            ? round(($agentSessions / $totalSessions) * 100, 1)
            : 0.0;

        $botOnlyPercentage = $totalSessions > 0
            ? round(($botOnlySessions / $totalSessions) * 100, 1)
            : 0.0;

        // Message counts in the selected range
        $messagesQuery = Message::query()
            ->whereBetween('messages.sent_at', [$from, $to])
            ->whereHas('conversation', function (Builder $cq) use ($channelId, $tagId) {
                $cq->where('is_simulation', false);
                if ($channelId) {
                    $cq->where('channel_id', $channelId);
                }
                if ($tagId) {
                    $cq->whereHas('tags', fn ($tq) => $tq->where('tags.id', $tagId));
                }
            });

        $userMessages = (clone $messagesQuery)
            ->where('sender_type', MessageSenderType::CONTACT->value)
            ->count();

        $botMessages = (clone $messagesQuery)
            ->where('sender_type', MessageSenderType::BOT->value)
            ->count();

        $agentMessages = (clone $messagesQuery)
            ->where('sender_type', MessageSenderType::AGENT->value)
            ->count();

        $totalMessages = $userMessages + $botMessages + $agentMessages;

        // Channel distribution
        $channelDistribution = $this->buildChannelDistribution($conversationsQuery, $totalSessions);

        // Topics distribution
        $topicsDist = $this->buildTopicsDistribution($conversationsQuery, $totalSessions);

        // Daily time series
        $timeSeries = $this->buildDailyTimeSeries($from, $to, $channelId, $tagId);

        return [
            'date_range' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'from_formatted' => $from->toDateString(),
                'to_formatted' => $to->toDateString(),
            ],
            'kpis' => [
                'total_sessions' => $totalSessions,
                'unique_contacts' => $uniqueContacts,
                'agent_sessions' => $agentSessions,
                'bot_only_sessions' => $botOnlySessions,
                'closed_by_agents' => $closedByAgents,
                'agent_session_percentage' => $agentSessionPercentage,
                'bot_only_percentage' => $botOnlyPercentage,
                'message_counts' => [
                    'user_messages' => $userMessages,
                    'bot_messages' => $botMessages,
                    'agent_messages' => $agentMessages,
                    'total_messages' => $totalMessages,
                ],
            ],
            'channel_distribution' => $channelDistribution,
            'topics_distribution' => $topicsDist['topics'],
            'untagged_count' => $topicsDist['untagged_count'],
            'untagged_percentage' => $topicsDist['untagged_percentage'],
            'time_series' => $timeSeries,
        ];
    }

    /**
     * Build channel distribution breakdown.
     *
     * @return array<int, array{channel_id: string, channel_name: string, channel_type: string, count: int, percentage: number}>
     */
    protected function buildChannelDistribution(Builder $conversationsQuery, int $totalSessions): array
    {
        $records = (clone $conversationsQuery)
            ->join('channels', 'conversations.channel_id', '=', 'channels.id')
            ->selectRaw('channels.id as ch_id, channels.name as ch_name, channels.type as ch_type, count(conversations.id) as sessions_count')
            ->groupBy('channels.id', 'channels.name', 'channels.type')
            ->orderByDesc('sessions_count')
            ->get();

        return $records->map(function ($rec) use ($totalSessions) {
            $count = (int) $rec->sessions_count;
            $percentage = $totalSessions > 0
                ? round(($count / $totalSessions) * 100, 1)
                : 0.0;

            return [
                'channel_id' => (string) $rec->ch_id,
                'channel_name' => (string) $rec->ch_name,
                'channel_type' => (string) $rec->ch_type,
                'count' => $count,
                'percentage' => $percentage,
            ];
        })->all();
    }

    /**
     * Build topics / tags distribution breakdown.
     *
     * @return array{topics: array<int, array{tag_id: string, name: string, color: string, count: int, percentage: float}>, untagged_count: int, untagged_percentage: float}
     */
    protected function buildTopicsDistribution(Builder $conversationsQuery, int $totalSessions): array
    {
        $records = (clone $conversationsQuery)
            ->join('conversation_tag', 'conversations.id', '=', 'conversation_tag.conversation_id')
            ->join('tags', 'conversation_tag.tag_id', '=', 'tags.id')
            ->selectRaw('tags.id as tag_id, tags.name as tag_name, tags.color as tag_color, count(distinct conversations.id) as sessions_count')
            ->groupBy('tags.id', 'tags.name', 'tags.color')
            ->orderByDesc('sessions_count')
            ->get();

        $topics = $records->map(function ($rec) use ($totalSessions) {
            $count = (int) $rec->sessions_count;
            $percentage = $totalSessions > 0
                ? round(($count / $totalSessions) * 100, 1)
                : 0.0;

            return [
                'tag_id' => (string) $rec->tag_id,
                'name' => (string) $rec->tag_name,
                'color' => (string) ($rec->tag_color ?: '#3B82F6'),
                'count' => $count,
                'percentage' => $percentage,
            ];
        })->all();

        $untaggedCount = (clone $conversationsQuery)->whereDoesntHave('tags')->count();
        $untaggedPercentage = $totalSessions > 0
            ? round(($untaggedCount / $totalSessions) * 100, 1)
            : 0.0;

        return [
            'topics' => $topics,
            'untagged_count' => $untaggedCount,
            'untagged_percentage' => $untaggedPercentage,
        ];
    }

    /**
     * Build daily time series of sessions and messages.
     *
     * @return array<int, array{date: string, total_sessions: int, agent_sessions: int, bot_sessions: int}>
     */
    protected function buildDailyTimeSeries(Carbon $from, Carbon $to, ?string $channelId = null, ?string $tagId = null): array
    {
        $query = Conversation::query()
            ->where('is_simulation', false)
            ->whereBetween('started_at', [$from, $to]);

        if ($channelId) {
            $query->where('channel_id', $channelId);
        }

        if ($tagId) {
            $query->whereHas('tags', fn ($tq) => $tq->where('tags.id', $tagId));
        }

        $records = $query->selectRaw("DATE(started_at) as session_date, count(*) as total, sum(case when assigned_user_id is not null then 1 else 0 end) as agent_count")
            ->groupBy('session_date')
            ->orderBy('session_date')
            ->get()
            ->keyBy('session_date');

        $period = CarbonPeriod::create($from->copy()->startOfDay(), '1 day', $to->copy()->startOfDay());
        $series = [];

        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            $rec = $records->get($dateStr);
            $total = $rec ? (int) $rec->total : 0;
            $agent = $rec ? (int) $rec->agent_count : 0;

            $series[] = [
                'date' => $dateStr,
                'total_sessions' => $total,
                'agent_sessions' => $agent,
                'bot_sessions' => max(0, $total - $agent),
            ];
        }

        return $series;
    }

    /**
     * Export users and sessions list as CSV.
     *
     * @param  array{from?: string|Carbon|null, to?: string|Carbon|null, channel_id?: string|null}  $filters
     */
    public function exportUsersCsv(array $filters = []): string
    {
        $from = isset($filters['from']) && ! empty($filters['from'])
            ? Carbon::parse($filters['from'])->startOfDay()
            : Carbon::now()->subDays(30)->startOfDay();

        $to = isset($filters['to']) && ! empty($filters['to'])
            ? Carbon::parse($filters['to'])->endOfDay()
            : Carbon::now()->endOfDay();

        $channelId = ! empty($filters['channel_id']) ? (string) $filters['channel_id'] : null;
        $tagId = ! empty($filters['tag_id']) ? (string) $filters['tag_id'] : null;

        $conversations = Conversation::query()
            ->where('is_simulation', false)
            ->whereBetween('started_at', [$from, $to])
            ->when($channelId, fn ($q) => $q->where('channel_id', $channelId))
            ->when($tagId, fn ($q) => $q->whereHas('tags', fn ($tq) => $tq->where('tags.id', $tagId)))
            ->with(['contact', 'channel', 'assignedUser', 'tags'])
            ->orderByDesc('started_at')
            ->get();

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        // BOM for Excel UTF-8 compliance
        fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($handle, [
            'ID Contacto',
            'Nombre Contacto',
            'Teléfono / Identificador',
            'Email',
            'Canal',
            'ID Conversación',
            'Fecha Inicio',
            'Estado',
            'Agente Asignado',
            'Etiquetas',
        ]);

        foreach ($conversations as $conv) {
            $contact = $conv->contact;
            $channel = $conv->channel;
            $agent = $conv->assignedUser;
            $tagsList = $conv->tags->pluck('name')->implode(', ');

            fputcsv($handle, [
                $contact?->id ?? '',
                $contact?->display_name ?? 'Visitante Anónimo',
                $contact?->phone ?: ($contact?->external_identifier ?? 'N/A'),
                $contact?->email ?? '',
                $channel?->name ?? ($channel?->type?->value ?? 'Webchat'),
                $conv->id,
                $conv->started_at?->format('d/m/Y H:i:s') ?? '',
                $conv->status?->value ?? (string) $conv->status,
                $agent?->name ?? 'Sólo Bot',
                $tagsList,
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv !== false ? $csv : '';
    }
}
