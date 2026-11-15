<?php

namespace App\Services\Analytics;

use App\Enums\FlowExecutionStatus;
use App\Enums\FlowExecutionStepStatus;
use App\Enums\FlowNodeType;
use App\Models\Flow;
use App\Models\FlowExecution;
use App\Models\FlowExecutionStep;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FlowAnalyticsService
{
    /**
     * Get aggregated flow analytics and menu funnel breakdown.
     *
     * @param  Flow  $flow
     * @param  array{from?: string|Carbon|null, to?: string|Carbon|null, channel_id?: string|null, version_id?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function getFlowAnalytics(Flow $flow, array $filters = []): array
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
        $versionId = ! empty($filters['version_id']) ? (string) $filters['version_id'] : null;

        // Base query for executions
        $executionsQuery = FlowExecution::query()
            ->where('flow_executions.flow_id', $flow->id)
            ->whereBetween('flow_executions.started_at', [$from, $to]);

        if ($versionId) {
            $executionsQuery->where('flow_executions.flow_version_id', $versionId);
        }

        if ($channelId) {
            $executionsQuery->whereHas('conversation', function (Builder $q) use ($channelId) {
                $q->where('channel_id', $channelId);
            });
        }

        $totalExecutions = (clone $executionsQuery)->count();

        // Unique contacts served
        $uniqueContacts = (clone $executionsQuery)
            ->join('conversations', 'flow_executions.conversation_id', '=', 'conversations.id')
            ->whereNotNull('conversations.contact_id')
            ->distinct('conversations.contact_id')
            ->count('conversations.contact_id');

        // Completed executions
        $completedExecutions = (clone $executionsQuery)
            ->where('status', FlowExecutionStatus::COMPLETED->value)
            ->count();

        $completionRate = $totalExecutions > 0
            ? round(($completedExecutions / $totalExecutions) * 100, 1)
            : 0.0;

        // Dropoff calculations: executions that stayed in a menu without selecting an option
        $executionIds = (clone $executionsQuery)->pluck('id');

        $dropoffCount = 0;
        if (! $executionIds->isEmpty()) {
            // Executions that have a menu step in 'waiting' or uncompleted state and did not complete
            $dropoffExecutionsQuery = FlowExecutionStep::query()
                ->whereIn('flow_execution_id', $executionIds)
                ->where('node_type', FlowNodeType::MENU->value)
                ->where('status', FlowExecutionStepStatus::WAITING->value)
                ->whereHas('flowExecution', function (Builder $q) {
                    $q->where('status', '!=', FlowExecutionStatus::COMPLETED->value);
                });

            $dropoffCount = $dropoffExecutionsQuery->distinct('flow_execution_id')->count('flow_execution_id');
        }

        $dropoffRate = $totalExecutions > 0
            ? round(($dropoffCount / $totalExecutions) * 100, 1)
            : 0.0;

        // Menu breakdown
        $menuBreakdown = $this->buildMenuBreakdown($flow, $executionIds, $versionId);

        // Determine top section across all menu options
        $topSection = null;
        $highestClicks = -1;
        foreach ($menuBreakdown as $menu) {
            foreach ($menu['options'] as $option) {
                if ($option['clicks_count'] > $highestClicks && $option['clicks_count'] > 0) {
                    $highestClicks = $option['clicks_count'];
                    $topSection = [
                        'title' => $option['title'],
                        'clicks_count' => $option['clicks_count'],
                        'percentage' => $option['percentage'],
                        'menu_title' => $menu['node_title'],
                    ];
                }
            }
        }

        // Daily time series
        $timeSeries = $this->buildDailyTimeSeries($flow, $from, $to, $channelId, $versionId);

        return [
            'flow_id' => $flow->id,
            'flow_name' => $flow->name,
            'date_range' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'from_formatted' => $from->toDateString(),
                'to_formatted' => $to->toDateString(),
            ],
            'kpis' => [
                'total_executions' => $totalExecutions,
                'unique_contacts' => $uniqueContacts,
                'completed_executions' => $completedExecutions,
                'completion_rate' => $completionRate,
                'dropoff_count' => $dropoffCount,
                'dropoff_rate' => $dropoffRate,
                'top_section' => $topSection,
            ],
            'menu_breakdown' => $menuBreakdown,
            'time_series' => $timeSeries,
        ];
    }

    /**
     * Build the menu options funnel breakdown for the flow.
     *
     * @param  Collection<int, string>  $executionIds
     * @return array<int, array<string, mixed>>
     */
    protected function buildMenuBreakdown(Flow $flow, Collection $executionIds, ?string $versionId = null): array
    {
        /** @var FlowVersion|null $version */
        $version = null;
        if ($versionId) {
            $version = $flow->versions()->with(['nodes', 'edges'])->find($versionId);
        }

        if (! $version) {
            $version = $flow->activeVersion()->with(['nodes', 'edges'])->first()
                ?? $flow->versions()->with(['nodes', 'edges'])->orderByDesc('version_number')->first();
        }

        if (! $version) {
            return [];
        }

        // Find all MENU nodes in the version
        $menuNodes = $version->nodes->where('type', FlowNodeType::MENU);
        if ($menuNodes->isEmpty()) {
            return [];
        }

        $allEdges = $version->edges;
        $allNodes = $version->nodes;

        // Fetch all steps for these executions and menu nodes if executions exist
        $menuSteps = collect();
        $allExecutionSteps = collect();

        if (! $executionIds->isEmpty()) {
            $menuNodeIds = $menuNodes->pluck('id')->all();

            $allExecutionSteps = FlowExecutionStep::query()
                ->whereIn('flow_execution_id', $executionIds)
                ->orderBy('flow_execution_id')
                ->orderBy('entered_at')
                ->get();

            $menuSteps = $allExecutionSteps->whereIn('flow_node_id', $menuNodeIds);
        }

        $breakdown = [];

        foreach ($menuNodes as $node) {
            $config = $node->configuration ?? [];
            $rawOptions = $config['options'] ?? [];
            $nodeEdges = $allEdges->where('source_node_key', $node->node_key);

            // Group execution steps by execution id to track next step transitions
            $stepsForThisNode = $menuSteps->where('flow_node_id', $node->id);

            $parsedOptions = [];
            $totalMenuClicks = 0;

            foreach ($rawOptions as $idx => $opt) {
                $optionId = is_array($opt) ? (string) ($opt['id'] ?? ($idx + 1)) : (string) ($idx + 1);
                $title = is_array($opt)
                    ? (string) ($opt['title'] ?? ($opt['text'] ?? ($opt['value'] ?? "Opción {$optionId}")))
                    : (string) $opt;
                $value = is_array($opt) ? (string) ($opt['value'] ?? ($opt['text'] ?? $title)) : (string) $opt;
                $text = is_array($opt) ? (string) ($opt['text'] ?? $title) : (string) $opt;

                // Determine target node key from option or condition edge
                $targetNodeKey = is_array($opt) ? ($opt['target_node_key'] ?? null) : null;
                $conditionHandle = 'opt_'.$optionId;

                $matchingEdge = $nodeEdges->first(function ($edge) use ($conditionHandle, $targetNodeKey) {
                    if ($targetNodeKey && $edge->target_node_key === $targetNodeKey) {
                        return true;
                    }

                    return $edge->condition_handle === $conditionHandle;
                });

                if ($matchingEdge && empty($targetNodeKey)) {
                    $targetNodeKey = $matchingEdge->target_node_key;
                }

                $targetNode = ! empty($targetNodeKey) ? $allNodes->firstWhere('node_key', $targetNodeKey) : null;
                $targetNodeId = $targetNode?->id ?? $targetNodeKey;

                // Count selections / clicks for this option
                $clicksCount = 0;

                if (! $stepsForThisNode->isEmpty()) {
                    foreach ($stepsForThisNode as $step) {
                        $output = $step->output ?? [];
                        $selectedOptionId = isset($output['selected_option_id']) ? (string) $output['selected_option_id'] : null;
                        $selectedOptionText = isset($output['selected_option_text']) ? (string) $output['selected_option_text'] : null;
                        $selectedOptionValue = isset($output['selected_option_value']) ? (string) $output['selected_option_value'] : null;
                        $outputTargetKey = $output['target_node_key'] ?? null;

                        $matched = false;

                        // 1. Check direct output matching
                        if ($selectedOptionId !== null && $selectedOptionId === $optionId) {
                            $matched = true;
                        } elseif ($selectedOptionValue !== null && ($selectedOptionValue === $value || $selectedOptionValue === $title)) {
                            $matched = true;
                        } elseif ($selectedOptionText !== null && (mb_strtolower($selectedOptionText) === mb_strtolower($title) || mb_strtolower($selectedOptionText) === mb_strtolower($text))) {
                            $matched = true;
                        } elseif ($outputTargetKey !== null && $targetNodeKey !== null && $outputTargetKey === $targetNodeKey) {
                            $matched = true;
                        }

                        // 2. Fallback: check if the subsequent step in the same execution reached the target node
                        if (! $matched && $targetNode && $step->status === FlowExecutionStepStatus::COMPLETED) {
                            $execSteps = $allExecutionSteps->where('flow_execution_id', $step->flow_execution_id)->values();
                            $currentIdx = $execSteps->search(fn ($s) => $s->id === $step->id);
                            if ($currentIdx !== false && isset($execSteps[$currentIdx + 1])) {
                                $nextStep = $execSteps[$currentIdx + 1];
                                if ($nextStep->flow_node_id === $targetNode->id) {
                                    $matched = true;
                                }
                            }
                        }

                        if ($matched) {
                            $clicksCount++;
                        }
                    }
                }

                $totalMenuClicks += $clicksCount;

                $parsedOptions[] = [
                    'option_id' => $optionId,
                    'title' => $title,
                    'target_node_id' => $targetNodeId,
                    'target_node_key' => $targetNodeKey,
                    'clicks_count' => $clicksCount,
                    'percentage' => 0.0,
                ];
            }

            // Calculate percentage relative to total selections in this menu
            foreach ($parsedOptions as &$optRef) {
                $optRef['percentage'] = $totalMenuClicks > 0
                    ? round(($optRef['clicks_count'] / $totalMenuClicks) * 100, 1)
                    : 0.0;
            }
            unset($optRef);

            $breakdown[] = [
                'node_id' => $node->id,
                'node_key' => $node->node_key,
                'node_title' => $node->name ?: ($config['title'] ?? 'Menú Interactivo'),
                'total_selections' => $totalMenuClicks,
                'options' => $parsedOptions,
            ];
        }

        return $breakdown;
    }

    /**
     * Build daily time series of executions.
     *
     * @return array<int, array{date: string, executions: int, completed: int}>
     */
    protected function buildDailyTimeSeries(Flow $flow, Carbon $from, Carbon $to, ?string $channelId = null, ?string $versionId = null): array
    {
        $query = FlowExecution::query()
            ->where('flow_executions.flow_id', $flow->id)
            ->whereBetween('flow_executions.started_at', [$from, $to]);

        if ($versionId) {
            $query->where('flow_executions.flow_version_id', $versionId);
        }

        if ($channelId) {
            $query->whereHas('conversation', function (Builder $q) use ($channelId) {
                $q->where('channel_id', $channelId);
            });
        }

        $records = $query->selectRaw('DATE(started_at) as exec_date, count(*) as total, sum(case when status = ? then 1 else 0 end) as completed', [
            FlowExecutionStatus::COMPLETED->value,
        ])
            ->groupBy('exec_date')
            ->orderBy('exec_date')
            ->get()
            ->keyBy('exec_date');

        $period = CarbonPeriod::create($from->copy()->startOfDay(), '1 day', $to->copy()->startOfDay());
        $series = [];

        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            $rec = $records->get($dateStr);

            $series[] = [
                'date' => $dateStr,
                'executions' => $rec ? (int) $rec->total : 0,
                'completed' => $rec ? (int) $rec->completed : 0,
            ];
        }

        return $series;
    }
}
