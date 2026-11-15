<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ChannelType;
use App\Enums\FlowNodeType;
use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Http\Controllers\Controller;
use App\Jobs\PrewarmFlowMediaJob;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowExecution;
use App\Models\FlowNode;
use App\Models\FlowPublication;
use App\Models\FlowSimulationSession;
use App\Models\FlowVersion;
use App\Services\AuditLogger;
use App\Services\FlowDiffService;
use App\Services\FlowValidator;
use App\Services\Media\AttachmentUploadService;
use App\Services\Runtime\FlowGraphCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FlowController extends Controller
{
    public function __construct(
        protected FlowValidator $flowValidator,
        protected FlowDiffService $flowDiffService,
        protected FlowGraphCacheService $graphCache
    ) {}

    public function index(): JsonResponse
    {
        $flows = Flow::with(['activeVersion', 'creator:id,name', 'updater:id,name'])
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json([
            'data' => $flows->map(fn ($flow) => [
                'id' => $flow->id,
                'name' => $flow->name,
                'description' => $flow->description,
                'status' => $flow->status->value,
                'channel_applicability' => $flow->channel_applicability,
                'active_version_id' => $flow->active_version_id,
                'active_version_number' => $flow->activeVersion?->version_number,
                'created_by' => $flow->creator?->name,
                'updated_by' => $flow->updater?->name,
                'created_at' => $flow->created_at->toIso8601String(),
                'updated_at' => $flow->updated_at->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'channel_applicability' => ['nullable', 'array', 'min:1'],
            'channel_applicability.*' => ['string', Rule::in(ChannelType::supportedTypes())],
        ]);

        $user = $request->user();

        $flow = DB::transaction(function () use ($validated, $user) {
            $applicability = ! empty($validated['channel_applicability'])
                ? array_values(array_unique($validated['channel_applicability']))
                : ['webchat'];

            $flow = Flow::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'status' => FlowStatus::DRAFT,
                'channel_applicability' => $applicability,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            // Create initial version 1
            $version = FlowVersion::create([
                'flow_id' => $flow->id,
                'version_number' => 1,
                'status' => FlowVersionStatus::DRAFT,
                'description' => 'Versión inicial',
            ]);

            // Add default start node
            FlowNode::create([
                'flow_version_id' => $version->id,
                'node_key' => 'node_start',
                'type' => FlowNodeType::START,
                'name' => 'Inicio del Flujo',
                'configuration' => [],
                'position_x' => 100,
                'position_y' => 100,
            ]);

            return $flow;
        });

        AuditLogger::log(
            action: 'flow.create',
            entityType: 'Flow',
            entityId: (string) $flow->id,
            metadata: ['name' => $flow->name]
        );

        return response()->json([
            'message' => 'Flujo creado exitosamente con versión inicial en borrador.',
            'data' => [
                'id' => $flow->id,
                'name' => $flow->name,
                'status' => $flow->status->value,
                'channel_applicability' => $flow->channel_applicability,
            ],
        ], 201);
    }

    public function show(Flow $flow): JsonResponse
    {
        $flow->load([
            'activeVersion',
            'versions.publisher:id,name',
            'creator:id,name',
            'updater:id,name',
            'publications.publisher:id,name',
            'publications.version:id,version_number',
            'publications.previousVersion:id,version_number',
        ]);

        return response()->json([
            'data' => [
                'id' => $flow->id,
                'name' => $flow->name,
                'description' => $flow->description,
                'status' => $flow->status->value,
                'channel_applicability' => $flow->channel_applicability,
                'active_version_id' => $flow->active_version_id,
                'active_version_number' => $flow->activeVersion?->version_number,
                'creator' => $flow->creator?->name,
                'updater' => $flow->updater?->name,
                'versions' => $flow->versions->map(fn ($v) => [
                    'id' => $v->id,
                    'version_number' => $v->version_number,
                    'status' => $v->status->value,
                    'is_active' => $flow->active_version_id === $v->id,
                    'description' => $v->description,
                    'published_at' => $v->published_at?->toIso8601String(),
                    'published_by' => $v->publisher?->name,
                    'created_at' => $v->created_at->toIso8601String(),
                    'nodes_count' => $v->nodes()->count(),
                    'edges_count' => $v->edges()->count(),
                ]),
                'publications' => $flow->publications->map(fn ($p) => [
                    'id' => $p->id,
                    'action' => $p->action,
                    'flow_version_id' => $p->flow_version_id,
                    'version_number' => $p->version?->version_number,
                    'previous_version_id' => $p->previous_version_id,
                    'previous_version_number' => $p->previousVersion?->version_number,
                    'published_by' => $p->publisher?->name,
                    'notes' => $p->notes,
                    'created_at' => $p->created_at->toIso8601String(),
                ]),
                'created_at' => $flow->created_at->toIso8601String(),
                'updated_at' => $flow->updated_at->toIso8601String(),
            ],
        ]);
    }

    public function listVersions(Flow $flow): JsonResponse
    {
        $flow->load([
            'versions.publisher:id,name',
            'publications.publisher:id,name',
            'publications.version:id,version_number',
            'publications.previousVersion:id,version_number',
        ]);

        return response()->json([
            'data' => $flow->versions->map(fn ($v) => [
                'id' => $v->id,
                'flow_id' => $v->flow_id,
                'version_number' => $v->version_number,
                'status' => $v->status->value,
                'is_active' => $flow->active_version_id === $v->id,
                'description' => $v->description,
                'published_at' => $v->published_at?->toIso8601String(),
                'published_by' => $v->publisher?->name,
                'created_at' => $v->created_at->toIso8601String(),
                'updated_at' => $v->updated_at->toIso8601String(),
                'nodes_count' => $v->nodes()->count(),
                'edges_count' => $v->edges()->count(),
            ]),
            'active_version_id' => $flow->active_version_id,
            'publications' => $flow->publications->map(fn ($p) => [
                'id' => $p->id,
                'action' => $p->action,
                'flow_version_id' => $p->flow_version_id,
                'version_number' => $p->version?->version_number,
                'previous_version_id' => $p->previous_version_id,
                'previous_version_number' => $p->previousVersion?->version_number,
                'published_by' => $p->publisher?->name,
                'notes' => $p->notes,
                'created_at' => $p->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function update(Request $request, Flow $flow): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'channel_applicability' => ['nullable', 'array', 'min:1'],
            'channel_applicability.*' => ['string', Rule::in(ChannelType::supportedTypes())],
        ]);

        if (array_key_exists('channel_applicability', $validated)) {
            $newApplicability = ! empty($validated['channel_applicability'])
                ? array_values(array_unique($validated['channel_applicability']))
                : ['webchat'];

            // Verify if any active channel currently relies on this flow with a removed channel type
            $blockingChannels = Channel::where('entry_flow_id', $flow->id)->get();
            $incompatibleChannels = $blockingChannels->filter(function ($ch) use ($newApplicability) {
                $type = is_object($ch->type) ? $ch->type->value : $ch->type;

                return ! in_array($type, $newApplicability, true);
            });

            if ($incompatibleChannels->isNotEmpty()) {
                $names = $incompatibleChannels->pluck('name')->implode(', ');

                return response()->json([
                    'message' => "No se puede actualizar la aplicabilidad: este flujo está asignado como flujo de entrada a los siguientes canales que requieren canales removidos: {$names}.",
                    'errors' => [
                        'channel_applicability' => ["Conflicto con canales vinculados: {$names}."],
                    ],
                ], 422);
            }

            $validated['channel_applicability'] = $newApplicability;
        }

        $flow->update(array_merge($validated, [
            'updated_by' => $request->user()->id,
        ]));

        AuditLogger::log(
            action: 'flow.update',
            entityType: 'Flow',
            entityId: (string) $flow->id,
            metadata: ['name' => $flow->name]
        );

        return response()->json([
            'message' => 'Flujo actualizado correctamente.',
            'data' => [
                'id' => $flow->id,
                'name' => $flow->name,
                'description' => $flow->description,
                'channel_applicability' => $flow->channel_applicability,
            ],
        ]);
    }

    public function showVersion(Flow $flow, FlowVersion $version): JsonResponse
    {
        if ($version->flow_id !== $flow->id) {
            return response()->json(['message' => 'Versión no encontrada para este flujo.'], 404);
        }

        $version->load(['nodes', 'edges', 'publisher:id,name']);

        return response()->json([
            'data' => [
                'id' => $version->id,
                'flow_id' => $version->flow_id,
                'version_number' => $version->version_number,
                'status' => $version->status->value,
                'is_active' => $flow->active_version_id === $version->id,
                'description' => $version->description,
                'metadata' => $version->metadata ?? [],
                'published_at' => $version->published_at?->toIso8601String(),
                'published_by' => $version->publisher?->name,
                'updated_at' => $version->updated_at?->toIso8601String(),
                'nodes' => $version->nodes->map(fn ($n) => [
                    'id' => $n->id,
                    'node_key' => $n->node_key,
                    'type' => $n->type->value,
                    'name' => $n->name,
                    'configuration' => $n->configuration ?? [],
                    'position_x' => $n->position_x,
                    'position_y' => $n->position_y,
                ]),
                'edges' => $version->edges->map(fn ($e) => [
                    'id' => $e->id,
                    'source_node_key' => $e->source_node_key,
                    'target_node_key' => $e->target_node_key,
                    'condition_handle' => $e->condition_handle,
                    'metadata' => $e->metadata ?? [],
                ]),
            ],
        ]);
    }

    public function createVersion(Request $request, Flow $flow): JsonResponse
    {
        $validated = $request->validate([
            'from_version_id' => ['nullable', 'uuid', 'exists:flow_versions,id'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $nextVersionNumber = ($flow->versions()->max('version_number') ?? 0) + 1;

        $newVersion = DB::transaction(function () use ($flow, $nextVersionNumber, $validated) {
            $newVersion = FlowVersion::create([
                'flow_id' => $flow->id,
                'version_number' => $nextVersionNumber,
                'status' => FlowVersionStatus::DRAFT,
                'description' => $validated['description'] ?? "Borrador de versión {$nextVersionNumber}",
            ]);

            // If requested to clone from an existing version
            if (! empty($validated['from_version_id'])) {
                $sourceVersion = FlowVersion::with(['nodes', 'edges'])->findOrFail($validated['from_version_id']);
                $newVersion->metadata = $sourceVersion->metadata ?? [];
                $newVersion->save();

                foreach ($sourceVersion->nodes as $node) {
                    FlowNode::create([
                        'flow_version_id' => $newVersion->id,
                        'node_key' => $node->node_key,
                        'type' => $node->type,
                        'name' => $node->name,
                        'configuration' => $node->configuration,
                        'position_x' => $node->position_x,
                        'position_y' => $node->position_y,
                    ]);
                }

                foreach ($sourceVersion->edges as $edge) {
                    FlowEdge::create([
                        'flow_version_id' => $newVersion->id,
                        'source_node_key' => $edge->source_node_key,
                        'target_node_key' => $edge->target_node_key,
                        'condition_handle' => $edge->condition_handle,
                        'metadata' => $edge->metadata,
                    ]);
                }
            } else {
                // Otherwise create default start node
                FlowNode::create([
                    'flow_version_id' => $newVersion->id,
                    'node_key' => 'node_start',
                    'type' => FlowNodeType::START,
                    'name' => 'Inicio del Flujo',
                    'configuration' => [],
                    'position_x' => 100,
                    'position_y' => 100,
                ]);
            }

            return $newVersion;
        });

        AuditLogger::log(
            action: 'flow.create_version',
            entityType: 'FlowVersion',
            entityId: (string) $newVersion->id,
            metadata: ['flow_id' => $flow->id, 'version_number' => $newVersion->version_number]
        );

        return response()->json([
            'message' => "Versión {$newVersion->version_number} creada exitosamente.",
            'data' => [
                'id' => $newVersion->id,
                'version_number' => $newVersion->version_number,
                'status' => $newVersion->status->value,
            ],
        ], 201);
    }

    public function saveVersionGraph(Request $request, Flow $flow, FlowVersion $version): JsonResponse
    {
        if ($version->flow_id !== $flow->id) {
            return response()->json(['message' => 'Versión no encontrada para este flujo.'], 404);
        }

        if ($version->status === FlowVersionStatus::PUBLISHED) {
            return response()->json([
                'message' => 'No se puede modificar directamente una versión ya publicada. Cree una nueva versión borrador para continuar editando.',
            ], 422);
        }

        // Optimistic concurrency check
        $expectedUpdatedAt = $request->input('expected_updated_at');
        if (! empty($expectedUpdatedAt) && $version->updated_at) {
            if ($version->updated_at->toIso8601String() !== $expectedUpdatedAt) {
                return response()->json([
                    'message' => 'Este flujo fue modificado en otra sesión o por otro usuario. Recarga para obtener la versión más reciente.',
                    'conflict' => true,
                ], 409);
            }
        }

        $validated = $request->validate([
            'expected_updated_at' => ['nullable', 'string'],
            'nodes' => ['required', 'array', 'min:1'],
            'nodes.*.node_key' => ['required', 'string', 'max:50'],
            'nodes.*.type' => ['required', 'string', Rule::in(FlowNodeType::values())],
            'nodes.*.name' => ['required', 'string', 'max:255'],
            'nodes.*.configuration' => ['nullable', 'array'],
            'nodes.*.position_x' => ['nullable', 'numeric'],
            'nodes.*.position_y' => ['nullable', 'numeric'],
            'edges' => ['present', 'array'],
            'edges.*.source_node_key' => ['required', 'string', 'max:50'],
            'edges.*.target_node_key' => ['required', 'string', 'max:50'],
            'edges.*.condition_handle' => ['nullable', 'string', 'max:50'],
            'edges.*.sourceHandle' => ['nullable', 'string', 'max:50'],
            'edges.*.source_handle' => ['nullable', 'string', 'max:50'],
            'edges.*.metadata' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ]);

        // Validate node keys are unique in payload
        $nodeKeys = collect($validated['nodes'])->pluck('node_key')->all();
        if (count($nodeKeys) !== count(array_unique($nodeKeys))) {
            return response()->json([
                'message' => 'El grafo contiene identificadores de nodo (node_key) duplicados.',
            ], 422);
        }

        $nodeKeySet = array_flip($nodeKeys);

        // Validate edges refer exclusively to nodes in this graph payload
        foreach ($validated['edges'] as $edge) {
            if (! isset($nodeKeySet[$edge['source_node_key']]) || ! isset($nodeKeySet[$edge['target_node_key']])) {
                return response()->json([
                    'message' => 'Las conexiones contienen referencias a nodos inexistentes o de otra versión.',
                ], 422);
            }
        }

        DB::transaction(function () use ($version, $validated) {
            // Replace existing nodes and edges in draft version
            $version->edges()->delete();
            $version->nodes()->delete();

            foreach ($validated['nodes'] as $nodeData) {
                FlowNode::create([
                    'flow_version_id' => $version->id,
                    'node_key' => $nodeData['node_key'],
                    'type' => $nodeData['type'],
                    'name' => $nodeData['name'],
                    'configuration' => $nodeData['configuration'] ?? [],
                    'position_x' => $nodeData['position_x'] ?? 0,
                    'position_y' => $nodeData['position_y'] ?? 0,
                ]);
            }

            foreach ($validated['edges'] as $edgeData) {
                $rawHandle = $edgeData['condition_handle'] ?? ($edgeData['sourceHandle'] ?? ($edgeData['source_handle'] ?? null));
                $handle = $rawHandle !== null ? (in_array(strtolower(trim((string) $rawHandle)), ['true', 'false'], true) ? strtolower(trim((string) $rawHandle)) : trim((string) $rawHandle)) : null;

                FlowEdge::create([
                    'flow_version_id' => $version->id,
                    'source_node_key' => $edgeData['source_node_key'],
                    'target_node_key' => $edgeData['target_node_key'],
                    'condition_handle' => $handle,
                    'metadata' => $edgeData['metadata'] ?? [],
                ]);
            }

            if (array_key_exists('metadata', $validated)) {
                $version->metadata = $validated['metadata'] ?? [];
            }

            $version->touch();
            $version->save();
        });

        $this->graphCache->invalidate($version->id);
        $version->refresh();

        AuditLogger::log(
            action: 'flow.save_graph',
            entityType: 'FlowVersion',
            entityId: (string) $version->id,
            metadata: ['node_count' => count($validated['nodes']), 'edge_count' => count($validated['edges'])]
        );

        return response()->json([
            'message' => 'Estructura de la versión guardada correctamente.',
            'data' => [
                'updated_at' => $version->updated_at?->toIso8601String(),
            ],
        ]);
    }

    public function validateVersion(Flow $flow, FlowVersion $version): JsonResponse
    {
        if ($version->flow_id !== $flow->id) {
            return response()->json(['message' => 'Versión no encontrada para este flujo.'], 404);
        }

        $validation = $this->flowValidator->validate($version);

        return response()->json([
            'valid' => $validation['is_valid'],
            'is_valid' => $validation['is_valid'],
            'errors' => $validation['errors'],
            'structured_errors' => $validation['structured_errors'] ?? [],
            'warnings' => $validation['warnings'] ?? [],
            'infos' => $validation['infos'] ?? [],
        ]);
    }

    public function publishVersion(Request $request, Flow $flow, FlowVersion $version): JsonResponse
    {
        if ($version->flow_id !== $flow->id) {
            return response()->json(['message' => 'Versión no encontrada para este flujo.'], 404);
        }

        // Validate structural integrity before entering lock
        $validation = $this->flowValidator->validate($version);
        if (! $validation['is_valid']) {
            AuditLogger::log(
                action: 'flow.publish_failed',
                entityType: 'Flow',
                entityId: (string) $flow->id,
                metadata: [
                    'version_id' => $version->id,
                    'version_number' => $version->version_number,
                    'errors' => $validation['errors'],
                ]
            );

            return response()->json([
                'message' => 'El flujo no cumple con los requisitos estructurales para publicación.',
                'is_valid' => false,
                'valid' => false,
                'errors' => $validation['errors'],
                'structured_errors' => $validation['structured_errors'] ?? [],
                'warnings' => $validation['warnings'] ?? [],
                'infos' => $validation['infos'] ?? [],
            ], 422);
        }

        $user = $request->user();
        $notes = $request->input('notes');

        $result = DB::transaction(function () use ($flow, $version, $user, $notes) {
            /** @var Flow $lockedFlow */
            $lockedFlow = Flow::where('id', $flow->id)->lockForUpdate()->first();
            /** @var FlowVersion $lockedVersion */
            $lockedVersion = FlowVersion::where('id', $version->id)->lockForUpdate()->first();

            // Idempotency: if already the active published version
            if ($lockedFlow->active_version_id === $lockedVersion->id && $lockedVersion->status === FlowVersionStatus::PUBLISHED) {
                return [
                    'idempotent' => true,
                    'previous_id' => $lockedFlow->active_version_id,
                ];
            }

            $previousActiveId = $lockedFlow->active_version_id;

            $lockedVersion->update([
                'status' => FlowVersionStatus::PUBLISHED,
                'published_at' => now(),
                'published_by' => $user->id,
            ]);

            $lockedFlow->update([
                'status' => FlowStatus::PUBLISHED,
                'active_version_id' => $lockedVersion->id,
                'updated_by' => $user->id,
            ]);

            FlowPublication::create([
                'flow_id' => $lockedFlow->id,
                'flow_version_id' => $lockedVersion->id,
                'action' => 'publish',
                'previous_version_id' => $previousActiveId,
                'published_by' => $user->id,
                'notes' => $notes ?? "Publicación de versión {$lockedVersion->version_number}",
                'metadata' => [
                    'version_number' => $lockedVersion->version_number,
                    'nodes_count' => $lockedVersion->nodes()->count(),
                    'edges_count' => $lockedVersion->edges()->count(),
                ],
                'created_at' => now(),
            ]);

            return [
                'idempotent' => false,
                'previous_id' => $previousActiveId,
            ];
        });

        $this->graphCache->invalidate($version->id);
        if (! empty($result['previous_id'])) {
            $this->graphCache->invalidate($result['previous_id']);
        }

        AuditLogger::log(
            action: 'flow.publish',
            entityType: 'Flow',
            entityId: (string) $flow->id,
            metadata: [
                'version_id' => $version->id,
                'version_number' => $version->version_number,
                'previous_version_id' => $result['previous_id'],
                'idempotent' => $result['idempotent'],
            ]
        );

        // Pre-warm static media attachments in background across all applicable WhatsApp channels
        PrewarmFlowMediaJob::dispatch($flow->id, $version->id);

        $msg = $result['idempotent']
            ? "La versión {$version->version_number} ya se encuentra publicada y activa."
            : "Versión {$version->version_number} publicada exitosamente.";

        return response()->json([
            'message' => $msg,
            'data' => [
                'flow_id' => $flow->id,
                'active_version_id' => $version->id,
                'version_number' => $version->version_number,
                'status' => FlowStatus::PUBLISHED->value,
                'previous_version_id' => $result['previous_id'],
            ],
        ]);
    }

    public function rollbackVersion(Request $request, Flow $flow, FlowVersion $version): JsonResponse
    {
        if ($version->flow_id !== $flow->id) {
            return response()->json(['message' => 'Versión no encontrada para este flujo.'], 404);
        }

        if ($version->status !== FlowVersionStatus::PUBLISHED) {
            return response()->json([
                'message' => 'Solo se puede realizar rollback hacia una versión previamente publicada e inmutable.',
            ], 422);
        }

        $user = $request->user();
        $notes = $request->input('notes');

        $result = DB::transaction(function () use ($flow, $version, $user, $notes) {
            /** @var Flow $lockedFlow */
            $lockedFlow = Flow::where('id', $flow->id)->lockForUpdate()->first();
            /** @var FlowVersion $lockedVersion */
            $lockedVersion = FlowVersion::where('id', $version->id)->lockForUpdate()->first();

            // Idempotency: if already the active version
            if ($lockedFlow->active_version_id === $lockedVersion->id) {
                return [
                    'idempotent' => true,
                    'previous_id' => $lockedFlow->active_version_id,
                ];
            }

            $previousId = $lockedFlow->active_version_id;

            $lockedFlow->update([
                'status' => FlowStatus::PUBLISHED,
                'active_version_id' => $lockedVersion->id,
                'updated_by' => $user->id,
            ]);

            FlowPublication::create([
                'flow_id' => $lockedFlow->id,
                'flow_version_id' => $lockedVersion->id,
                'action' => 'rollback',
                'previous_version_id' => $previousId,
                'published_by' => $user->id,
                'notes' => $notes ?? "Rollback hacia la versión {$lockedVersion->version_number}",
                'metadata' => [
                    'from_version_id' => $previousId,
                    'to_version_id' => $lockedVersion->id,
                    'to_version_number' => $lockedVersion->version_number,
                ],
                'created_at' => now(),
            ]);

            return [
                'idempotent' => false,
                'previous_id' => $previousId,
            ];
        });

        $this->graphCache->invalidate($version->id);
        if (! empty($result['previous_id'])) {
            $this->graphCache->invalidate($result['previous_id']);
        }

        AuditLogger::log(
            action: 'flow.rollback',
            entityType: 'Flow',
            entityId: (string) $flow->id,
            metadata: [
                'from_version_id' => $result['previous_id'],
                'to_version_id' => $version->id,
                'to_version_number' => $version->version_number,
                'idempotent' => $result['idempotent'],
            ]
        );

        // Pre-warm static media attachments in background across all applicable WhatsApp channels
        PrewarmFlowMediaJob::dispatch($flow->id, $version->id);

        $msg = $result['idempotent']
            ? "La versión {$version->version_number} ya es la versión activa del flujo."
            : "Flujo restaurado exitosamente a la versión {$version->version_number}.";

        return response()->json([
            'message' => $msg,
            'data' => [
                'flow_id' => $flow->id,
                'active_version_id' => $version->id,
                'active_version_number' => $version->version_number,
                'status' => FlowStatus::PUBLISHED->value,
                'previous_version_id' => $result['previous_id'],
            ],
        ]);
    }

    public function compareVersions(Flow $flow, FlowVersion $version, FlowVersion $targetVersion): JsonResponse
    {
        if ($version->flow_id !== $flow->id || $targetVersion->flow_id !== $flow->id) {
            return response()->json(['message' => 'Una o ambas versiones no corresponden a este flujo.'], 404);
        }

        $comparison = $this->flowDiffService->compare($version, $targetVersion);

        return response()->json([
            'data' => $comparison,
        ]);
    }

    public function destroyVersion(Flow $flow, FlowVersion $version): JsonResponse
    {
        if ($version->flow_id !== $flow->id) {
            return response()->json(['message' => 'Versión no encontrada para este flujo.'], 404);
        }

        if ($flow->active_version_id === $version->id) {
            return response()->json([
                'message' => 'No se puede eliminar la versión activa en producción.',
            ], 422);
        }

        if ($version->status === FlowVersionStatus::PUBLISHED) {
            return response()->json([
                'message' => 'Las versiones publicadas son inmutables y forman parte del historial permanente; no pueden eliminarse.',
            ], 422);
        }

        if ($version->conversations()->exists() || $version->executions()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar una versión vinculada a conversaciones o ejecuciones históricas.',
            ], 422);
        }

        DB::transaction(function () use ($version) {
            $version->edges()->delete();
            $version->nodes()->delete();
            $version->delete();
        });

        $this->graphCache->invalidate($version->id);

        AuditLogger::log(
            action: 'flow.delete_version',
            entityType: 'FlowVersion',
            entityId: (string) $version->id,
            metadata: ['flow_id' => $flow->id, 'version_number' => $version->version_number]
        );

        return response()->json([
            'message' => "Borrador de versión {$version->version_number} eliminado correctamente.",
        ]);
    }

    public function destroy(Request $request, Flow $flow): JsonResponse
    {
        $blockingChannels = Channel::where('entry_flow_id', $flow->id)->get(['id', 'name']);
        if ($blockingChannels->isNotEmpty()) {
            $channelNames = $blockingChannels->pluck('name')->implode(', ');

            return response()->json([
                'message' => "No se puede eliminar este flujo porque está configurado como flujo de entrada en los siguientes canales: {$channelNames}. Cambia o desvincula el flujo en esos canales primero.",
                'blocking_channels' => $blockingChannels->pluck('name')->all(),
            ], 422);
        }

        $versionIds = $flow->versions()->pluck('id');
        $conversationsCount = Conversation::real()
            ->where(function ($query) use ($flow, $versionIds) {
                $query->where('active_flow_id', $flow->id)
                    ->orWhereIn('active_flow_version_id', $versionIds);
            })
            ->count();

        $force = $request->boolean('force');

        if ($conversationsCount > 0 && ! $force) {
            return response()->json([
                'message' => "El flujo tiene {$conversationsCount} conversación(es) activa(s) o asociada(s). Confirma la eliminación forzada para continuar.",
                'requires_force' => true,
                'conversations_count' => $conversationsCount,
            ], 422);
        }

        $flowId = $flow->id;
        $flowName = $flow->name;
        $versionsCount = $versionIds->count();

        DB::transaction(function () use ($flow, $versionIds, $force) {
            if ($force) {
                Conversation::where('active_flow_id', $flow->id)
                    ->orWhereIn('active_flow_version_id', $versionIds)
                    ->update([
                        'active_flow_id' => null,
                        'active_flow_version_id' => null,
                    ]);
            }

            // Break circular FK with active version
            $flow->update(['active_version_id' => null]);

            FlowSimulationSession::where('flow_id', $flow->id)->delete();
            Conversation::where('is_simulation', true)
                ->where(function ($q) use ($flow, $versionIds) {
                    $q->where('active_flow_id', $flow->id)
                        ->orWhereIn('active_flow_version_id', $versionIds);
                })
                ->delete();

            FlowExecution::where('flow_id', $flow->id)->delete();
            FlowPublication::where('flow_id', $flow->id)->delete();

            if ($versionIds->isNotEmpty()) {
                FlowEdge::whereIn('flow_version_id', $versionIds)->delete();
                FlowNode::whereIn('flow_version_id', $versionIds)->delete();
                FlowVersion::whereIn('id', $versionIds)->delete();
            }

            $flow->delete();
        });

        foreach ($versionIds as $vId) {
            $this->graphCache->invalidate($vId);
        }

        AuditLogger::log(
            action: 'flow.deleted',
            entityType: 'Flow',
            entityId: (string) $flowId,
            metadata: [
                'name' => $flowName,
                'versions_count' => $versionsCount,
                'forced' => $force,
            ]
        );

        return response()->json([
            'message' => "Flujo '{$flowName}' eliminado exitosamente.",
        ]);
    }

    public function uploadAttachment(Request $request, Flow $flow): JsonResponse
    {
        $request->validate([
            'file' => ['required_without_all:attachment,image', 'nullable', 'file', 'max:10240'],
            'attachment' => ['required_without_all:file,image', 'nullable', 'file', 'max:10240'],
            'image' => ['required_without_all:file,attachment', 'nullable', 'file', 'max:10240'],
        ]);

        $file = $request->file('file') ?? $request->file('attachment') ?? $request->file('image');

        if (! $file) {
            return response()->json([
                'message' => 'No se proporcionó ningún archivo válido.',
            ], 422);
        }

        $upload = app(AttachmentUploadService::class)->store($file, 'flows/'.$flow->id);
        $attachment = $upload['attachment'];

        return response()->json([
            'url' => $attachment['url'],
            'file_name' => $attachment['file_name'],
            'mime_type' => $attachment['mime_type'],
            'size_bytes' => $attachment['size_bytes'],
            'message_type' => $upload['message_type']->value,
            'data' => [
                'url' => $attachment['url'],
                'file_name' => $attachment['file_name'],
                'mime_type' => $attachment['mime_type'],
                'size_bytes' => $attachment['size_bytes'],
            ],
        ], 201);
    }
}
