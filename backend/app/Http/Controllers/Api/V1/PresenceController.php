<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Presence\AgentPresenceHistoryService;
use App\Services\Presence\AgentAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PresenceController extends Controller
{
    /**
     * Heartbeat endpoint for authenticated agents and operators.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            AgentAvailabilityService::recordHeartbeat($user);

            if ($request->hasSession()) {
                app(AgentPresenceHistoryService::class)->recordHeartbeat($user, $request->session()->getId());
            }
        }

        return response()->json([
            'status' => 'ok',
            'online_agents' => AgentAvailabilityService::getOnlineAgentsCount(),
            'has_agents' => AgentAvailabilityService::hasOnlineAgents(),
            'last_seen_at' => $user?->last_seen_at?->toIso8601String(),
        ]);
    }
}
