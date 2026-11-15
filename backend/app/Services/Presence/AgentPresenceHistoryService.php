<?php

namespace App\Services\Presence;

use App\Models\AgentPresenceSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AgentPresenceHistoryService
{
    public const HEARTBEAT_INTERVAL_SECONDS = 60;

    public const RECONNECT_GRACE_SECONDS = 300;

    /**
     * Record one heartbeat for the authenticated browser session.
     *
     * The user row lock serializes concurrent tabs/devices for the same account while
     * keeping each browser session independently trackable.
     */
    public function recordHeartbeat(User $user, string $laravelSessionId): void
    {
        $now = now();
        $sessionKey = hash('sha256', $laravelSessionId);

        DB::transaction(function () use ($user, $sessionKey, $now): void {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $staleSessions = AgentPresenceSession::query()
                ->where('user_id', $user->id)
                ->whereNull('ended_at')
                ->where('last_heartbeat_at', '<', $now->copy()->subSeconds(
                    self::HEARTBEAT_INTERVAL_SECONDS + self::RECONNECT_GRACE_SECONDS
                ))
                ->lockForUpdate()
                ->get();

            foreach ($staleSessions as $staleSession) {
                $staleSession->ended_at = $staleSession->last_heartbeat_at
                    ->copy()
                    ->addSeconds(self::HEARTBEAT_INTERVAL_SECONDS);
                $staleSession->save();
            }

            $active = AgentPresenceSession::query()
                ->where('user_id', $user->id)
                ->where('session_key', $sessionKey)
                ->whereNull('ended_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($active) {
                $latestExpectedHeartbeat = $active->last_heartbeat_at
                    ->copy()
                    ->addSeconds(self::HEARTBEAT_INTERVAL_SECONDS + self::RECONNECT_GRACE_SECONDS);

                if ($now->lessThanOrEqualTo($latestExpectedHeartbeat)) {
                    $active->last_heartbeat_at = $now;
                    $active->save();

                    return;
                }

                $active->ended_at = $active->last_heartbeat_at
                    ->copy()
                    ->addSeconds(self::HEARTBEAT_INTERVAL_SECONDS);
                $active->save();
            }

            AgentPresenceSession::query()->create([
                'user_id' => $user->id,
                'session_key' => $sessionKey,
                'started_at' => $now,
                'last_heartbeat_at' => $now,
            ]);
        }, attempts: 3);
    }

    /** Close only the current browser session during a normal logout. */
    public function endSession(User $user, string $laravelSessionId): void
    {
        $sessionKey = hash('sha256', $laravelSessionId);
        $now = now();

        DB::transaction(function () use ($user, $sessionKey, $now): void {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $active = AgentPresenceSession::query()
                ->where('user_id', $user->id)
                ->where('session_key', $sessionKey)
                ->whereNull('ended_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($active) {
                $active->ended_at = $now->lessThan($active->last_heartbeat_at)
                    ? $active->last_heartbeat_at
                    : $now;
                $active->save();
            }
        }, attempts: 3);
    }

    /**
     * Return connected seconds per user, merging overlapping browser sessions and
     * reconnect gaps up to five minutes.
     *
     * @param array<int, int> $userIds
     * @return array{seconds_by_user: array<int, int>, tracking_started_at: Carbon|null}
     */
    public function connectedSecondsByUser(array $userIds, Carbon $from, Carbon $to): array
    {
        $trackingStartedAt = AgentPresenceSession::query()->min('started_at');
        $secondsByUser = array_fill_keys($userIds, 0);

        if ($userIds === []) {
            return [
                'seconds_by_user' => $secondsByUser,
                'tracking_started_at' => $trackingStartedAt ? Carbon::parse($trackingStartedAt) : null,
            ];
        }

        $sessions = AgentPresenceSession::query()
            ->whereIn('user_id', $userIds)
            ->where('started_at', '<=', $to)
            ->where(function ($query) use ($from): void {
                $query->whereNull('ended_at')->orWhere('ended_at', '>=', $from);
            })
            ->orderBy('user_id')
            ->orderBy('started_at')
            ->get(['user_id', 'started_at', 'last_heartbeat_at', 'ended_at']);

        $now = now();
        $intervalsByUser = [];

        foreach ($sessions as $session) {
            $start = $session->started_at->copy()->max($from);
            $end = $session->ended_at?->copy();

            if (! $end) {
                $heartbeatIsFresh = $session->last_heartbeat_at->greaterThanOrEqualTo(
                    $now->copy()->subMinutes(AgentAvailabilityService::PRESENCE_THRESHOLD_MINUTES)
                );

                $end = $heartbeatIsFresh
                    ? $now->copy()
                    : $session->last_heartbeat_at->copy()->addSeconds(self::HEARTBEAT_INTERVAL_SECONDS);
            }

            $end = $end->min($to);
            if ($end->lessThanOrEqualTo($start)) {
                continue;
            }

            $intervalsByUser[(int) $session->user_id][] = [$start, $end];
        }

        foreach ($intervalsByUser as $userId => $intervals) {
            $currentStart = null;
            $currentEnd = null;

            foreach ($intervals as [$start, $end]) {
                if ($currentStart === null) {
                    $currentStart = $start;
                    $currentEnd = $end;
                    continue;
                }

                $gapSeconds = $currentEnd->diffInSeconds($start, false);
                if ($gapSeconds <= self::RECONNECT_GRACE_SECONDS) {
                    if ($end->greaterThan($currentEnd)) {
                        $currentEnd = $end;
                    }

                    continue;
                }

                $secondsByUser[$userId] += max(0, (int) $currentStart->diffInSeconds($currentEnd, false));
                $currentStart = $start;
                $currentEnd = $end;
            }

            if ($currentStart !== null && $currentEnd !== null) {
                $secondsByUser[$userId] += max(0, (int) $currentStart->diffInSeconds($currentEnd, false));
            }
        }

        return [
            'seconds_by_user' => $secondsByUser,
            'tracking_started_at' => $trackingStartedAt ? Carbon::parse($trackingStartedAt) : null,
        ];
    }
}
