<?php

namespace App\Services\Analytics;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\Message;
use App\Models\User;
use App\Services\Presence\AgentPresenceHistoryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class AgentMetricsService
{
    public function __construct(protected AgentPresenceHistoryService $presenceHistory) {}

    /**
     * Retrieve aggregated metrics per agent and team consolidated KPIs.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getAgentMetrics(array $filters = []): array
    {
        $from = ! empty($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : now()->startOfDay();
        $to = ! empty($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now()->endOfDay();
        $channelId = ! empty($filters['channel_id']) ? (string) $filters['channel_id'] : null;
        $agentId = ! empty($filters['agent_id']) ? (int) $filters['agent_id'] : null;
        $onlineOnly = ! empty($filters['online_only']) && filter_var($filters['online_only'], FILTER_VALIDATE_BOOLEAN);

        // Fetch users who are agents, supervisors or admins
        $workspaceId = app()->bound('current_workspace_id') ? (string) app('current_workspace_id') : null;
        $agentsQuery = User::query()
            ->with($workspaceId !== null ? ['workspaceMemberships.role'] : ['roles'])
            ->where('is_active', true)
            ->where(function (Builder $q) use ($workspaceId) {
                if ($workspaceId !== null) {
                    $q->where(function (Builder $roleQuery) use ($workspaceId): void {
                        $roleQuery->whereHas('workspaceMemberships', fn (Builder $mq) => $mq
                            ->where('workspace_id', $workspaceId)
                            ->where('is_active', true)
                            ->whereHas('role', fn (Builder $rq) => $rq->whereIn('name', ['agent', 'admin', 'super_admin', 'supervisor'])));
                        if (app()->environment('testing')) {
                            $roleQuery->orWhereHas('roles', fn (Builder $rq) => $rq->whereIn('name', ['agent', 'admin', 'super_admin', 'supervisor']));
                        }
                    });
                } else {
                    $q->whereHas('roles', fn (Builder $rq) => $rq->whereIn('name', ['agent', 'admin', 'super_admin', 'supervisor']));
                }
                $q->orWhereHas('assignedConversations');
            })
            ->when($agentId, fn (Builder $q) => $q->where('id', $agentId));

        if ($onlineOnly) {
            $agentsQuery->whereNotNull('last_seen_at')
                ->where('last_seen_at', '>=', now()->subMinutes(5));
        }

        $agents = $agentsQuery->orderBy('name')->get();
        $presenceData = $this->presenceHistory->connectedSecondsByUser(
            $agents->modelKeys(),
            $from,
            $to
        );
        $trackingStartedAt = $presenceData['tracking_started_at'];
        $connectedTimeAvailable = $trackingStartedAt !== null && $to->greaterThanOrEqualTo($trackingStartedAt);
        $connectedSecondsByUser = $presenceData['seconds_by_user'];

        // Total closed across team in range to calculate percentages
        $totalTeamClosed = Conversation::query()
            ->when($channelId, fn (Builder $q) => $q->where('channel_id', $channelId))
            ->where('status', ConversationStatus::CLOSED->value)
            ->whereBetween('closed_at', [$from, $to])
            ->whereNotNull('assigned_user_id')
            ->when($agentId, fn (Builder $q) => $q->where('assigned_user_id', $agentId))
            ->count();

        $agentRows = [];
        $teamAssigned = 0;
        $teamActive = 0;
        $teamClosed = 0;
        $teamFirstResponseTimes = [];
        $teamResponseTimes = [];
        $teamAttentionDurations = [];

        foreach ($agents as $agent) {
            $row = $this->calculateSingleAgentMetrics(
                $agent,
                $from,
                $to,
                $channelId,
                $totalTeamClosed,
                $connectedTimeAvailable ? (int) ($connectedSecondsByUser[$agent->id] ?? 0) : null
            );
            $agentRows[] = $row;

            $teamAssigned += $row['assigned_conversations'];
            $teamActive += $row['active_conversations'];
            $teamClosed += $row['closed_conversations'];

            if ($row['avg_first_response_seconds'] > 0) {
                $teamFirstResponseTimes[] = $row['avg_first_response_seconds'];
            }
            if ($row['avg_response_time_seconds'] > 0) {
                $teamResponseTimes[] = $row['avg_response_time_seconds'];
            }
            if ($row['avg_attention_duration_seconds'] > 0) {
                $teamAttentionDurations[] = $row['avg_attention_duration_seconds'];
            }
        }

        $avgTeamFirstResponse = count($teamFirstResponseTimes) > 0
            ? (int) round(array_sum($teamFirstResponseTimes) / count($teamFirstResponseTimes))
            : 0;

        $avgTeamResponse = count($teamResponseTimes) > 0
            ? (int) round(array_sum($teamResponseTimes) / count($teamResponseTimes))
            : 0;

        $avgTeamAttention = count($teamAttentionDurations) > 0
            ? (int) round(array_sum($teamAttentionDurations) / count($teamAttentionDurations))
            : 0;

        $teamConnectedSeconds = $connectedTimeAvailable
            ? array_sum(array_map(fn (array $row) => $row['connected_seconds'] ?? 0, $agentRows))
            : null;

        return [
            'date_range' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'from_formatted' => $from->toDateString(),
                'to_formatted' => $to->toDateString(),
            ],
            'kpis' => [
                'total_assigned' => $teamAssigned,
                'total_active' => $teamActive,
                'total_closed' => $teamClosed,
                'avg_first_response_seconds' => $avgTeamFirstResponse,
                'avg_first_response_formatted' => $this->formatSeconds($avgTeamFirstResponse),
                'avg_response_time_seconds' => $avgTeamResponse,
                'avg_response_time_formatted' => $this->formatSeconds($avgTeamResponse),
                'avg_attention_duration_seconds' => $avgTeamAttention,
                'avg_attention_duration_formatted' => $this->formatSeconds($avgTeamAttention),
                'total_connected_seconds' => $teamConnectedSeconds,
                'total_connected_formatted' => $teamConnectedSeconds !== null
                    ? $this->formatSeconds($teamConnectedSeconds)
                    : null,
            ],
            'presence_tracking_started_at' => $trackingStartedAt?->toIso8601String(),
            'agents' => $agentRows,
        ];
    }

    /**
     * Calculate individual metrics for one agent.
     *
     * @return array<string, mixed>
     */
    protected function calculateSingleAgentMetrics(
        User $agent,
        Carbon $from,
        Carbon $to,
        ?string $channelId,
        int $totalTeamClosed,
        ?int $connectedSeconds = null
    ): array {
        // Active assigned conversations
        $activeCount = Conversation::query()
            ->where('assigned_user_id', $agent->id)
            ->where('status', ConversationStatus::ASSIGNED->value)
            ->when($channelId, fn (Builder $q) => $q->where('channel_id', $channelId))
            ->count();

        // Max concurrency capacity
        $roles = $this->workspaceRolesFor($agent);
        $maxCapacity = $roles->max('max_concurrent_conversations') ?? 5;

        // Presence Status
        $presenceStatus = 'offline';
        if ($agent->last_seen_at && $agent->last_seen_at >= now()->subMinutes(5)) {
            $presenceStatus = ($maxCapacity > 0 && $activeCount >= $maxCapacity) ? 'busy' : 'online';
        }

        // Assigned conversations in date range (from assignments or direct conversation creation)
        $assignedCount = ConversationAssignment::query()
            ->join('conversations', 'conversation_assignments.conversation_id', '=', 'conversations.id')
            ->where('conversation_assignments.assigned_user_id', $agent->id)
            ->whereBetween('conversation_assignments.assigned_at', [$from, $to])
            ->when($channelId, fn (Builder $q) => $q->where('conversations.channel_id', $channelId))
            ->distinct('conversation_assignments.conversation_id')
            ->count();

        if ($assignedCount === 0) {
            $assignedCount = Conversation::query()
                ->where('assigned_user_id', $agent->id)
                ->whereBetween('created_at', [$from, $to])
                ->when($channelId, fn (Builder $q) => $q->where('channel_id', $channelId))
                ->count();
        }

        // Closed conversations in date range
        $closedCount = Conversation::query()
            ->where('assigned_user_id', $agent->id)
            ->where('status', ConversationStatus::CLOSED->value)
            ->whereBetween('closed_at', [$from, $to])
            ->when($channelId, fn (Builder $q) => $q->where('channel_id', $channelId))
            ->count();

        $closedPercentage = $totalTeamClosed > 0
            ? round(($closedCount / $totalTeamClosed) * 100, 1)
            : ($closedCount > 0 ? 100.0 : 0.0);

        // Waiting response count: assigned to this agent, currently active, last message inbound
        $waitingResponseCount = Conversation::query()
            ->where('assigned_user_id', $agent->id)
            ->where('status', ConversationStatus::ASSIGNED->value)
            ->when($channelId, fn (Builder $q) => $q->where('channel_id', $channelId))
            ->whereExists(function ($sub) {
                $sub->selectRaw(1)
                    ->from('messages')
                    ->whereColumn('messages.conversation_id', 'conversations.id')
                    ->where('messages.direction', MessageDirection::INBOUND->value)
                    ->where('messages.sent_at', '>=', function ($inner) {
                        $inner->selectRaw("COALESCE(MAX(m2.sent_at), '1970-01-01')")
                            ->from('messages as m2')
                            ->whereColumn('m2.conversation_id', 'conversations.id')
                            ->where('m2.sender_type', MessageSenderType::AGENT->value);
                    });
            })
            ->count();

        // First response time calculation
        $assignments = ConversationAssignment::query()
            ->join('conversations', 'conversation_assignments.conversation_id', '=', 'conversations.id')
            ->where('conversation_assignments.assigned_user_id', $agent->id)
            ->whereBetween('conversation_assignments.assigned_at', [$from, $to])
            ->when($channelId, fn (Builder $q) => $q->where('conversations.channel_id', $channelId))
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
                $diff = abs((int) Carbon::parse($firstAgentMsg->sent_at)->diffInSeconds(Carbon::parse($assign->assigned_at)));
                if ($diff <= 86400) {
                    $firstResponseTimes[] = $diff;
                }
            }
        }

        $avgFirstResponseSeconds = count($firstResponseTimes) > 0
            ? (int) round(array_sum($firstResponseTimes) / count($firstResponseTimes))
            : 0;

        // General response time between contact message and subsequent agent reply
        $avgResponseSeconds = $this->calculateAgentAvgResponseTime($agent->id, $from, $to, $channelId);

        // Attention duration for closed chats
        $closedChats = Conversation::query()
            ->where('assigned_user_id', $agent->id)
            ->where('status', ConversationStatus::CLOSED->value)
            ->whereBetween('closed_at', [$from, $to])
            ->when($channelId, fn (Builder $q) => $q->where('channel_id', $channelId))
            ->get(['started_at', 'created_at', 'closed_at']);

        $durations = [];
        foreach ($closedChats as $chat) {
            $start = Carbon::parse($chat->started_at ?? $chat->created_at);
            $closed = Carbon::parse($chat->closed_at);
            $diff = abs((int) $closed->diffInSeconds($start));
            if ($diff <= 86400 * 7) {
                $durations[] = $diff;
            }
        }

        $avgAttentionSeconds = count($durations) > 0
            ? (int) round(array_sum($durations) / count($durations))
            : 0;

        // Channels handled by this agent
        $channels = Conversation::query()
            ->join('channels', 'conversations.channel_id', '=', 'channels.id')
            ->where('conversations.assigned_user_id', $agent->id)
            ->whereBetween('conversations.created_at', [$from, $to])
            ->select('channels.id', 'channels.name', 'channels.type')
            ->distinct()
            ->get()
            ->map(fn ($ch) => [
                'id' => (string) $ch->id,
                'name' => (string) $ch->name,
                'type' => (string) ($ch->type?->value ?? $ch->type),
            ])
            ->values()
            ->all();

        return [
            'agent_id' => $agent->id,
            'name' => $agent->name,
            'email' => $agent->email,
            'avatar_url' => null,
            'roles' => $roles->pluck('name')->all(),
            'presence_status' => $presenceStatus,
            'last_seen_at' => $agent->last_seen_at?->toIso8601String(),
            'last_seen_formatted' => $agent->last_seen_at ? $agent->last_seen_at->diffForHumans() : 'Nunca',
            'connected_seconds' => $connectedSeconds,
            'connected_formatted' => $connectedSeconds !== null ? $this->formatSeconds($connectedSeconds) : null,
            'channels' => $channels,
            'assigned_conversations' => $assignedCount,
            'active_conversations' => $activeCount,
            'closed_conversations' => $closedCount,
            'closed_percentage' => $closedPercentage,
            'waiting_response_count' => $waitingResponseCount,
            'avg_first_response_seconds' => $avgFirstResponseSeconds,
            'avg_first_response_formatted' => $this->formatSeconds($avgFirstResponseSeconds),
            'avg_response_time_seconds' => $avgResponseSeconds,
            'avg_response_time_formatted' => $this->formatSeconds($avgResponseSeconds),
            'avg_attention_duration_seconds' => $avgAttentionSeconds,
            'avg_attention_duration_formatted' => $this->formatSeconds($avgAttentionSeconds),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, \App\Models\Role> */
    private function workspaceRolesFor(User $agent): \Illuminate\Support\Collection
    {
        $workspaceId = app()->bound('current_workspace_id') ? (string) app('current_workspace_id') : null;
        if ($workspaceId !== null) {
            $role = $agent->workspaceMembership($workspaceId)?->role;
            if ($role) {
                return collect([$role]);
            }

            if (app()->environment('testing')) {
                return $agent->roles;
            }

            return collect();
        }

        return app()->environment('testing') ? $agent->roles : collect();
    }

    /**
     * Calculate average response time for all agent replies to contact messages.
     */
    protected function calculateAgentAvgResponseTime(int $agentId, Carbon $from, Carbon $to, ?string $channelId): int
    {
        $agentMessages = Message::query()
            ->where('sender_type', MessageSenderType::AGENT->value)
            ->where('sender_id', (string) $agentId)
            ->whereBetween('sent_at', [$from, $to])
            ->whereHas('conversation', function (Builder $q) use ($channelId) {
                if ($channelId) {
                    $q->where('channel_id', $channelId);
                }
            })
            ->orderBy('sent_at')
            ->limit(100)
            ->get(['conversation_id', 'sent_at']);

        $diffs = [];
        foreach ($agentMessages as $msg) {
            $priorContactMsg = Message::query()
                ->where('conversation_id', $msg->conversation_id)
                ->where('direction', MessageDirection::INBOUND->value)
                ->where('sent_at', '<=', $msg->sent_at)
                ->orderByDesc('sent_at')
                ->first(['sent_at']);

            if ($priorContactMsg) {
                $diff = abs((int) Carbon::parse($msg->sent_at)->diffInSeconds(Carbon::parse($priorContactMsg->sent_at)));
                if ($diff <= 86400) {
                    $diffs[] = $diff;
                }
            }
        }

        return count($diffs) > 0 ? (int) round(array_sum($diffs) / count($diffs)) : 0;
    }

    /**
     * Export balance per agent as CSV.
     *
     * @param array<string, mixed> $filters
     */
    public function exportAgentsSummary(array $filters = []): string
    {
        $metrics = $this->getAgentMetrics($filters);
        $agents = $metrics['agents'] ?? [];

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        // BOM for Excel UTF-8 compliance
        fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($handle, [
            'ID Agente',
            'Nombre del Agente',
            'Email',
            'Estado',
            'Última Conexión',
            'Chats Asignados',
            'Chats en Curso',
            'Chats Cerrados',
            '% del Total Cerrado',
            'Esperando Respuesta',
            'T. Medio 1ª Resp. (seg)',
            'T. Medio Resp. (seg)',
            'Duración Media Atención',
            'Tiempo Conectado',
        ]);

        foreach ($agents as $agent) {
            fputcsv($handle, [
                $agent['agent_id'],
                $agent['name'],
                $agent['email'],
                strtoupper($agent['presence_status']),
                $agent['last_seen_formatted'] ?? '',
                $agent['assigned_conversations'],
                $agent['active_conversations'],
                $agent['closed_conversations'],
                $agent['closed_percentage'].'%',
                $agent['waiting_response_count'],
                $agent['avg_first_response_seconds'],
                $agent['avg_response_time_seconds'],
                $agent['avg_attention_duration_formatted'],
                $agent['connected_formatted'] ?? 'Sin datos',
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv ?: '';
    }

    /**
     * Export detailed conversations attended as CSV.
     *
     * @param array<string, mixed> $filters
     */
    public function exportConversationsDetail(array $filters = []): string
    {
        $from = ! empty($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : now()->startOfDay();
        $to = ! empty($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now()->endOfDay();
        $channelId = ! empty($filters['channel_id']) ? (string) $filters['channel_id'] : null;
        $agentId = ! empty($filters['agent_id']) ? (int) $filters['agent_id'] : null;

        $query = Conversation::query()
            ->with(['contact', 'channel', 'assignedUser'])
            ->whereNotNull('assigned_user_id')
            ->whereBetween('created_at', [$from, $to])
            ->when($channelId, fn (Builder $q) => $q->where('channel_id', $channelId))
            ->when($agentId, fn (Builder $q) => $q->where('assigned_user_id', $agentId))
            ->orderByDesc('created_at');

        $conversations = $query->limit(5000)->get();

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        // BOM for Excel UTF-8 compliance
        fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($handle, [
            'ID Conversación',
            'Contacto',
            'Teléfono / ID',
            'Canal',
            'Agente Asignado',
            'Fecha Inicio',
            'Fecha Cierre',
            'Estado',
            'Duración Total',
            '1ª Respuesta',
        ]);

        foreach ($conversations as $conv) {
            $start = Carbon::parse($conv->started_at ?? $conv->created_at);
            $closed = $conv->closed_at ? Carbon::parse($conv->closed_at) : null;
            $durationSeconds = $closed ? abs((int) $closed->diffInSeconds($start)) : abs((int) now()->diffInSeconds($start));

            // First response time
            $assignment = ConversationAssignment::query()
                ->where('conversation_id', $conv->id)
                ->where('assigned_user_id', $conv->assigned_user_id)
                ->orderBy('assigned_at')
                ->first(['assigned_at']);

            $firstResponseFormatted = 'N/A';
            if ($assignment) {
                $firstAgentMsg = Message::query()
                    ->where('conversation_id', $conv->id)
                    ->where('sender_type', MessageSenderType::AGENT->value)
                    ->where('sent_at', '>=', $assignment->assigned_at)
                    ->orderBy('sent_at')
                    ->first(['sent_at']);

                if ($firstAgentMsg) {
                    $diff = abs((int) Carbon::parse($firstAgentMsg->sent_at)->diffInSeconds(Carbon::parse($assignment->assigned_at)));
                    $firstResponseFormatted = $this->formatSeconds($diff);
                }
            }

            fputcsv($handle, [
                $conv->id,
                $conv->contact?->display_name ?? 'Anónimo',
                $conv->contact?->phone ?: ($conv->contact?->external_identifier ?? 'N/A'),
                $conv->channel?->name ?? 'Canal Web',
                $conv->assignedUser?->name ?? 'Sin Asignar',
                $start->format('d/m/Y H:i:s'),
                $closed ? $closed->format('d/m/Y H:i:s') : 'En curso',
                $conv->status?->value ?? $conv->status,
                $this->formatSeconds($durationSeconds),
                $firstResponseFormatted,
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv ?: '';
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
