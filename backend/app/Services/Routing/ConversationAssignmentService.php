<?php

namespace App\Services\Routing;

use App\Enums\ConversationStatus;
use App\Events\ConversationAssigned;
use App\Events\ConversationStatusChanged;
use App\Events\ConversationUpdated;
use App\Events\MessageCreated;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Presence\AgentAvailabilityService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConversationAssignmentService
{
    public const DEFAULT_MAX_CONCURRENT_CONVERSATIONS = 5;

    public const MAX_CONCURRENT_CONVERSATIONS = 5;

    public const ELIGIBLE_ROLES = ['agent', 'admin', 'supervisor', 'super_admin'];

    /**
     * Retrieves the collection of online, non-saturated agent candidates eligible for assignment.
     * By default, returns all eligible agents with available capacity for a uniform random draw.
     * When strategy is 'least_busy', filters down to the agents with the minimum current active load.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function getEligibleCandidates(Conversation $conversation, string $strategy = 'random'): Collection
    {
        // 1. Get online agents with agent or admin roles
        $onlineAgents = AgentAvailabilityService::getOnlineAgents(self::ELIGIBLE_ROLES, (string) $conversation->workspace_id);

        if ($onlineAgents->isEmpty()) {
            return collect();
        }

        $teamId = (string) ($conversation->team_id ?: app(ConversationQueueService::class)->teamForChannel((string) $conversation->channel_id)->id);
        $onlineAgents->loadMissing(['workspaceMemberships.role', 'channels:id', 'teams:id']);
        $onlineAgents = $onlineAgents->filter(
            fn (User $agent) => $agent->canAccessChannel((string) $conversation->channel_id)
                && $agent->belongsToTeam($teamId)
        )->values();

        // 2. Calculate active assigned conversations per agent
        $agentIds = $onlineAgents->pluck('id')->all();

        $activeCounts = Conversation::query()
            ->where('is_simulation', false)
            ->where('status', ConversationStatus::ASSIGNED)
            ->whereIn('assigned_user_id', $agentIds)
            ->selectRaw('assigned_user_id, count(*) as count')
            ->groupBy('assigned_user_id')
            ->pluck('count', 'assigned_user_id')
            ->all();

        // 3. Capacity filter: discard any agent with <= 0 capacity or >= dynamic role capacity
        $eligibleAgents = $onlineAgents->filter(function (User $agent) use ($activeCounts, $conversation) {
            $maxCapacity = $this->maxCapacityFor($agent, (string) $conversation->workspace_id);

            if ($maxCapacity <= 0) {
                return false;
            }

            $count = (int) ($activeCounts[$agent->id] ?? 0);

            return $count < $maxCapacity;
        })->values();

        if ($eligibleAgents->isEmpty()) {
            return collect();
        }

        // 4. Selection strategy: uniform random across all eligible agents with capacity (default),
        // or least_busy when explicitly specified.
        if ($strategy === 'least_busy') {
            $minCount = $eligibleAgents->min(fn (User $agent) => (int) ($activeCounts[$agent->id] ?? 0));

            return $eligibleAgents->filter(
                fn (User $agent) => ((int) ($activeCounts[$agent->id] ?? 0)) === $minCount
            )->values();
        }

        return $eligibleAgents;
    }

    public function autoAssign(Conversation $conversation, string $strategy = 'random'): ?User
    {
        $conversation = app(ConversationQueueService::class)->enter($conversation) ?? $conversation;
        $candidates = $this->getEligibleCandidates($conversation, $strategy);

        if ($candidates->isEmpty()) {
            $this->markAsWaiting($conversation);
            $waiting = $conversation->fresh(['team', 'channel', 'contact', 'assignedUser']);
            if ($waiting) {
                DB::afterCommit(function () use ($waiting) {
                    broadcast(new ConversationUpdated($waiting));
                    broadcast(new ConversationStatusChanged($waiting));
                });
            }

            return null;
        }

        $candidates = $candidates->shuffle();
        foreach ($candidates as $selectedAgent) {
            $assigned = $this->assignToAgent($conversation, $selectedAgent, 'Auto Assignment');
            if ($assigned) return $assigned;
        }

        return null;
    }

    /**
     * Assigns the oldest waiting conversation when quota is freed (e.g. after a conversation closes).
     */
    public function assignWaitingOnQuotaFreed(?User $freedAgent = null): ?Conversation
    {
        $waitingQuery = Conversation::where('is_simulation', false)
            ->where('status', ConversationStatus::WAITING_AGENT)
            ->whereNull('assigned_user_id')
            ->orderByRaw('COALESCE(queued_at, created_at) asc');

        if ($freedAgent) {
            $freedAgent->loadMissing('teams:id');
            $teamIds = $freedAgent->teams->pluck('id')->all();
            $waitingQuery->whereIn('team_id', $teamIds);
        }

        $waitingConversations = $waitingQuery->limit(100)->get();

        if ($waitingConversations->isEmpty()) {
            return null;
        }

        // If the freed agent is provided, online, and still has capacity, assign directly
        if ($freedAgent && $freedAgent->is_active) {
            foreach ($waitingConversations as $waitingConversation) {
                $isOnline = AgentAvailabilityService::getOnlineAgents(
                    self::ELIGIBLE_ROLES,
                    (string) $waitingConversation->workspace_id
                )->contains('id', $freedAgent->id);
                if (! $isOnline) {
                    continue;
                }

                if (! $freedAgent->canAccessChannel((string) $waitingConversation->channel_id)
                    || ! $freedAgent->belongsToTeam((string) $waitingConversation->team_id)) {
                    continue;
                }
                if ($this->assignToAgent($waitingConversation, $freedAgent, 'Freed-quota auto-assignment')) {
                    return $waitingConversation->fresh();
                }
            }
        }

        foreach ($waitingConversations as $waitingConversation) {
            if ($this->autoAssign($waitingConversation)) {
                return $waitingConversation->fresh();
            }
        }

        return null;
    }

    /**
     * Explicitly marks a conversation as waiting_agent with no assigned user.
     */
    protected function markAsWaiting(Conversation $conversation): void
    {
        app(ConversationQueueService::class)->enter($conversation);
    }

    public function canAcceptMoreConversations(User $agent, ?string $excludeConversationId = null, ?string $workspaceId = null): bool
    {
        $agent->loadMissing('workspaceMemberships.role');
        $maxCapacity = $this->maxCapacityFor($agent, $workspaceId);
        if ($maxCapacity <= 0) return false;

        $activeCount = Conversation::query()
            ->where('is_simulation', false)
            ->where('status', ConversationStatus::ASSIGNED)
            ->where('assigned_user_id', $agent->id)
            ->when($excludeConversationId, fn ($query) => $query->where('id', '!=', $excludeConversationId))
            ->count();

        return $activeCount < $maxCapacity;
    }

    /**
     * Executes the assignment to a specific agent and dispatches real-time broadcast events.
     */
    public function assignToAgent(Conversation $conversation, User $agent, string $reason = 'Auto-assigned'): ?User
    {
        return DB::transaction(function () use ($conversation, $agent, $reason) {
            /** @var Conversation $locked */
            $locked = Conversation::where('id', $conversation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ConversationStatus::WAITING_AGENT || $locked->assigned_user_id !== null) {
                return null;
            }

            $lockedAgent = User::query()->whereKey($agent->id)->lockForUpdate()->first();
            if (! $lockedAgent || ! $lockedAgent->is_active
                || ! $lockedAgent->canAccessChannel((string) $locked->channel_id)
                || ! $lockedAgent->belongsToTeam((string) $locked->team_id)
                || ! $this->canAcceptMoreConversations($lockedAgent, null, (string) $locked->workspace_id)) {
                return null;
            }

            $previousAssignedUserId = $locked->assigned_user_id;

            // Close existing active assignments
            ConversationAssignment::where('conversation_id', $locked->id)
                ->whereNull('unassigned_at')
                ->update(['unassigned_at' => now()]);

            // Create assignment record
            ConversationAssignment::create([
                'conversation_id' => $locked->id,
                'assigned_user_id' => $agent->id,
                'assigned_by_user_id' => null,
                'assigned_at' => now(),
                'reason' => $reason,
            ]);

            $locked->update([
                'assigned_user_id' => $agent->id,
                'status' => ConversationStatus::ASSIGNED,
                'queued_at' => null,
            ]);

            app(ConversationQueueService::class)->leave($locked->id, 'assigned', (int) $agent->id);

            $fresh = $locked->fresh(['assignedUser', 'channel', 'contact', 'assignments']);

            DB::afterCommit(function () use ($fresh, $agent, $reason) {
                broadcast(new ConversationUpdated($fresh));
                broadcast(new ConversationAssigned($fresh, $agent, $reason));
                broadcast(new ConversationStatusChanged($fresh));

                $latestMessage = $fresh->messages()->latest('sent_at')->first();
                if ($latestMessage) {
                    broadcast(new MessageCreated($latestMessage));
                }
            });

            AuditLogger::log(
                action: 'conversation.auto_assign',
                entityType: 'Conversation',
                entityId: (string) $locked->id,
                metadata: [
                    'assigned_user_id' => $agent->id,
                    'previous_assigned_user_id' => $previousAssignedUserId,
                    'reason' => $reason,
                ]
            );

            // Synchronize the passed model instance
            $conversation->assigned_user_id = $agent->id;
            $conversation->status = ConversationStatus::ASSIGNED;

            return $agent;
        });
    }

    private function maxCapacityFor(User $agent, ?string $workspaceId = null): int
    {
        $role = $workspaceId !== null
            ? $agent->workspaceMembership($workspaceId)?->role
            : $agent->workspaceRole();

        if ($role) {
            return (int) ($role->max_concurrent_conversations ?? self::DEFAULT_MAX_CONCURRENT_CONVERSATIONS);
        }

        // Compatibility for direct test fixtures created before workspace_user.
        return app()->environment('testing')
            ? (int) ($agent->roles->max('max_concurrent_conversations') ?? self::DEFAULT_MAX_CONCURRENT_CONVERSATIONS)
            : self::DEFAULT_MAX_CONCURRENT_CONVERSATIONS;
    }
}
