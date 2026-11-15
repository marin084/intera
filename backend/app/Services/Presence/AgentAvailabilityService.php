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
    public static function getOnlineAgents(?array $roles = null): \Illuminate\Support\Collection
    {
        if (self::$fakeAgents !== null) {
            return self::$fakeAgents;
        }

        try {
            $targetRoles = $roles ?? self::AGENT_ROLES;
            $threshold = now()->subMinutes(self::PRESENCE_THRESHOLD_MINUTES);

            return User::query()
                ->where('is_active', true)
                ->whereNotNull('last_seen_at')
                ->where('last_seen_at', '>=', $threshold)
                ->whereHas('roles', function ($query) use ($targetRoles) {
                    $query->whereIn('name', $targetRoles);
                })
                ->get();
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * Returns the count of agents currently online and available.
     */
    public static function getOnlineAgentsCount(): int
    {
        if (self::$fakeCount !== null) {
            return max(0, self::$fakeCount);
        }

        if (self::$fakeAgents !== null) {
            return self::$fakeAgents->count();
        }

        try {
            $threshold = now()->subMinutes(self::PRESENCE_THRESHOLD_MINUTES);

            return User::query()
                ->where('is_active', true)
                ->whereNotNull('last_seen_at')
                ->where('last_seen_at', '>=', $threshold)
                ->whereHas('roles', function ($query) {
                    $query->whereIn('name', self::AGENT_ROLES);
                })
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Determines whether there is at least one online agent.
     */
    public static function hasOnlineAgents(): bool
    {
        return self::getOnlineAgentsCount() > 0;
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
