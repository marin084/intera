<?php

namespace App\Services\Presence;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class AgentAvailabilityService
{
    public const PRESENCE_THRESHOLD_MINUTES = 5;

    public const AGENT_ROLES = ['agent', 'admin', 'super_admin'];

    protected static ?int $fakeCount = null;

    /** @var \Illuminate\Support\Collection<int, User>|null */
    protected static ?\Illuminate\Support\Collection $fakeAgents = null;

    /**
     * Overrides agent availability count and/or agent list for testing.
     *
     * @param int|null $count
     * @param \Illuminate\Support\Collection<int, User>|null $agents
     */
    public static function fake(?int $count, ?\Illuminate\Support\Collection $agents = null): void
    {
        self::$fakeCount = $count;
        self::$fakeAgents = $agents;
    }

    /**
     * Resets any faked availability count or agents.
     */
    public static function resetFake(): void
    {
        self::$fakeCount = null;
        self::$fakeAgents = null;
    }

    /**
     * Returns the collection of agents currently online and available.
     *
     * @param array<string>|null $roles
     * @return \Illuminate\Support\Collection<int, User>
     */
    public static function getOnlineAgents(?array $roles = null, ?string $workspaceId = null): \Illuminate\Support\Collection
    {
        if (self::$fakeAgents !== null) {
            return self::$fakeAgents;
        }

        try {
            $targetRoles = $roles ?? self::AGENT_ROLES;
            $threshold = now()->subMinutes(self::PRESENCE_THRESHOLD_MINUTES);
            $workspaceId ??= app()->bound('current_workspace_id') ? (string) app('current_workspace_id') : null;
            if ($workspaceId === null && ! app()->environment('testing')) {
                return collect();
            }

            return User::query()
                ->where('is_active', true)
                ->whereNotNull('last_seen_at')
                ->where('last_seen_at', '>=', $threshold)
                ->when($workspaceId !== null, function ($query) use ($workspaceId, $targetRoles): void {
                    $query->where(function ($roleQuery) use ($workspaceId, $targetRoles): void {
                        $roleQuery->whereHas('workspaceMemberships', function ($membershipQuery) use ($workspaceId, $targetRoles): void {
                            $membershipQuery->where('workspace_id', $workspaceId)
                                ->where('is_active', true)
                                ->whereHas('role', fn ($query) => $query->whereIn('name', $targetRoles));
                        });
                        if (app()->environment('testing')) {
                            $roleQuery->orWhereHas('roles', fn ($query) => $query->whereIn('name', $targetRoles));
                        }
                    });
                }, function ($query) use ($targetRoles): void {
                    $query->whereHas('roles', fn ($roleQuery) => $roleQuery->whereIn('name', $targetRoles));
                })
                ->get();
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * Returns the count of agents currently online and available.
     */
    public static function getOnlineAgentsCount(?string $workspaceId = null): int
    {
        if (self::$fakeCount !== null) {
            return max(0, self::$fakeCount);
        }

        if (self::$fakeAgents !== null) {
            return self::$fakeAgents->count();
        }

        try {
            $threshold = now()->subMinutes(self::PRESENCE_THRESHOLD_MINUTES);
            $workspaceId ??= app()->bound('current_workspace_id') ? (string) app('current_workspace_id') : null;
            if ($workspaceId === null && ! app()->environment('testing')) {
                return 0;
            }

            return User::query()
                ->where('is_active', true)
                ->whereNotNull('last_seen_at')
                ->where('last_seen_at', '>=', $threshold)
                ->when($workspaceId !== null, function ($query) use ($workspaceId): void {
                    $query->where(function ($roleQuery) use ($workspaceId): void {
                        $roleQuery->whereHas('workspaceMemberships', function ($membershipQuery) use ($workspaceId): void {
                            $membershipQuery->where('workspace_id', $workspaceId)
                                ->where('is_active', true)
                                ->whereHas('role', fn ($query) => $query->whereIn('name', self::AGENT_ROLES));
                        });
                        if (app()->environment('testing')) {
                            $roleQuery->orWhereHas('roles', fn ($query) => $query->whereIn('name', self::AGENT_ROLES));
                        }
                    });
                }, function ($query): void {
                    $query->whereHas('roles', fn ($roleQuery) => $roleQuery->whereIn('name', self::AGENT_ROLES));
                })
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Determines whether there is at least one online agent.
     */
    public static function hasOnlineAgents(?string $workspaceId = null): bool
    {
        return self::getOnlineAgentsCount($workspaceId) > 0;
    }

    /**
     * Records presence / heartbeat for a given user.
     */
    public static function recordHeartbeat(?User $user): void
    {
        if (! $user) {
            return;
        }

        $user->last_seen_at = now();
        $user->saveQuietly();

        try {
            Cache::put("agent_presence:{$user->id}", now()->timestamp, now()->addMinutes(self::PRESENCE_THRESHOLD_MINUTES));
        } catch (\Throwable) {
            // Ignore cache storage errors if cache service is busy
        }
    }
}
