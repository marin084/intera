<?php

namespace App\Services\Analytics;

use App\Enums\FlowExecutionStepStatus;
use App\Enums\FlowNodeType;
use App\Models\Flow;
use App\Models\FlowExecution;
use App\Models\FlowExecutionStep;
use App\Models\FlowNode;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FlowFunnelAnalyticsService
{
    public function __construct(
        protected FlowAnalyticsService $flowAnalyticsService
    ) {}

    /**
     * Get available flow nodes and menu options to be used as funnel stages.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAvailableSteps(Flow $flow): array
    {
        $version = $flow->activeVersion ?? $flow->versions()->orderByDesc('version_number')->first();
        if (! $version) {
            return [];
        }

        $nodes = $version->nodes()->orderBy('created_at')->get();
        $steps = [];

        foreach ($nodes as $node) {
            $nodeType = $node->type instanceof FlowNodeType ? $node->type->value : (string) $node->type;
            $nodeLabel = $node->name ?: ($node->node_key ?: "Nodo {$nodeType}");

            $steps[] = [
                'id' => "node_{$node->id}",
                'node_id' => (string) $node->id,
                'node_key' => (string) $node->node_key,
                'node_type' => $nodeType,
                'label' => $nodeLabel,
                'option_id' => null,
                'option_title' => null,
                'target_node_key' => null,
            ];

            if ($nodeType === FlowNodeType::MENU->value && ! empty($node->configuration['options'])) {
                foreach ($node->configuration['options'] as $idx => $opt) {
                    $optId = is_array($opt) ? (string) ($opt['id'] ?? ($idx + 1)) : (string) ($idx + 1);
                    $optTitle = is_array($opt)
                        ? (string) ($opt['title'] ?? ($opt['text'] ?? ($opt['value'] ?? "Opción {$optId}")))
                        : (string) $opt;
                    $targetKey = is_array($opt) ? ($opt['target_node_key'] ?? null) : null;

                    $steps[] = [
                        'id' => "opt_{$node->id}_{$optId}",
                        'node_id' => (string) $node->id,
                        'node_key' => (string) $node->node_key,
                        'node_type' => 'menu_option',
                        'label' => "{$nodeLabel} › {$optTitle}",
                        'option_id' => $optId,
                        'option_title' => $optTitle,
                        'target_node_key' => $targetKey,
                    ];
                }
            }
        }

        return $steps;
    }

    /**
     * Generate default sequential stages for a flow.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDefaultSteps(Flow $flow): array
    {
        $version = $flow->activeVersion ?? $flow->versions()->orderByDesc('version_number')->first();
        if (! $version) {
            return [];
        }

        $nodes = $version->nodes()->get();
        $startNode = $nodes->firstWhere('type', FlowNodeType::START) ?? $nodes->first();
        if (! $startNode) {
            return [];
        }

        $defaultSteps = [];
        $defaultSteps[] = [
            'id' => "default_node_{$startNode->id}",
            'node_id' => (string) $startNode->id,
            'node_key' => (string) $startNode->node_key,
            'node_type' => $startNode->type instanceof FlowNodeType ? $startNode->type->value : (string) $startNode->type,
            'label' => $startNode->name ?: ($startNode->node_key ?: 'Inicio'),
            'option_id' => null,
            'option_title' => null,
            'is_active' => true,
        ];

        $menuNode = $nodes->firstWhere('type', FlowNodeType::MENU);
        if ($menuNode) {
            $menuLabel = $menuNode->name ?: 'Menú Principal';
            $options = $menuNode->configuration['options'] ?? [];
            if (! empty($options)) {
                $firstOpt = $options[0];
                $optId = is_array($firstOpt) ? (string) ($firstOpt['id'] ?? '1') : '1';
                $optTitle = is_array($firstOpt) ? (string) ($firstOpt['title'] ?? 'Opción 1') : (string) $firstOpt;
                $targetKey = is_array($firstOpt) ? ($firstOpt['target_node_key'] ?? null) : null;

                $defaultSteps[] = [
                    'id' => "default_opt_{$menuNode->id}_{$optId}",
                    'node_id' => (string) $menuNode->id,
                    'node_key' => (string) $menuNode->node_key,
                    'node_type' => 'menu_option',
                    'label' => "{$menuLabel} › {$optTitle}",
                    'option_id' => $optId,
                    'option_title' => $optTitle,
                    'target_node_key' => $targetKey,
                    'is_active' => true,
                ];

                if ($targetKey) {
                    $targetNode = $nodes->firstWhere('node_key', $targetKey);
                    if ($targetNode) {
                        $defaultSteps[] = [
                            'id' => "default_node_{$targetNode->id}",
                            'node_id' => (string) $targetNode->id,
                            'node_key' => (string) $targetNode->node_key,
                            'node_type' => $targetNode->type instanceof FlowNodeType ? $targetNode->type->value : (string) $targetNode->type,
                            'label' => $targetNode->name ?: $targetNode->node_key,
                            'option_id' => null,
                            'option_title' => null,
                            'is_active' => true,
                        ];
                    }
                }
            } else {
                $defaultSteps[] = [
                    'id' => "default_node_{$menuNode->id}",
                    'node_id' => (string) $menuNode->id,
                    'node_key' => (string) $menuNode->node_key,
                    'node_type' => FlowNodeType::MENU->value,
                    'label' => $menuLabel,
                    'option_id' => null,
                    'option_title' => null,
                    'is_active' => true,
                ];
            }
        } else {
            foreach ($nodes as $node) {
                if ($node->id === $startNode->id) {
                    continue;
                }
                $defaultSteps[] = [
                    'id' => "default_node_{$node->id}",
                    'node_id' => (string) $node->id,
                    'node_key' => (string) $node->node_key,
                    'node_type' => $node->type instanceof FlowNodeType ? $node->type->value : (string) $node->type,
                    'label' => $node->name ?: $node->node_key,
                    'option_id' => null,
                    'option_title' => null,
                    'is_active' => true,
                ];
                if (count($defaultSteps) >= 3) {
                    break;
                }
            }
        }

        return $defaultSteps;
    }

    /**
     * Calculate multi-stage sequential conversion funnel.
     *
     * @param  array<int, array<string, mixed>>  $stepsInput
     * @param  array{from?: string|Carbon|null, to?: string|Carbon|null, channel_id?: string|null}  $filters
     * @return array{steps: array<int, array<string, mixed>>}
     */
    public function calculateSequentialFunnel(Flow $flow, array $stepsInput = [], array $filters = []): array
    {
        [$from, $to] = $this->parseDateRange($filters);
        $channelId = ! empty($filters['channel_id']) ? (string) $filters['channel_id'] : null;

        // Filter active steps
        $activeSteps = array_values(array_filter($stepsInput, function ($step) {
            $isActive = $step['is_active'] ?? ($step['enabled'] ?? true);

            return $isActive !== false && $isActive !== 'false' && $isActive !== 0 && $isActive !== '0';
        }));

        if (empty($activeSteps)) {
            return ['steps' => []];
        }

        // Build options lookup map
        $optionsMap = $this->buildOptionsMap($flow);

        // Fetch executions in date range
        $executionsQuery = FlowExecution::query()
            ->where('flow_executions.flow_id', $flow->id)
            ->whereBetween('flow_executions.started_at', [$from, $to]);

        if ($channelId) {
            $executionsQuery->whereHas('conversation', function (Builder $q) use ($channelId) {
                $q->where('channel_id', $channelId);
            });
        }

        $executions = $executionsQuery->with([
            'conversation.contact',
            'conversation.channel',
            'steps.flowNode',
        ])->get();

        if ($executions->isEmpty()) {
            return ['steps' => $this->buildEmptySequentialSteps($activeSteps)];
        }

        // Flatten and sort steps chronologically per conversation
        $allSteps = $executions->flatMap->steps;
        $stepsByConversation = [];

        foreach ($executions as $exec) {
            $convId = (string) $exec->conversation_id;
            if (! isset($stepsByConversation[$convId])) {
                $stepsByConversation[$convId] = [
                    'conversation' => $exec->conversation,
                    'steps' => collect(),
                ];
            }
            foreach ($exec->steps as $s) {
                $stepsByConversation[$convId]['steps']->push($s);
            }
        }

        foreach ($stepsByConversation as $convId => &$data) {
            $data['steps'] = $data['steps']->sortBy(function ($s) {
                return $this->extractStepTimestamp($s)->timestamp;
            })->values();
        }
        unset($data);

        // Multi-stage sequential evaluation
        $stepResults = [];
        $qualifiedConversationsPrev = []; // convId => ['stepped_at' => Carbon, 'step' => FlowExecutionStep]
        $firstStepUsersCount = 0;

        foreach ($activeSteps as $idx => $stepDef) {
            $stepNum = $idx + 1;
            $qualifiedCurrent = [];
            $qualifiedContactsCollection = collect();

            if ($stepNum === 1) {
                // Step 1: Users who executed the first step definition
                foreach ($stepsByConversation as $convId => $convData) {
                    foreach ($convData['steps'] as $s) {
                        if ($this->stepMatchesDefinition($s, $stepDef, $allSteps, $optionsMap)) {
                            $ts = $this->extractStepTimestamp($s);
                            $qualifiedCurrent[$convId] = [
                                'stepped_at' => $ts,
                                'step' => $s,
                            ];
                            $qualifiedContactsCollection->push($this->formatContactRecord($s));
                            break; // Stop at first match in conversation
                        }
                    }
                }
            } else {
                // Step N > 1: Subset of previous step that traversed step N chronologically later (>= prev timestamp)
                foreach ($qualifiedConversationsPrev as $convId => $prevData) {
                    $prevTimestamp = $prevData['stepped_at'];
                    $convData = $stepsByConversation[$convId] ?? null;
                    if (! $convData) {
                        continue;
                    }

                    foreach ($convData['steps'] as $s) {
                        $ts = $this->extractStepTimestamp($s);
                        if ($ts->greaterThanOrEqualTo($prevTimestamp) &&
                            $this->stepMatchesDefinition($s, $stepDef, $allSteps, $optionsMap)) {
                            $qualifiedCurrent[$convId] = [
                                'stepped_at' => $ts,
                                'step' => $s,
                            ];
                            $qualifiedContactsCollection->push($this->formatContactRecord($s));
                            break; // Take earliest subsequent match
                        }
                    }
                }
            }

            // Deduplicate contacts for accurate unique user counts
            $uniqueContacts = $qualifiedContactsCollection->unique(function ($c) {
                return $c['contact_id'] ?: 'conv_'.$c['conversation_id'];
            })->values();

            $currentUsersCount = $uniqueContacts->count();

            if ($stepNum === 1) {
                $firstStepUsersCount = $currentUsersCount;
                $conversionFromPrev = 100.0;
                $dropoffFromPrev = 0.0;
                $conversionFromFirst = 100.0;
            } else {
                $prevUsersCount = $stepResults[$idx - 1]['users_count'];
                $conversionFromPrev = $prevUsersCount > 0
                    ? round(($currentUsersCount / $prevUsersCount) * 100, 1)
                    : 0.0;
                $dropoffFromPrev = $prevUsersCount > 0
                    ? round(max(0.0, 100.0 - $conversionFromPrev), 1)
                    : 0.0;
                $conversionFromFirst = $firstStepUsersCount > 0
                    ? round(($currentUsersCount / $firstStepUsersCount) * 100, 1)
                    : 0.0;
            }

            $stepResults[] = [
                'step_number' => $stepNum,
                'label' => $stepDef['label'] ?? "Paso {$stepNum}",
                'node_id' => $stepDef['node_id'] ?? null,
                'node_key' => $stepDef['node_key'] ?? null,
                'node_type' => $stepDef['node_type'] ?? null,
                'option_id' => $stepDef['option_id'] ?? null,
                'option_title' => $stepDef['option_title'] ?? null,
                'users_count' => $currentUsersCount,
                'conversion_from_previous' => $conversionFromPrev,
                'dropoff_from_previous' => $dropoffFromPrev,
                'conversion_from_first' => $conversionFromFirst,
                'qualified_contacts' => $uniqueContacts,
            ];

            $qualifiedConversationsPrev = $qualifiedCurrent;
        }

        return ['steps' => $stepResults];
    }

    /**
     * Get specific contacts who reached a specific sequential step.
     *
     * @param  array{flow_id?: string, step_number?: int, steps?: array<int, array<string, mixed>>|string, from?: string|Carbon|null, to?: string|Carbon|null, channel_id?: string|null, per_page?: int|null, page?: int|null}  $filters
     * @return array{data: array<int, array<string, mixed>>, total: int, per_page: int, current_page: int, last_page: int}
     */
    public function getStepContacts(Flow $flow, array $filters = []): array
    {
        $stepNumber = max(1, (int) ($filters['step_number'] ?? 1));
        $stepsInput = $filters['steps'] ?? null;

        if (is_string($stepsInput)) {
            $stepsInput = json_decode($stepsInput, true);
        }

        if (empty($stepsInput) || ! is_array($stepsInput)) {
            $stepsInput = $this->getDefaultSteps($flow);
        }

        $sequential = $this->calculateSequentialFunnel($flow, $stepsInput, $filters);
        $targetStepResult = null;

        foreach ($sequential['steps'] as $stepRes) {
            if ($stepRes['step_number'] === $stepNumber) {
                $targetStepResult = $stepRes;
                break;
            }
        }

        /** @var Collection<int, array<string, mixed>> $contacts */
        $contacts = $targetStepResult['qualified_contacts'] ?? collect();
        $contacts = $contacts->sortByDesc('stepped_at')->values();

        $perPage = max(1, (int) ($filters['per_page'] ?? 25));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $total = $contacts->count();
        $sliced = $contacts->slice(($page - 1) * $perPage, $perPage)->values();

        return [
            'data' => $sliced->all(),
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int) ceil($total / $perPage) ?: 1,
        ];
    }

    /**
     * Get consolidated funnel data including backward-compatible KPIs, available steps, and sequential funnel.
     *
     * @param  array{from?: string|Carbon|null, to?: string|Carbon|null, channel_id?: string|null, steps?: array<int, array<string, mixed>>|string}  $filters
     * @return array<string, mixed>
     */
    public function getFunnel(Flow $flow, array $filters = []): array
    {
        $analytics = $this->flowAnalyticsService->getFlowAnalytics($flow, $filters);
        $availableSteps = $this->getAvailableSteps($flow);

        $stepsInput = $filters['steps'] ?? null;
        if (is_string($stepsInput)) {
            $stepsInput = json_decode($stepsInput, true);
        }

        if (empty($stepsInput) || ! is_array($stepsInput)) {
            $stepsInput = $this->getDefaultSteps($flow);
        }

        $sequential = $this->calculateSequentialFunnel($flow, $stepsInput, $filters);

        // Sanitize sequential steps for response (remove qualified_contacts collection)
        $cleanSequentialSteps = array_map(function ($s) {
            unset($s['qualified_contacts']);

            return $s;
        }, $sequential['steps'] ?? []);

        return array_merge($analytics, [
            'available_steps' => $availableSteps,
            'default_steps' => $this->getDefaultSteps($flow),
            'sequential_funnel' => $cleanSequentialSteps,
            'configured_steps' => $stepsInput,
        ]);
    }

    /**
     * Get specific contacts that selected a particular menu option (Legacy / Drill-down).
     *
     * @param  array{node_id?: string|null, node_key?: string|null, option_id?: string|null, option_title?: string|null, from?: string|Carbon|null, to?: string|Carbon|null, channel_id?: string|null, per_page?: int|null, page?: int|null}  $filters
     * @return array{data: array<int, array<string, mixed>>, total: int, per_page: int, current_page: int, last_page: int}
     */
    public function getOptionContacts(Flow $flow, array $filters = []): array
    {
        [$from, $to] = $this->parseDateRange($filters);

        $channelId = ! empty($filters['channel_id']) ? (string) $filters['channel_id'] : null;
        $nodeId = ! empty($filters['node_id']) ? (string) $filters['node_id'] : null;
        $nodeKey = ! empty($filters['node_key']) ? (string) $filters['node_key'] : null;
        $optionId = ! empty($filters['option_id']) ? (string) $filters['option_id'] : null;
        $optionTitle = ! empty($filters['option_title']) ? (string) $filters['option_title'] : null;

        $executionsQuery = FlowExecution::query()
            ->where('flow_executions.flow_id', $flow->id)
            ->whereBetween('flow_executions.started_at', [$from, $to]);

        if ($channelId) {
            $executionsQuery->whereHas('conversation', function (Builder $q) use ($channelId) {
                $q->where('channel_id', $channelId);
            });
        }

        $executionIds = $executionsQuery->pluck('id');
        if ($executionIds->isEmpty()) {
            return [
                'data' => [],
                'total' => 0,
                'per_page' => 25,
                'current_page' => 1,
                'last_page' => 1,
            ];
        }

        /** @var FlowNode|null $menuNode */
        $menuNode = null;
        if ($nodeId) {
            $menuNode = FlowNode::find($nodeId);
        } elseif ($nodeKey) {
            $version = $flow->activeVersion ?? $flow->versions()->orderByDesc('version_number')->first();
            if ($version) {
                $menuNode = $version->nodes()->where('node_key', $nodeKey)->first();
            }
        }

        $allExecutionSteps = FlowExecutionStep::query()
            ->whereIn('flow_execution_id', $executionIds)
            ->with([
                'flowExecution.conversation.contact',
                'flowExecution.conversation.channel',
            ])
            ->orderBy('flow_execution_id')
            ->orderBy('entered_at')
            ->get();

        $menuStepsQuery = $allExecutionSteps->where('node_type', FlowNodeType::MENU->value);
        if ($menuNode) {
            $menuStepsQuery = $menuStepsQuery->where('flow_node_id', $menuNode->id);
        }

        $targetNodeKey = null;
        if ($menuNode && ! empty($menuNode->configuration['options'])) {
            $rawOptions = $menuNode->configuration['options'];
            foreach ($rawOptions as $idx => $opt) {
                $optId = is_array($opt) ? (string) ($opt['id'] ?? ($idx + 1)) : (string) ($idx + 1);
                $title = is_array($opt)
                    ? (string) ($opt['title'] ?? ($opt['text'] ?? ($opt['value'] ?? "Opción {$optId}")))
                    : (string) $opt;

                if (($optionId !== null && $optId === $optionId) ||
                    ($optionTitle !== null && mb_strtolower($title) === mb_strtolower($optionTitle))) {
                    $targetNodeKey = is_array($opt) ? ($opt['target_node_key'] ?? null) : null;
                    break;
                }
            }
        }

        $matchedContacts = collect();
        $seenExecutionIds = [];

        foreach ($menuStepsQuery as $step) {
            if (isset($seenExecutionIds[$step->flow_execution_id])) {
                continue;
            }

            $output = $step->output ?? [];
            $selectedOptionId = isset($output['selected_option_id']) ? (string) $output['selected_option_id'] : null;
            $selectedOptionText = isset($output['selected_option_text']) ? (string) $output['selected_option_text'] : null;
            $selectedOptionValue = isset($output['selected_option_value']) ? (string) $output['selected_option_value'] : null;
            $outputTargetKey = $output['target_node_key'] ?? null;

            $matched = false;

            if ($optionId !== null && $selectedOptionId !== null && $selectedOptionId === $optionId) {
                $matched = true;
            } elseif ($optionTitle !== null && $selectedOptionText !== null && mb_strtolower($selectedOptionText) === mb_strtolower($optionTitle)) {
                $matched = true;
            } elseif ($optionTitle !== null && $selectedOptionValue !== null && mb_strtolower($selectedOptionValue) === mb_strtolower($optionTitle)) {
                $matched = true;
            } elseif ($targetNodeKey !== null && $outputTargetKey !== null && $outputTargetKey === $targetNodeKey) {
                $matched = true;
            }

            if (! $matched && $targetNodeKey && $step->status === FlowExecutionStepStatus::COMPLETED) {
                $execSteps = $allExecutionSteps->where('flow_execution_id', $step->flow_execution_id)->values();
                $currentIdx = $execSteps->search(fn ($s) => $s->id === $step->id);
                if ($currentIdx !== false && isset($execSteps[$currentIdx + 1])) {
                    $nextStep = $execSteps[$currentIdx + 1];
                    if ($nextStep->flowNode?->node_key === $targetNodeKey) {
                        $matched = true;
                    }
                }
            }

            if ($matched) {
                $seenExecutionIds[$step->flow_execution_id] = true;
                $matchedContacts->push($this->formatContactRecord($step));
            }
        }

        $matchedContacts = $matchedContacts->sortByDesc('stepped_at')->values();

        $perPage = max(1, (int) ($filters['per_page'] ?? 25));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $total = $matchedContacts->count();
        $sliced = $matchedContacts->slice(($page - 1) * $perPage, $perPage)->values();

        return [
            'data' => $sliced->all(),
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int) ceil($total / $perPage) ?: 1,
        ];
    }

    /**
     * Generate CSV content for contact records.
     *
     * @param  array<int, array<string, mixed>>  $contacts
     */
    public function generateContactsCsv(array $contacts): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($handle, ['ID Contacto', 'Nombre', 'Teléfono / ID Externo', 'Email', 'Canal', 'ID Conversación', 'Fecha y Hora']);

        foreach ($contacts as $contact) {
            fputcsv($handle, [
                $contact['contact_id'] ?? '',
                $contact['contact_name'] ?? '',
                $contact['phone_number'] ?? '',
                $contact['email'] ?? '',
                $contact['channel_name'] ?? $contact['channel_type'] ?? '',
                $contact['conversation_id'] ?? '',
                $contact['stepped_at_formatted'] ?? $contact['stepped_at'] ?? '',
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv !== false ? $csv : '';
    }

    /**
     * Helper to test whether a FlowExecutionStep matches a step definition.
     *
     * @param  array<string, mixed>  $stepDef
     * @param  Collection<int, FlowExecutionStep>  $allSteps
     * @param  array<string, array<int, array<string, mixed>>>  $optionsMap
     */
    protected function stepMatchesDefinition(
        FlowExecutionStep $step,
        array $stepDef,
        Collection $allSteps,
        array $optionsMap
    ): bool {
        $nodeId = ! empty($stepDef['node_id']) ? (string) $stepDef['node_id'] : null;
        $nodeKey = ! empty($stepDef['node_key']) ? (string) $stepDef['node_key'] : null;
        $optionId = isset($stepDef['option_id']) && $stepDef['option_id'] !== '' ? (string) $stepDef['option_id'] : null;
        $optionTitle = isset($stepDef['option_title']) && $stepDef['option_title'] !== '' ? (string) $stepDef['option_title'] : null;

        $matchesNode = false;
        if ($nodeId && (string) $step->flow_node_id === $nodeId) {
            $matchesNode = true;
        } elseif ($nodeKey && (string) $step->flowNode?->node_key === $nodeKey) {
            $matchesNode = true;
        }

        if (! $matchesNode) {
            return false;
        }

        if ($optionId === null && $optionTitle === null) {
            return true;
        }

        $output = $step->output ?? [];
        $selectedOptionId = isset($output['selected_option_id']) ? (string) $output['selected_option_id'] : null;
        $selectedOptionText = isset($output['selected_option_text']) ? (string) $output['selected_option_text'] : null;
        $selectedOptionValue = isset($output['selected_option_value']) ? (string) $output['selected_option_value'] : null;
        $outputTargetKey = $output['target_node_key'] ?? null;

        $targetNodeKey = $stepDef['target_node_key'] ?? null;
        if (! $targetNodeKey && $nodeId && isset($optionsMap[$nodeId])) {
            foreach ($optionsMap[$nodeId] as $opt) {
                if (($optionId !== null && $opt['id'] === $optionId) ||
                    ($optionTitle !== null && mb_strtolower($opt['title']) === mb_strtolower($optionTitle))) {
                    $targetNodeKey = $opt['target_node_key'];
                    break;
                }
            }
        }

        if ($optionId !== null && $selectedOptionId !== null && $selectedOptionId === $optionId) {
            return true;
        }
        if ($optionTitle !== null && $selectedOptionText !== null && mb_strtolower($selectedOptionText) === mb_strtolower($optionTitle)) {
            return true;
        }
        if ($optionTitle !== null && $selectedOptionValue !== null && mb_strtolower($selectedOptionValue) === mb_strtolower($optionTitle)) {
            return true;
        }
        if ($targetNodeKey !== null && $outputTargetKey !== null && $outputTargetKey === $targetNodeKey) {
            return true;
        }

        if ($targetNodeKey !== null && $step->status === FlowExecutionStepStatus::COMPLETED) {
            $execSteps = $allSteps->where('flow_execution_id', $step->flow_execution_id)->values();
            $currentIdx = $execSteps->search(fn ($s) => $s->id === $step->id);
            if ($currentIdx !== false && isset($execSteps[$currentIdx + 1])) {
                $nextStep = $execSteps[$currentIdx + 1];
                if ($nextStep->flowNode?->node_key === $targetNodeKey) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Map menu options per node for target lookup.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function buildOptionsMap(Flow $flow): array
    {
        $version = $flow->activeVersion ?? $flow->versions()->orderByDesc('version_number')->first();
        if (! $version) {
            return [];
        }

        $map = [];
        $menuNodes = $version->nodes()->where('type', FlowNodeType::MENU)->get();

        foreach ($menuNodes as $node) {
            $nodeId = (string) $node->id;
            $map[$nodeId] = [];

            if (! empty($node->configuration['options'])) {
                foreach ($node->configuration['options'] as $idx => $opt) {
                    $optId = is_array($opt) ? (string) ($opt['id'] ?? ($idx + 1)) : (string) ($idx + 1);
                    $optTitle = is_array($opt)
                        ? (string) ($opt['title'] ?? ($opt['text'] ?? ($opt['value'] ?? "Opción {$optId}")))
                        : (string) $opt;
                    $targetKey = is_array($opt) ? ($opt['target_node_key'] ?? null) : null;

                    $map[$nodeId][] = [
                        'id' => $optId,
                        'title' => $optTitle,
                        'target_node_key' => $targetKey,
                    ];
                }
            }
        }

        return $map;
    }

    /**
     * Parse date range filters.
     *
     * @param  array{from?: string|Carbon|null, to?: string|Carbon|null}  $filters
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function parseDateRange(array $filters): array
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

        return [$from, $to];
    }

    /**
     * Extract timestamp from a FlowExecutionStep.
     */
    protected function extractStepTimestamp(FlowExecutionStep $step): Carbon
    {
        if ($step->completed_at) {
            return Carbon::parse($step->completed_at);
        }
        if ($step->entered_at) {
            return Carbon::parse($step->entered_at);
        }
        if ($step->created_at) {
            return Carbon::parse($step->created_at);
        }

        return Carbon::now();
    }

    /**
     * Format a contact record from an execution step.
     *
     * @return array<string, mixed>
     */
    protected function formatContactRecord(FlowExecutionStep $step): array
    {
        $execution = $step->flowExecution;
        $conversation = $execution?->conversation;
        $contact = $conversation?->contact;
        $channel = $conversation?->channel;

        $steppedAt = $this->extractStepTimestamp($step);

        return [
            'contact_id' => $contact?->id ? (string) $contact->id : null,
            'contact_name' => $contact?->display_name ?? 'Visitante Anónimo',
            'phone_number' => $contact?->phone ?: ($contact?->external_identifier ?? 'N/A'),
            'email' => $contact?->email ?? '',
            'channel_type' => $channel?->type?->value ?? ($channel?->type ?? 'webchat'),
            'channel_name' => $channel?->name ?? 'Canal Web',
            'conversation_id' => $conversation?->id ? (string) $conversation->id : null,
            'execution_id' => $execution?->id ? (string) $execution->id : null,
            'stepped_at' => $steppedAt->toIso8601String(),
            'stepped_at_formatted' => $steppedAt->format('d/m/Y H:i:s'),
        ];
    }

    /**
     * Build empty sequential step results when no data exists.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    protected function buildEmptySequentialSteps(array $steps): array
    {
        $results = [];
        foreach ($steps as $idx => $stepDef) {
            $num = $idx + 1;
            $results[] = [
                'step_number' => $num,
                'label' => $stepDef['label'] ?? "Paso {$num}",
                'node_id' => $stepDef['node_id'] ?? null,
                'node_key' => $stepDef['node_key'] ?? null,
                'node_type' => $stepDef['node_type'] ?? null,
                'option_id' => $stepDef['option_id'] ?? null,
                'option_title' => $stepDef['option_title'] ?? null,
                'users_count' => 0,
                'conversion_from_previous' => $num === 1 ? 100.0 : 0.0,
                'dropoff_from_previous' => 0.0,
                'conversion_from_first' => $num === 1 ? 100.0 : 0.0,
                'qualified_contacts' => collect(),
            ];
        }

        return $results;
    }
}
