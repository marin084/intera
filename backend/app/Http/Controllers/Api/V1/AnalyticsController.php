<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Flow;
use App\Services\Analytics\AgentMetricsService;
use App\Services\Analytics\FlowFunnelAnalyticsService;
use App\Services\Analytics\RealtimeAnalyticsService;
use App\Services\Analytics\SessionAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AnalyticsController extends Controller
{
    public function __construct(
        protected FlowFunnelAnalyticsService $funnelService,
        protected SessionAnalyticsService $sessionService,
        protected RealtimeAnalyticsService $realtimeService,
        protected AgentMetricsService $agentMetricsService
    ) {}

    /**
     * Get flow funnel breakdown and metrics.
     */
    public function funnels(Request $request): JsonResponse
    {
        $this->authorizeFullAnalyticsAccess($request);

        $validated = $request->validate([
            'flow_id' => ['nullable', 'string', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'channel_id' => ['nullable', 'string', 'uuid'],
            'steps' => ['nullable'],
        ]);

        if (! empty($validated['steps']) && is_string($validated['steps'])) {
            $decoded = json_decode($validated['steps'], true);
            if (is_array($decoded)) {
                $validated['steps'] = $decoded;
            }
        }

        $flow = null;
        if (! empty($validated['flow_id'])) {
            $flow = Flow::find($validated['flow_id']);
        }

        if (! $flow) {
            $flow = Flow::where('status', 'published')->first() ?? Flow::first();
        }

        if (! $flow) {
            return response()->json([
                'data' => [
                    'flow_id' => null,
                    'flow_name' => null,
                    'date_range' => [
                        'from' => now()->subDays(30)->startOfDay()->toIso8601String(),
                        'to' => now()->endOfDay()->toIso8601String(),
                    ],
                    'kpis' => [
                        'total_executions' => 0,
                        'unique_contacts' => 0,
                        'completed_executions' => 0,
                        'completion_rate' => 0.0,
                        'dropoff_count' => 0,
                        'dropoff_rate' => 0.0,
                        'top_section' => null,
                    ],
                    'menu_breakdown' => [],
                    'time_series' => [],
                    'available_steps' => [],
                    'default_steps' => [],
                    'sequential_funnel' => [],
                ],
            ]);
        }

        $analytics = $this->funnelService->getFunnel($flow, $validated);

        return response()->json([
            'data' => $analytics,
        ]);
    }

    /**
     * Get exact contacts who selected a specific menu option, or download as CSV.
     */
    public function funnelContacts(Request $request): Response
    {
        $this->authorizeFullAnalyticsAccess($request);

        $validated = $request->validate([
            'flow_id' => ['required', 'string', 'uuid'],
            'node_id' => ['nullable', 'string', 'uuid'],
            'node_key' => ['nullable', 'string'],
            'option_id' => ['nullable', 'string'],
            'option_title' => ['nullable', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'channel_id' => ['nullable', 'string', 'uuid'],
            'export' => ['nullable', 'string', 'in:csv'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        /** @var Flow|null $flow */
        $flow = Flow::find($validated['flow_id']);
        if (! $flow) {
            return response()->json(['message' => 'Flujo no encontrado.'], 404);
        }

        // If CSV export is requested, retrieve all records without pagination
        if ($request->input('export') === 'csv') {
            $allData = $this->funnelService->getOptionContacts($flow, array_merge($validated, [
                'per_page' => 10000,
                'page' => 1,
            ]));

            $csv = $this->funnelService->generateContactsCsv($allData['data']);
            $optName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $validated['option_title'] ?? $validated['option_id'] ?? 'opcion');

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"contactos_{$optName}.csv\"",
            ]);
        }

        $result = $this->funnelService->getOptionContacts($flow, $validated);

        return response()->json($result);
    }

    /**
     * Get exact contacts for a specific sequential funnel step, or download as CSV.
     */
    public function stepContacts(Request $request): Response
    {
        $this->authorizeFullAnalyticsAccess($request);

        $validated = $request->validate([
            'flow_id' => ['required', 'string', 'uuid'],
            'step_number' => ['required', 'integer', 'min:1'],
            'steps' => ['nullable'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'channel_id' => ['nullable', 'string', 'uuid'],
            'export' => ['nullable', 'string', 'in:csv'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        /** @var Flow|null $flow */
        $flow = Flow::find($validated['flow_id']);
        if (! $flow) {
            return response()->json(['message' => 'Flujo no encontrado.'], 404);
        }

        if (! empty($validated['steps']) && is_string($validated['steps'])) {
            $decoded = json_decode($validated['steps'], true);
            if (is_array($decoded)) {
                $validated['steps'] = $decoded;
            }
        }

        if ($request->input('export') === 'csv') {
            $allData = $this->funnelService->getStepContacts($flow, array_merge($validated, [
                'per_page' => 10000,
                'page' => 1,
            ]));

            $csv = $this->funnelService->generateContactsCsv($allData['data']);
            $stepNum = $validated['step_number'];

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"contactos_paso_{$stepNum}.csv\"",
            ]);
        }

        $result = $this->funnelService->getStepContacts($flow, $validated);

        return response()->json($result);
    }

    /**
     * Get users and sessions operational metrics.
     */
    public function usersAndSessions(Request $request): JsonResponse
    {
        $this->authorizeFullAnalyticsAccess($request);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'channel_id' => ['nullable', 'string', 'uuid'],
            'tag_id' => ['nullable', 'string', 'uuid'],
        ]);

        $metrics = $this->sessionService->getUsersAndSessions($validated);

        return response()->json([
            'data' => $metrics,
        ]);
    }

    /**
     * Export users and sessions list as CSV.
     */
    public function exportUsersAndSessions(Request $request): Response
    {
        $this->authorizeFullAnalyticsAccess($request);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'channel_id' => ['nullable', 'string', 'uuid'],
            'tag_id' => ['nullable', 'string', 'uuid'],
        ]);

        $csv = $this->sessionService->exportUsersCsv($validated);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="usuarios_y_sesiones.csv"',
        ]);
    }

    /**
     * Get realtime monitoring KPIs and queue statuses.
     */
    public function realtime(Request $request): JsonResponse
    {
        $this->authorizeFullAnalyticsAccess($request);

        $validated = $request->validate([
            'channel_id' => ['nullable', 'string', 'uuid'],
        ]);

        $metrics = $this->realtimeService->getRealtimeMetrics($validated['channel_id'] ?? null);

        return response()->json([
            'data' => $metrics,
        ]);
    }

    /**
     * Get agent performance and productivity metrics.
     */
    public function agents(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'channel_id' => ['nullable', 'string', 'uuid'],
            'agent_id' => ['nullable', 'integer'],
            'online_only' => ['nullable', 'boolean'],
        ]);

        if ($this->isRestrictedAgent($request)) {
            $validated['agent_id'] = (int) $request->user()->id;
            $validated['online_only'] = false;
        }

        $metrics = $this->agentMetricsService->getAgentMetrics($validated);

        return response()->json([
            'data' => $metrics,
        ]);
    }

    /**
     * Export balance per agent as CSV.
     */
    public function exportAgentsSummary(Request $request): Response
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'channel_id' => ['nullable', 'string', 'uuid'],
            'agent_id' => ['nullable', 'integer'],
            'online_only' => ['nullable', 'boolean'],
        ]);

        if ($this->isRestrictedAgent($request)) {
            $validated['agent_id'] = (int) $request->user()->id;
            $validated['online_only'] = false;
        }

        $csv = $this->agentMetricsService->exportAgentsSummary($validated);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="metricas_agentes_resumen.csv"',
        ]);
    }

    /**
     * Export conversations detail attended by agents as CSV.
     */
    public function exportConversationsDetail(Request $request): Response
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'channel_id' => ['nullable', 'string', 'uuid'],
            'agent_id' => ['nullable', 'integer'],
        ]);

        if ($this->isRestrictedAgent($request)) {
            $validated['agent_id'] = (int) $request->user()->id;
        }

        $csv = $this->agentMetricsService->exportConversationsDetail($validated);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="conversaciones_detalle_agentes.csv"',
        ]);
    }

    /**
     * Check if the authenticated user is restricted to viewing only their own agent metrics.
     */
    protected function isRestrictedAgent(Request $request): bool
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();
        if (! $user) {
            return false;
        }

        if ($user->hasRole(['super_admin', 'admin', 'supervisor']) || $user->hasPermission('conversations.view_all')) {
            return false;
        }

        return true;
    }

    /**
     * Ensure user has access to global analytics sections (funnels, sessions, realtime).
     */
    protected function authorizeFullAnalyticsAccess(Request $request): void
    {
        if ($this->isRestrictedAgent($request)) {
            abort(403, 'No tienes permisos para acceder a esta sección de analítica.');
        }
    }
}
