<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Flow;
use App\Services\Analytics\FlowAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlowAnalyticsController extends Controller
{
    public function __construct(
        protected FlowAnalyticsService $analyticsService
    ) {}

    /**
     * Get analytics and menu funnel breakdown for a given flow.
     */
    public function show(Request $request, Flow $flow): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'channel_id' => ['nullable', 'string', 'uuid'],
            'version_id' => ['nullable', 'string', 'uuid'],
        ]);

        $analytics = $this->analyticsService->getFlowAnalytics($flow, $validated);

        return response()->json([
            'data' => $analytics,
        ]);
    }
}
