<?php

namespace App\Services\Runtime;

use App\Enums\ConversationStatus;
use App\Enums\FlowExecutionStatus;
use App\Enums\FlowExecutionStepStatus;
use App\Enums\FlowFailureCode;
use App\Enums\FlowNodeType;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageType;
use App\Events\ContactUpdated;
use App\Events\ConversationClosed;
use App\Events\ConversationStatusChanged;
use App\Events\ConversationWaitingAgent;
use App\Events\FlowExecutionCompleted;
use App\Events\FlowExecutionFailed;
use App\Events\FlowExecutionStarted;
use App\Events\FlowExecutionTransferred;
use App\Events\FlowExecutionWaitingInput;
use App\Events\FlowNodeCompleted;
use App\Events\FlowNodeEntered;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\FlowExecution;
use App\Models\FlowExecutionStep;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class FlowRuntimeEngine
{
    public function __construct(
        protected NodeHandlerRegistry $registry,
        protected FlowGraphCacheService $graphCache
    ) {
    }

    /**
     * Starts a new flow execution for a conversation pinned to the given FlowVersion.
     */
    public function start(Conversation $conversation, FlowVersion $version, ?string $correlationId = null): FlowExecution
    {
        $correlationId = $correlationId ?: (string) Str::uuid();

        return DB::transaction(function () use ($conversation, $version, $correlationId) {
            $versionWithGraph = $this->graphCache->getVersionWithGraph($version) ?? $version;
            $versionWithGraph->loadMissing(['nodes', 'edges']);

            $startNode = $versionWithGraph->nodes->firstWhere('type', FlowNodeType::START);
            if (!$startNode) {
                throw new \RuntimeException('La versión del flujo no contiene un nodo de inicio.');
            }

            $meta = $conversation->metadata ?? [];
            unset($meta['closing_assignment_captured'], $meta['last_assigned_user_id']);

            $conversation->update([
                'active_flow_id' => $versionWithGraph->flow_id,
                'active_flow_version_id' => $versionWithGraph->id,
                'status' => ConversationStatus::BOT,
                'metadata' => $meta,
            ]);

            $initialContext = $this->hydrateContactVariables($conversation);

            $execution = FlowExecution::create([
                'conversation_id' => $conversation->id,
                'flow_id' => $versionWithGraph->flow_id,
                'flow_version_id' => $versionWithGraph->id,
                'current_node_id' => $startNode->id,
                'status' => FlowExecutionStatus::RUNNING,
                'context' => $initialContext,
                'started_at' => now(),
            ]);

            Log::info("[FlowRuntimeEngine] [{$correlationId}] Iniciada ejecución {$execution->id} para conversación {$conversation->id} en versión {$versionWithGraph->id}");

            event(new FlowExecutionStarted($execution));

            return $this->advance($execution, $correlationId);
        });
    }

    /**
     * Resumes a waiting execution with an inbound message.
     */
    public function resumeWithInboundMessage(Conversation $conversation, Message $message, ?string $correlationId = null): ?FlowExecution
    {
        $correlationId = $correlationId ?: (string) Str::uuid();

        return DB::transaction(function () use ($conversation, $message, $correlationId) {
            /** @var FlowExecution|null $execution */
            $execution = FlowExecution::where('conversation_id', $conversation->id)
                ->where('status', FlowExecutionStatus::WAITING_INPUT)
                ->lockForUpdate()
                ->first();

            if (!$execution) {
                return null;
            }

            $version = $this->graphCache->getVersionWithGraph($execution->flow_version_id);
            if (!$version) {
                $this->failExecution(
                    $execution,
                    FlowFailureCode::EXECUTION_STATE_ERROR->value,
                    'No se encontró la versión fijada del flujo.',
                    $correlationId
                );

                return $execution;
            }

            $version->loadMissing(['nodes', 'edges']);

            /** @var FlowNode|null $currentNode */
            $currentNode = $version->nodes->firstWhere('id', $execution->current_node_id);
            if (!$currentNode) {
                $this->failExecution(
                    $execution,
                    FlowFailureCode::NODE_NOT_FOUND->value,
                    "No se encontró el nodo actual '{$execution->current_node_id}' en la versión del flujo.",
                    $correlationId
                );

                return $execution;
            }

            /** @var FlowExecutionStep|null $waitingStep */
            $waitingStep = FlowExecutionStep::where('flow_execution_id', $execution->id)
                ->where('flow_node_id', $currentNode->id)
                ->where('status', FlowExecutionStepStatus::WAITING)
                ->latest('entered_at')
                ->first();

            $context = ExecutionContext::fromArray($execution->context, $conversation->contact, (string) $conversation->workspace_id);

            try {
                $handler = $this->registry->get($currentNode->type->value);
            } catch (Throwable $e) {
                $this->failExecution(
                    $execution,
                    FlowFailureCode::HANDLER_NOT_FOUND->value,
                    $e->getMessage(),
                    $correlationId
                );

                return $execution;
            }

            $result = $handler->resume($currentNode, $execution, $context, $message);
            $execution->context = $context->toArray();
            $this->syncContactFromContext($execution, $context);

            if ($result->isWaitingInput()) {
                // Validation retry; keep waiting
                if ($waitingStep) {
                    $meta = $waitingStep->metadata ?? [];
                    $meta['last_retry_at'] = now()->toIso8601String();
                    $meta['attempts'] = ($meta['attempts'] ?? 1) + 1;
                    $waitingStep->update(['metadata' => $meta]);
                }
                $execution->save();

                return $execution;
            }

            if ($result->isFailed()) {
                if ($waitingStep) {
                    $waitingStep->update([
                        'status' => FlowExecutionStepStatus::FAILED,
                        'completed_at' => now(),
                        'error_code' => $result->failureCode,
                    ]);
                }
                $this->failExecution(
                    $execution,
                    $result->failureCode ?? FlowFailureCode::INVALID_NODE_CONFIG->value,
                    $result->errorMessage ?? 'Error desconocido',
                    $correlationId
                );

                return $execution;
            }

            if ($result->isCompleted()) {
                if ($waitingStep) {
                    $waitingStep->update([
                        'status' => FlowExecutionStepStatus::COMPLETED,
                        'completed_at' => now(),
                        'output' => $result->output,
                        'metadata' => $result->metadata,
                    ]);
                }

                // Locate outgoing edge / branch
                $edges = $version->edges->where('source_node_key', $currentNode->node_key);
                $outgoingEdge = null;

                if ($result->outgoingHandle !== null) {
                    $outgoingEdge = $edges->firstWhere('condition_handle', $result->outgoingHandle);
                }

                if (!$outgoingEdge && !empty($result->output['target_node_key'])) {
                    $targetNodeKey = $result->output['target_node_key'];
                    $nextNode = $version->nodes->firstWhere('node_key', $targetNodeKey);
                    if ($nextNode) {
                        $execution->update([
                            'status' => FlowExecutionStatus::RUNNING,
                            'waiting_since' => null,
                            'current_node_id' => $nextNode->id,
                        ]);

                        return $this->advance($execution, $correlationId);
                    }
                }

                if (!$outgoingEdge) {
                    $outgoingEdge = $edges->first();
                }

                if (!$outgoingEdge) {
                    $this->failExecution(
                        $execution,
                        FlowFailureCode::INVALID_EDGE->value,
                        "El nodo '{$currentNode->node_key}' no tiene ninguna conexión saliente válida para el handle: '" . ($result->outgoingHandle ?? 'default') . "'.",
                        $correlationId
                    );

                    return $execution;
                }

                $nextNode = $version->nodes->firstWhere('node_key', $outgoingEdge->target_node_key);
                if (!$nextNode) {
                    $this->failExecution(
                        $execution,
                        FlowFailureCode::NODE_NOT_FOUND->value,
                        "El nodo destino '{$outgoingEdge->target_node_key}' no existe en la versión del flujo.",
                        $correlationId
                    );

                    return $execution;
                }

                $execution->update([
                    'status' => FlowExecutionStatus::RUNNING,
                    'waiting_since' => null,
                    'current_node_id' => $nextNode->id,
                ]);

                return $this->advance($execution, $correlationId);
            }

            return $execution;
        });
    }

    /**
     * Advances the flow execution until it pauses, waits, completes, or fails.
     */
    public function advance(FlowExecution $execution, ?string $correlationId = null): FlowExecution
    {
        $correlationId = $correlationId ?: (string) ($execution->context['_correlation_id'] ?? Str::uuid());

        return DB::transaction(function () use ($execution, $correlationId) {
            /** @var FlowExecution $lockedExecution */
            $lockedExecution = FlowExecution::where('id', $execution->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedExecution->status !== FlowExecutionStatus::RUNNING) {
                return $lockedExecution;
            }

            $version = $this->graphCache->getVersionWithGraph($lockedExecution->flow_version_id);
            if (!$version) {
                $this->failExecution(
                    $lockedExecution,
                    FlowFailureCode::EXECUTION_STATE_ERROR->value,
                    'No se encontró la versión fijada del flujo.',
                    $correlationId
                );

                return $lockedExecution;
            }

            $version->loadMissing(['nodes', 'edges']);

            $currentNode = $version->nodes->firstWhere('id', $lockedExecution->current_node_id);
            if (!$currentNode) {
                $currentNode = $version->nodes->firstWhere('type', FlowNodeType::START);
                if (!$currentNode) {
                    $this->failExecution(
                        $lockedExecution,
                        FlowFailureCode::NODE_NOT_FOUND->value,
                        'No se encontró ningún nodo válido para iniciar.',
                        $correlationId
                    );

                    return $lockedExecution;
                }
                $lockedExecution->current_node_id = $currentNode->id;
                $lockedExecution->save();
            }

            $context = ExecutionContext::fromArray(
                $lockedExecution->context,
                $lockedExecution->conversation?->contact,
                (string) $lockedExecution->conversation?->workspace_id
            );

            $maxSteps = (int) config('flows.max_steps_per_run', 100);
            $maxVisitsPerNode = (int) config('flows.max_node_visits_per_turn', 10);
            $stepCount = 0;
            $visitedTrail = [];
            $nodeVisitCounts = [];

            while ($currentNode && $lockedExecution->status === FlowExecutionStatus::RUNNING) {
                $stepCount++;
                $visitedTrail[] = $currentNode->node_key;
                $nodeVisitCounts[$currentNode->node_key] = ($nodeVisitCounts[$currentNode->node_key] ?? 0) + 1;

                // Infinite loop detection: repetitive visits to same node within single automatic turn
                if ($nodeVisitCounts[$currentNode->node_key] > $maxVisitsPerNode) {
                    $recentTrail = implode(' → ', array_slice($visitedTrail, -8));
                    $this->failExecution(
                        $lockedExecution,
                        FlowFailureCode::MAX_STEPS_EXCEEDED->value,
                        "Bucle infinito automático detectado: el nodo '{$currentNode->node_key}' fue visitado más de {$maxVisitsPerNode} veces en un mismo turno sin interacción. Secuencia: {$recentTrail}.",
                        $correlationId
                    );
                    break;
                }

                if ($stepCount > $maxSteps) {
                    $recentTrail = implode(' → ', array_slice($visitedTrail, -6));
                    $this->failExecution(
                        $lockedExecution,
                        FlowFailureCode::MAX_STEPS_EXCEEDED->value,
                        "Se superó el presupuesto máximo de pasos ({$maxSteps}) por ejecución. Posible bucle infinito detectado en secuencia: {$recentTrail}.",
                        $correlationId
                    );
                    break;
                }

                // Check handler
                try {
                    $handler = $this->registry->get($currentNode->type->value);
                } catch (Throwable $e) {
                    $this->failExecution(
                        $lockedExecution,
                        FlowFailureCode::HANDLER_NOT_FOUND->value,
                        $e->getMessage(),
                        $correlationId
                    );
                    break;
                }

                // Create Step
                $step = FlowExecutionStep::create([
                    'flow_execution_id' => $lockedExecution->id,
                    'flow_node_id' => $currentNode->id,
                    'node_type' => $currentNode->type->value,
                    'status' => FlowExecutionStepStatus::ENTERED,
                    'entered_at' => now(),
                    'input' => [
                        'node_key' => $currentNode->node_key,
                        'name' => $currentNode->name,
                        'correlation_id' => $correlationId,
                    ],
                ]);

                event(new FlowNodeEntered($lockedExecution, $currentNode, $step));

                $stepStart = microtime(true);
                try {
                    $result = $handler->execute($currentNode, $lockedExecution, $context);
                } catch (Throwable $e) {
                    $stepDurationMs = (int) round((microtime(true) - $stepStart) * 1000);
                    $step->update([
                        'status' => FlowExecutionStepStatus::FAILED,
                        'completed_at' => now(),
                        'error_code' => FlowFailureCode::INVALID_NODE_CONFIG->value,
                        'metadata' => [
                            'exception' => $e->getMessage(),
                            'duration_ms' => $stepDurationMs,
                            'correlation_id' => $correlationId,
                        ],
                    ]);
                    $this->failExecution($lockedExecution, FlowFailureCode::INVALID_NODE_CONFIG->value, $e->getMessage(), $correlationId);
                    break;
                }

                $stepDurationMs = (int) round((microtime(true) - $stepStart) * 1000);
                $lockedExecution->context = $context->toArray();
                $this->syncContactFromContext($lockedExecution, $context);

                if ($result->isWaitingInput()) {
                    $meta = $result->metadata;
                    $meta['duration_ms'] = $stepDurationMs;
                    $meta['correlation_id'] = $correlationId;

                    $step->update([
                        'status' => FlowExecutionStepStatus::WAITING,
                        'output' => $result->output,
                        'metadata' => $meta,
                    ]);

                    $lockedExecution->update([
                        'status' => FlowExecutionStatus::WAITING_INPUT,
                        'current_node_id' => $currentNode->id,
                        'waiting_since' => now(),
                    ]);

                    event(new FlowExecutionWaitingInput($lockedExecution, $currentNode));
                    break;
                }

                if ($result->isTransferred()) {
                    $meta = $result->metadata;
                    $meta['duration_ms'] = $stepDurationMs;
                    $meta['correlation_id'] = $correlationId;

                    $step->update([
                        'status' => FlowExecutionStepStatus::COMPLETED,
                        'completed_at' => now(),
                        'output' => $result->output,
                        'metadata' => $meta,
                    ]);

                    $conv = $lockedExecution->conversation;
                    $isSimulation = (bool) ($conv?->is_simulation ?? false);

                    if ($isSimulation) {
                        $previousAssignedUserId = $conv?->assigned_user_id;
                        if (!$previousAssignedUserId && $conv) {
                            $previousAssignedUserId = ConversationAssignment::where('conversation_id', $conv->id)
                                ->whereNull('unassigned_at')
                                ->orderByDesc('assigned_at')
                                ->orderByDesc('id')
                                ->value('assigned_user_id');
                        }
                        $now = now();
                        $metadata = $conv?->metadata ?? [];
                        $metadata['closing_assignment_captured'] = true;
                        if ($previousAssignedUserId) {
                            $metadata['last_assigned_user_id'] = (int) $previousAssignedUserId;
                        } else {
                            unset($metadata['last_assigned_user_id']);
                        }

                        $lockedExecution->update([
                            'status' => FlowExecutionStatus::COMPLETED,
                            'current_node_id' => $currentNode->id,
                            'completed_at' => $now,
                        ]);
                        $conv?->update([
                            'status' => ConversationStatus::CLOSED,
                            'closed_at' => $now,
                            'assigned_user_id' => null,
                            'metadata' => $metadata,
                        ]);
                        if ($conv) {
                            ConversationAssignment::where('conversation_id', $conv->id)
                                ->whereNull('unassigned_at')
                                ->update(['unassigned_at' => $now]);
                        }
                        event(new FlowExecutionTransferred($lockedExecution, $currentNode));
                        break;
                    }

                    $lockedExecution->update([
                        'status' => FlowExecutionStatus::WAITING_AGENT,
                        'current_node_id' => $currentNode->id,
                    ]);

                    $conv?->update([
                        'status' => ConversationStatus::WAITING_AGENT,
                    ]);

                    event(new FlowExecutionTransferred($lockedExecution, $currentNode));

                    if ($conv) {
                        app(\App\Services\Routing\ConversationAssignmentService::class)->autoAssign($conv);
                        $freshConv = $conv->fresh(['channel', 'contact', 'assignedUser']);
                        DB::afterCommit(function () use ($freshConv) {
                            if ($freshConv->status === ConversationStatus::WAITING_AGENT) {
                                broadcast(new ConversationWaitingAgent($freshConv));
                            }
                            broadcast(new ConversationStatusChanged($freshConv, 'bot'));
                        });
                    }
                    break;
                }

                if ($result->isFlowCompleted()) {
                    $meta = $result->metadata;
                    $meta['duration_ms'] = $stepDurationMs;
                    $meta['correlation_id'] = $correlationId;

                    $step->update([
                        'status' => FlowExecutionStepStatus::COMPLETED,
                        'completed_at' => now(),
                        'output' => $result->output,
                        'metadata' => $meta,
                    ]);

                    $lockedExecution->update([
                        'status' => FlowExecutionStatus::COMPLETED,
                        'current_node_id' => null,
                        'completed_at' => now(),
                    ]);

                    $conv = $lockedExecution->conversation;
                    $isSimulation = (bool) ($conv?->is_simulation ?? false);
                    $previousAssignedUserId = $conv?->assigned_user_id;
                    if (!$previousAssignedUserId && $conv) {
                        $previousAssignedUserId = ConversationAssignment::where('conversation_id', $conv->id)
                            ->whereNull('unassigned_at')
                            ->orderByDesc('assigned_at')
                            ->orderByDesc('id')
                            ->value('assigned_user_id');
                    }
                    $now = now();

                    $metadata = $conv?->metadata ?? [];
                    $metadata['closing_assignment_captured'] = true;
                    if ($previousAssignedUserId) {
                        $metadata['last_assigned_user_id'] = (int) $previousAssignedUserId;
                    } else {
                        unset($metadata['last_assigned_user_id']);
                    }

                    $conv?->update([
                        'status' => ConversationStatus::CLOSED,
                        'closed_at' => $now,
                        'assigned_user_id' => null,
                        'metadata' => $metadata,
                    ]);

                    if ($conv) {
                        ConversationAssignment::where('conversation_id', $conv->id)
                            ->whereNull('unassigned_at')
                            ->update(['unassigned_at' => $now]);
                    }

                    if ($conv && ! $isSimulation) {
                        app(\App\Services\ConversationSessionDividerService::class)
                            ->ensureClosedSessionDivider($conv, 'flow_complete', $now);
                    }

                    event(new FlowExecutionCompleted($lockedExecution));

                    if ($conv && !$isSimulation) {
                        $freshConv = $conv->fresh(['channel', 'contact']);
                        DB::afterCommit(function () use ($freshConv, $previousAssignedUserId) {
                            broadcast(new ConversationClosed($freshConv));
                            broadcast(new ConversationStatusChanged($freshConv));

                            if ($previousAssignedUserId) {
                                $freedAgent = User::find($previousAssignedUserId);
                                if ($freedAgent) {
                                    app(\App\Services\Routing\ConversationAssignmentService::class)->assignWaitingOnQuotaFreed($freedAgent);
                                }
                            }
                        });
                    }
                    break;
                }

                if ($result->isFailed()) {
                    $step->update([
                        'status' => FlowExecutionStepStatus::FAILED,
                        'completed_at' => now(),
                        'error_code' => $result->failureCode,
                        'metadata' => [
                            'message' => $result->errorMessage,
                            'duration_ms' => $stepDurationMs,
                            'correlation_id' => $correlationId,
                        ],
                    ]);

                    $this->failExecution(
                        $lockedExecution,
                        $result->failureCode ?? FlowFailureCode::INVALID_NODE_CONFIG->value,
                        $result->errorMessage ?? 'Error desconocido en la ejecución del nodo.',
                        $correlationId
                    );
                    break;
                }

                // Node completed successfully
                $meta = $result->metadata;
                $meta['duration_ms'] = $stepDurationMs;
                $meta['correlation_id'] = $correlationId;

                $step->update([
                    'status' => FlowExecutionStepStatus::COMPLETED,
                    'completed_at' => now(),
                    'output' => $result->output,
                    'metadata' => $meta,
                ]);

                event(new FlowNodeCompleted($lockedExecution, $currentNode, $step));

                // Handle Goto jump directly if target_node_key is configured
                if ($currentNode->type === FlowNodeType::GOTO) {
                    $targetKey = $currentNode->configuration['target_node_key'] ?? ($result->output['target_node_key'] ?? null);
                    if ($targetKey) {
                        $targetNode = $version->nodes->firstWhere('node_key', $targetKey);
                        if (!$targetNode) {
                            $this->failExecution(
                                $lockedExecution,
                                FlowFailureCode::NODE_NOT_FOUND->value,
                                "El nodo destino del salto '{$targetKey}' no existe en la versión del flujo.",
                                $correlationId
                            );
                            break;
                        }

                        $currentNode = $targetNode;
                        $lockedExecution->current_node_id = $currentNode->id;
                        $lockedExecution->save();

                        continue;
                    }
                }

                // Find next edge
                $edges = $version->edges->where('source_node_key', $currentNode->node_key);

                if ($result->outgoingHandle !== null) {
                    $edge = $edges->firstWhere('condition_handle', $result->outgoingHandle);
                    if (!$edge) {
                        $edge = $edges->firstWhere('condition_handle', null) ?? $edges->first();
                    }
                } else {
                    $edge = $edges->first();
                }

                if (!$edge) {
                    $this->failExecution(
                        $lockedExecution,
                        FlowFailureCode::INVALID_EDGE->value,
                        "El nodo '{$currentNode->node_key}' no tiene una conexión saliente válida para el handle: '" . ($result->outgoingHandle ?? 'default') . "'.",
                        $correlationId
                    );
                    break;
                }

                $nextNode = $version->nodes->firstWhere('node_key', $edge->target_node_key);
                if (!$nextNode) {
                    $this->failExecution(
                        $lockedExecution,
                        FlowFailureCode::NODE_NOT_FOUND->value,
                        "El nodo destino '{$edge->target_node_key}' no existe en la versión del flujo.",
                        $correlationId
                    );
                    break;
                }

                $currentNode = $nextNode;
                $lockedExecution->current_node_id = $currentNode->id;
                $lockedExecution->save();
            }

            return $lockedExecution;
        });
    }

    /**
     * Hydrates the initial execution context from the contact's persistent metadata and standard attributes.
     *
     * @return array<string, mixed>
     */
    protected function hydrateContactVariables(Conversation $conversation): array
    {
        if ($conversation->is_simulation) {
            return [];
        }

        $conversation->loadMissing('contact');
        $contact = $conversation->contact;
        $context = new ExecutionContext([], $contact, (string) $conversation->workspace_id);

        if ($contact) {
            // 1. Preload stored flow variables
            $metadata = $contact->metadata ?? [];
            $storedVars = $metadata['flow_variables'] ?? $metadata['variables'] ?? [];
            if (is_array($storedVars)) {
                foreach ($storedVars as $key => $val) {
                    $context->set($key, $val);
                }
            }

            // 2. Preload non-generic name/email/phone shortcuts
            if (!empty($contact->display_name)) {
                $isGeneric = str_starts_with($contact->display_name, 'Visitante ') || $contact->display_name === 'Visitante';
                if (!$isGeneric) {
                    if (!$context->has('nombre')) {
                        $context->set('nombre', $contact->display_name);
                    }
                    if (!$context->has('visitor_name')) {
                        $context->set('visitor_name', $contact->display_name);
                    }
                    if (!$context->has('name')) {
                        $context->set('name', $contact->display_name);
                    }
                }
            }

            if (!empty($contact->email)) {
                if (!$context->has('email')) {
                    $context->set('email', $contact->email);
                }
                if (!$context->has('correo')) {
                    $context->set('correo', $contact->email);
                }
            }

            if (!empty($contact->phone)) {
                if (!$context->has('telefono')) {
                    $context->set('telefono', $contact->phone);
                }
                if (!$context->has('phone')) {
                    $context->set('phone', $contact->phone);
                }
            }
        }

        return $context->toArray();
    }

    /**
     * Synchronizes execution context variables back into persistent Contact metadata and standard fields.
     */
    protected function syncContactFromContext(FlowExecution $execution, ExecutionContext $context): void
    {
        $conversation = $execution->conversation;
        if (!$conversation || ($conversation->is_simulation ?? false)) {
            return;
        }

        $conversation->loadMissing('contact');
        $contact = $conversation->contact;
        if (!$contact) {
            return;
        }

        $allVars = $context->all();
        $flowVars = [];
        foreach ($allVars as $key => $value) {
            // Avoid internal variables starting with _ or system injected objects like 'contact'
            if (str_starts_with((string) $key, '_') || (string) $key === 'contact') {
                continue;
            }
            $flowVars[$key] = $value;
        }

        $metadata = $contact->metadata ?? [];
        $existingFlowVars = $metadata['flow_variables'] ?? [];
        $mergedFlowVars = array_merge($existingFlowVars, $flowVars);

        // Ensure name aliases are synchronized together in flow_variables
        $nameCandidate = $context->get('visitor_name') ?? $context->get('nombre') ?? $context->get('name') ?? $context->get('var_nombre');
        if (is_string($nameCandidate) && trim($nameCandidate) !== '') {
            $cleanName = mb_substr(trim($nameCandidate), 0, 255);
            $mergedFlowVars['visitor_name'] = $cleanName;
            $mergedFlowVars['nombre'] = $cleanName;
            $mergedFlowVars['name'] = $cleanName;
            $mergedFlowVars['var_nombre'] = $cleanName;
        }

        $contactDirty = false;

        if ($mergedFlowVars !== $existingFlowVars) {
            $metadata['flow_variables'] = $mergedFlowVars;
            $contact->metadata = $metadata;
            $contactDirty = true;
        }

        // Promote name if current name is empty or generic
        if (is_string($nameCandidate) && trim($nameCandidate) !== '') {
            $cleanName = mb_substr(trim($nameCandidate), 0, 255);
            $currentName = (string) ($contact->display_name ?? '');
            if ($currentName === '' || str_starts_with($currentName, 'Visitante ') || $currentName === 'Visitante') {
                if ($contact->display_name !== $cleanName) {
                    $contact->display_name = $cleanName;
                    $contactDirty = true;
                }
            }
        }

        // Promote email if currently empty
        $emailCandidate = $context->get('email') ?? $context->get('correo');
        if (empty($contact->email) && is_string($emailCandidate) && trim($emailCandidate) !== '') {
            $filteredEmail = filter_var(trim($emailCandidate), FILTER_VALIDATE_EMAIL);
            if ($filteredEmail) {
                $contact->email = mb_substr($filteredEmail, 0, 255);
                $contactDirty = true;
            }
        }

        // Promote phone if currently empty
        $phoneCandidate = $context->get('phone') ?? $context->get('telefono');
        if (empty($contact->phone) && is_string($phoneCandidate) && trim($phoneCandidate) !== '') {
            $normalizedPhone = preg_replace('/[^\d+]/', '', trim($phoneCandidate));
            if ($normalizedPhone && strlen($normalizedPhone) >= 6 && strlen($normalizedPhone) <= 30) {
                $contact->phone = $normalizedPhone;
                $contactDirty = true;
            }
        }

        if ($contactDirty) {
            $contact->save();
            $freshContact = $contact->fresh();
            $freshConv = $conversation->fresh();
            if ($freshContact && $freshConv) {
                DB::afterCommit(function () use ($freshConv, $freshContact) {
                    broadcast(new ContactUpdated($freshConv, $freshContact));
                });
            }
        }
    }

    /**
     * Handles unrecoverable execution failure, preserving consistency and transferring to agent fallback.
     */
    protected function failExecution(FlowExecution $execution, string $code, string $reason, ?string $correlationId = null): void
    {
        Log::error("[FlowRuntimeEngine] [{$correlationId}] Error en ejecución {$execution->id}: [{$code}] {$reason}");

        $execution->update([
            'status' => FlowExecutionStatus::FAILED,
            'failed_at' => now(),
            'failure_code' => $code,
        ]);

        $conversation = $execution->conversation;
        $isSimulation = (bool) ($conversation?->is_simulation ?? false);

        if ($conversation) {
            if (!$isSimulation) {
                $conversation->update([
                    'status' => ConversationStatus::WAITING_AGENT,
                ]);
                app(\App\Services\Routing\ConversationAssignmentService::class)->autoAssign($conversation);
            }

            // Create internal system message (safe for contact and human agents)
            Message::create([
                'conversation_id' => $conversation->id,
                'flow_execution_id' => $execution->id,
                'sender_type' => MessageSenderType::SYSTEM,
                'sender_id' => null,
                'direction' => MessageDirection::OUTBOUND,
                'message_type' => MessageType::SYSTEM,
                'visibility' => 'internal',
                'content' => $isSimulation
                    ? "Error en la simulación: [{$code}] {$reason}"
                    : 'Error en el flujo automatizado. La conversación fue transferida a atención humana.',
                'metadata' => [
                    'failure_code' => $code,
                    'execution_id' => $execution->id,
                    'is_simulation' => $isSimulation,
                    'correlation_id' => $correlationId,
                ],
                'sent_at' => now(),
            ]);

            $conversation->update(['last_message_at' => now()]);
        }

        event(new FlowExecutionFailed($execution, $code, $reason));
    }
}
