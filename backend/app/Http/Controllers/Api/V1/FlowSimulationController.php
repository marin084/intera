<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\FlowExecutionStatus;
use App\Enums\FlowNodeType;
use App\Enums\FlowVersionStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageType;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowExecution;
use App\Models\FlowExecutionStep;
use App\Models\FlowNode;
use App\Models\FlowSimulationSession;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Services\Runtime\FlowRuntimeEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FlowSimulationController extends Controller
{
    public function __construct(
        protected FlowRuntimeEngine $runtimeEngine
    ) {}

    /**
     * Inicia una sesión aislada de simulación sobre una versión de flujo (borrador o publicada).
     */
    public function start(Request $request, Flow $flow, FlowVersion $version): JsonResponse
    {
        if ($version->flow_id !== $flow->id) {
            return response()->json(['message' => 'Versión no encontrada para este flujo.'], 404);
        }

        $user = $request->user();

        // Si se enviaron cambios no guardados del borrador, guardarlos atómicamente antes de simular
        if ($request->has('save_draft_graph') && $version->status === FlowVersionStatus::DRAFT) {
            $this->saveDraftGraphIfProvided($version, $request->input('save_draft_graph'));
        }

        $version->loadMissing(['nodes', 'edges']);

        $startNode = $version->nodes->firstWhere('type', FlowNodeType::START);
        if (! $startNode) {
            return response()->json([
                'message' => 'No se puede simular: el flujo no contiene un nodo de inicio (Start).',
                'error_code' => 'MISSING_START_NODE',
            ], 422);
        }

        $initialContext = $request->input('context', []);
        if (! is_array($initialContext)) {
            $initialContext = [];
        }

        // Canal y contacto aislados para simulación (claramente delimitados de producción)
        $channel = Channel::firstOrCreate(
            ['name' => 'Canal de Simulación'],
            [
                'type' => ChannelType::WEBCHAT,
                'is_active' => false, // Inactivo para no recibir tráfico público
                'configuration' => ['is_simulator_channel' => true],
            ]
        );

        $contact = Contact::firstOrCreate(
            ['external_identifier' => 'sim_user_'.($user?->id ?? 'guest')],
            [
                'display_name' => 'Usuario Simulador ('.($user?->name ?? 'Pruebas').')',
                'email' => 'simulador_'.($user?->id ?? '0').'@conexa.local',
            ]
        );

        $simulationData = DB::transaction(function () use ($version, $flow, $channel, $contact, $user, $initialContext) {
            // Conversación marcada estrictamente con is_simulation = true
            $conversation = Conversation::create([
                'channel_id' => $channel->id,
                'contact_id' => $contact->id,
                'status' => ConversationStatus::BOT,
                'is_simulation' => true,
                'active_flow_id' => $flow->id,
                'active_flow_version_id' => $version->id,
                'started_at' => now(),
            ]);

            $execution = $this->runtimeEngine->start($conversation, $version);

            // Fusionar variables iniciales si fueron provistas
            if (! empty($initialContext)) {
                $currContext = $execution->context ?? [];
                $execution->context = array_merge($currContext, $initialContext);
                $execution->save();
            }

            // Registrar sesión de simulación
            $session = FlowSimulationSession::create([
                'user_id' => $user->id,
                'flow_id' => $flow->id,
                'flow_version_id' => $version->id,
                'conversation_id' => $conversation->id,
                'flow_execution_id' => $execution->id,
                'status' => $execution->status->value,
                'current_node_key' => $execution->currentNode?->node_key,
                'context' => $execution->context ?? [],
                'last_activity_at' => now(),
            ]);

            return $this->formatSimulationState($execution, $conversation, $version, $session);
        });

        return response()->json([
            'message' => 'Simulación iniciada exitosamente.',
            'data' => $simulationData,
        ]);
    }

    /**
     * Procesa una respuesta de usuario o selección interactiva en la simulación activa.
     */
    public function step(Request $request, Flow $flow, FlowVersion $version): JsonResponse
    {
        if ($version->flow_id !== $flow->id) {
            return response()->json(['message' => 'Versión no encontrada para este flujo.'], 404);
        }

        $validated = $request->validate([
            'execution_id' => ['required', 'uuid', 'exists:flow_executions,id'],
            'message' => ['required', 'string'],
        ]);

        /** @var FlowExecution $execution */
        $execution = FlowExecution::where('id', $validated['execution_id'])
            ->where('flow_version_id', $version->id)
            ->firstOrFail();

        $user = $request->user();

        // Verificar sesión y autorización de aislamiento
        $session = FlowSimulationSession::where('flow_execution_id', $execution->id)->first();
        if ($session && $session->user_id !== $user->id && ! $user->hasPermission('flows.edit')) {
            return response()->json(['message' => 'No autorizado para interactuar con esta simulación.'], 403);
        }

        $conversation = $execution->conversation;
        if (! $conversation) {
            return response()->json(['message' => 'Conversación asociada no encontrada.'], 404);
        }

        // Impedir avances en simulaciones finalizadas o fallidas
        if (in_array($execution->status, [FlowExecutionStatus::COMPLETED, FlowExecutionStatus::FAILED, FlowExecutionStatus::CANCELLED], true)) {
            return response()->json([
                'message' => "La simulación ya finalizó ({$execution->status->value}). Reiníciala para probar nuevamente.",
                'data' => $this->formatSimulationState($execution, $conversation, $version, $session),
            ], 422);
        }

        $simulationData = DB::transaction(function () use ($execution, $conversation, $version, $session, $validated) {
            // Mensaje entrante del contacto simulador
            $inboundMessage = Message::create([
                'conversation_id' => $conversation->id,
                'flow_execution_id' => $execution->id,
                'sender_type' => MessageSenderType::CONTACT,
                'sender_id' => null,
                'direction' => MessageDirection::INBOUND,
                'message_type' => MessageType::TEXT,
                'content' => $validated['message'],
                'sent_at' => now(),
            ]);

            $conversation->update(['last_message_at' => now()]);

            $updatedExecution = $this->runtimeEngine->resumeWithInboundMessage($conversation, $inboundMessage);
            $effectiveExecution = $updatedExecution ?? $execution;
            $effectiveExecution->refresh();

            if ($session) {
                $session->update([
                    'status' => $effectiveExecution->status->value,
                    'current_node_key' => $effectiveExecution->currentNode?->node_key,
                    'context' => $effectiveExecution->context ?? [],
                    'last_activity_at' => now(),
                    'completed_at' => in_array($effectiveExecution->status, [FlowExecutionStatus::COMPLETED, FlowExecutionStatus::FAILED], true) ? now() : null,
                ]);
            }

            return $this->formatSimulationState($effectiveExecution, $conversation, $version, $session);
        });

        return response()->json([
            'message' => 'Paso de simulación procesado exitosamente.',
            'data' => $simulationData,
        ]);
    }

    /**
     * Consulta el estado actual de una simulación.
     */
    public function show(Request $request, Flow $flow, FlowVersion $version, string $execution): JsonResponse
    {
        if ($version->flow_id !== $flow->id) {
            return response()->json(['message' => 'Versión no encontrada para este flujo.'], 404);
        }

        /** @var FlowExecution $executionModel */
        $executionModel = FlowExecution::where('id', $execution)
            ->where('flow_version_id', $version->id)
            ->firstOrFail();

        $session = FlowSimulationSession::where('flow_execution_id', $executionModel->id)->first();
        $conversation = $executionModel->conversation;

        return response()->json([
            'data' => $this->formatSimulationState($executionModel, $conversation, $version, $session),
        ]);
    }

    /**
     * Reinicia la simulación: cancela la anterior e inicia una nueva limpia sin herencia.
     */
    public function reset(Request $request, Flow $flow, FlowVersion $version): JsonResponse
    {
        if ($version->flow_id !== $flow->id) {
            return response()->json(['message' => 'Versión no encontrada para este flujo.'], 404);
        }

        $prevExecutionId = $request->input('execution_id');
        if (! empty($prevExecutionId)) {
            $prevExecution = FlowExecution::where('id', $prevExecutionId)->first();
            if ($prevExecution && ! in_array($prevExecution->status, [FlowExecutionStatus::COMPLETED, FlowExecutionStatus::FAILED], true)) {
                $prevExecution->update([
                    'status' => FlowExecutionStatus::CANCELLED,
                    'completed_at' => now(),
                ]);
            }
            if ($prevSession = FlowSimulationSession::where('flow_execution_id', $prevExecutionId)->first()) {
                $prevSession->update(['status' => 'cancelled', 'completed_at' => now()]);
            }
        }

        // Inicia una nueva simulación fresca
        return $this->start($request, $flow, $version);
    }

    /**
     * Detiene / Cancela una simulación activa.
     */
    public function stop(Request $request, Flow $flow, FlowVersion $version): JsonResponse
    {
        if ($version->flow_id !== $flow->id) {
            return response()->json(['message' => 'Versión no encontrada para este flujo.'], 404);
        }

        $validated = $request->validate([
            'execution_id' => ['required', 'uuid', 'exists:flow_executions,id'],
        ]);

        /** @var FlowExecution $execution */
        $execution = FlowExecution::where('id', $validated['execution_id'])
            ->where('flow_version_id', $version->id)
            ->firstOrFail();

        $execution->update([
            'status' => FlowExecutionStatus::CANCELLED,
            'completed_at' => now(),
        ]);

        $session = FlowSimulationSession::where('flow_execution_id', $execution->id)->first();
        if ($session) {
            $session->update([
                'status' => 'cancelled',
                'completed_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Simulación detenida.',
            'data' => $this->formatSimulationState($execution, $execution->conversation, $version, $session),
        ]);
    }

    /**
     * Guarda el grafo borrador si el cliente envió cambios pendientes antes de simular.
     */
    protected function saveDraftGraphIfProvided(FlowVersion $version, array $graphData): void
    {
        if (empty($graphData['nodes']) || ! is_array($graphData['nodes'])) {
            return;
        }

        DB::transaction(function () use ($version, $graphData) {
            $version->edges()->delete();
            $version->nodes()->delete();

            foreach ($graphData['nodes'] as $nodeData) {
                FlowNode::create([
                    'flow_version_id' => $version->id,
                    'node_key' => $nodeData['node_key'] ?? $nodeData['id'],
                    'type' => $nodeData['type'],
                    'name' => $nodeData['name'] ?? 'Bloque',
                    'configuration' => $nodeData['configuration'] ?? ($nodeData['data'] ?? []),
                    'position_x' => $nodeData['position_x'] ?? ($nodeData['position']['x'] ?? 0),
                    'position_y' => $nodeData['position_y'] ?? ($nodeData['position']['y'] ?? 0),
                ]);
            }

            if (! empty($graphData['edges']) && is_array($graphData['edges'])) {
                foreach ($graphData['edges'] as $edgeData) {
                    $rawHandle = $edgeData['condition_handle'] ?? ($edgeData['sourceHandle'] ?? ($edgeData['source_handle'] ?? null));
                    $handle = $rawHandle !== null ? (in_array(strtolower(trim((string) $rawHandle)), ['true', 'false'], true) ? strtolower(trim((string) $rawHandle)) : trim((string) $rawHandle)) : null;

                    FlowEdge::create([
                        'flow_version_id' => $version->id,
                        'source_node_key' => $edgeData['source_node_key'] ?? $edgeData['source'],
                        'target_node_key' => $edgeData['target_node_key'] ?? $edgeData['target'],
                        'condition_handle' => $handle,
                        'metadata' => $edgeData['metadata'] ?? [],
                    ]);
                }
            }

            $version->touch();
        });
    }

    /**
     * Da formato consistente al estado de la simulación, enriquecido con inspector y eventos comprensibles.
     */
    protected function formatSimulationState(
        FlowExecution $execution,
        ?Conversation $conversation,
        FlowVersion $version,
        ?FlowSimulationSession $session = null
    ): array {
        $execution->refresh();
        $version->loadMissing('nodes');

        $currentNode = $version->nodes->firstWhere('id', $execution->current_node_id);

        $messages = $conversation ? Message::where('conversation_id', $conversation->id)
            ->orderBy('sent_at', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'sender_type' => $m->sender_type->value,
                'direction' => $m->direction->value,
                'message_type' => $m->message_type->value,
                'content' => $m->content,
                'payload' => $m->payload ?? [],
                'metadata' => $m->metadata,
                'sent_at' => $m->sent_at?->toIso8601String(),
            ])
            ->values()
            ->all() : [];

        $steps = FlowExecutionStep::where('flow_execution_id', $execution->id)
            ->with('flowNode')
            ->orderBy('entered_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $formattedSteps = $steps->map(function ($s, $idx) {
            $nodeKey = $s->flowNode?->node_key ?? ($s->input['node_key'] ?? null);
            $nodeName = $s->flowNode?->name ?? ($s->input['name'] ?? 'Bloque');

            return [
                'id' => $s->id,
                'step_number' => $idx + 1,
                'flow_node_id' => $s->flow_node_id,
                'node_key' => $nodeKey,
                'node_name' => $nodeName,
                'node_type' => $s->node_type,
                'status' => $s->status->value,
                'entered_at' => $s->entered_at?->toIso8601String(),
                'completed_at' => $s->completed_at?->toIso8601String(),
                'input' => $s->input,
                'output' => $s->output,
                'error_code' => $s->error_code,
                'metadata' => $s->metadata,
            ];
        })->values()->all();

        // Generar Historial comprensible de eventos para el Inspector
        $events = $this->buildEventTimeline($execution, $formattedSteps, $messages);

        // Identificar última rama / edge transitado para resaltado en canvas
        $takenEdge = $this->identifyLastTakenEdge($steps->last(), $version);

        return [
            'simulation_id' => $session?->id,
            'execution_id' => $execution->id,
            'conversation_id' => $conversation?->id,
            'flow_id' => $version->flow_id,
            'flow_version_id' => $version->id,
            'version_number' => $version->version_number,
            'version_status' => $version->status->value,
            'status' => $execution->status->value,
            'failure_code' => $execution->failure_code,
            'waiting_since' => $execution->waiting_since?->toIso8601String(),
            'current_node' => $currentNode ? [
                'id' => $currentNode->id,
                'node_key' => $currentNode->node_key,
                'name' => $currentNode->name,
                'type' => $currentNode->type->value,
                'configuration' => $currentNode->configuration,
            ] : null,
            'context' => $execution->context ?? [],
            'messages' => $messages,
            'steps' => $formattedSteps,
            'events' => $events,
            'taken_edge' => $takenEdge,
        ];
    }

    /**
     * Construye un timeline de eventos legible y ordenado para el inspector.
     */
    protected function buildEventTimeline(FlowExecution $execution, array $steps, array $messages): array
    {
        $events = [];
        $order = 1;

        $events[] = [
            'order' => $order++,
            'type' => 'SIMULATION_STARTED',
            'label' => 'Simulación iniciada',
            'status' => 'success',
            'details' => 'Contexto de memoria inicializado.',
            'timestamp' => $execution->started_at?->toIso8601String(),
        ];

        foreach ($steps as $step) {
            $nodeType = strtoupper($step['node_type']);
            $nodeKey = $step['node_key'];
            $nodeName = $step['node_name'];
            $status = $step['status'];

            $label = match ($step['node_type']) {
                'start' => "Nodo inicial ejecutado: {$nodeName}",
                'message' => "Mensaje enviado por el bot ({$nodeKey})",
                'menu' => $status === 'waiting' ? "Menú interactivo presentado: esperando selección ({$nodeKey})" : "Opción de menú resuelta ({$nodeKey})",
                'question' => $status === 'waiting' ? "Pregunta formulada: esperando respuesta ({$nodeKey})" : "Respuesta de pregunta capturada ({$nodeKey})",
                'condition' => "Condición evaluada en {$nodeName}",
                'set_variable' => "Variable actualizada en {$nodeName}",
                'goto' => "Salto Goto ejecutado hacia {$nodeName}",
                'transfer_to_agent' => "Transferencia simulada a agente ({$nodeName})",
                'end' => 'Flujo finalizado (Nodo End alcanzado)',
                'http_request' => "Petición HTTP simulada ({$nodeName})",
                default => "Paso ejecutado: {$nodeType} ({$nodeName})",
            };

            $details = null;
            if ($step['node_type'] === 'condition' && isset($step['output']['evaluation'])) {
                $eval = $step['output']['evaluation'] ? 'TRUE' : 'FALSE';
                $var = $step['output']['variable'] ?? 'desconocida';
                $op = $step['output']['operator'] ?? '==';
                $exp = json_encode($step['output']['expected'] ?? null);
                $act = json_encode($step['output']['actual'] ?? null);
                $details = "Evaluación: {$eval} | {$var} [{$op}] {$exp} (valor actual: {$act})";
            } elseif ($step['node_type'] === 'set_variable' && isset($step['output']['variable'])) {
                $details = "{$step['output']['variable']} = ".json_encode($step['output']['value'] ?? null);
            } elseif ($step['node_type'] === 'menu' && isset($step['output']['selected_option_text'])) {
                $details = "Opción seleccionada: '{$step['output']['selected_option_text']}'";
            } elseif ($step['node_type'] === 'transfer_to_agent') {
                $details = 'El bot transferiría esta conversación a un agente humano en el Inbox.';
            } elseif (! empty($step['error_code'])) {
                $details = "Error: [{$step['error_code']}]";
            }

            $events[] = [
                'order' => $order++,
                'type' => 'STEP_'.$nodeType,
                'node_key' => $nodeKey,
                'node_name' => $nodeName,
                'label' => $label,
                'status' => $status === 'completed' ? 'success' : ($status === 'waiting' ? 'waiting' : 'error'),
                'details' => $details,
                'timestamp' => $step['completed_at'] ?? $step['entered_at'],
            ];
        }

        if ($execution->status === FlowExecutionStatus::COMPLETED) {
            $events[] = [
                'order' => $order++,
                'type' => 'SIMULATION_COMPLETED',
                'label' => 'Simulación finalizada correctamente',
                'status' => 'success',
                'details' => 'No quedan más pasos pendientes.',
                'timestamp' => $execution->completed_at?->toIso8601String(),
            ];
        } elseif ($execution->status === FlowExecutionStatus::FAILED) {
            $events[] = [
                'order' => $order++,
                'type' => 'SIMULATION_FAILED',
                'label' => "Simulación fallida: [{$execution->failure_code}]",
                'status' => 'error',
                'details' => 'Se interrumpió la ejecución del flujo por un error o bucle.',
                'timestamp' => $execution->failed_at?->toIso8601String(),
            ];
        }

        return $events;
    }

    /**
     * Identifica el identificador de la conexión transitada por el último paso para resaltado visual.
     */
    protected function identifyLastTakenEdge(?FlowExecutionStep $lastStep, FlowVersion $version): ?array
    {
        if (! $lastStep || ! $lastStep->flowNode) {
            return null;
        }

        $sourceKey = $lastStep->flowNode->node_key;
        $outgoingHandle = $lastStep->output['outgoing_handle'] ?? ($lastStep->metadata['outgoing_handle'] ?? null);

        $edge = null;
        if ($outgoingHandle) {
            $edge = $version->edges->where('source_node_key', $sourceKey)->firstWhere('condition_handle', $outgoingHandle);
        }
        if (! $edge) {
            $edge = $version->edges->where('source_node_key', $sourceKey)->first();
        }

        if (! $edge) {
            return null;
        }

        return [
            'id' => $edge->id,
            'source_node_key' => $edge->source_node_key,
            'target_node_key' => $edge->target_node_key,
            'condition_handle' => $edge->condition_handle,
        ];
    }
}
