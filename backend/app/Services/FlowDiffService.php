<?php

namespace App\Services;

use App\Models\FlowVersion;

class FlowDiffService
{
    /**
     * Compare two FlowVersion instances and return a detailed semantic diff.
     *
     * @return array{
     *     source_version: array{id: string, version_number: int, status: string},
     *     target_version: array{id: string, version_number: int, status: string},
     *     has_changes: bool,
     *     stats: array{
     *         nodes_added: int,
     *         nodes_removed: int,
     *         nodes_modified: int,
     *         edges_added: int,
     *         edges_removed: int,
     *         variables_changed: int
     *     },
     *     summary: string,
     *     nodes: array{
     *         added: array<array<string, mixed>>,
     *         removed: array<array<string, mixed>>,
     *         modified: array<array<string, mixed>>
     *     },
     *     edges: array{
     *         added: array<array<string, mixed>>,
     *         removed: array<array<string, mixed>>
     *     },
     *     variables: array{
     *         added: array<array<string, mixed>>,
     *         removed: array<array<string, mixed>>,
     *         modified: array<array<string, mixed>>
     *     },
     *     highlights: array<string>
     * }
     */
    public function compare(FlowVersion $sourceVersion, FlowVersion $targetVersion): array
    {
        $sourceVersion->loadMissing(['nodes', 'edges']);
        $targetVersion->loadMissing(['nodes', 'edges']);

        $sourceNodes = $sourceVersion->nodes->keyBy('node_key');
        $targetNodes = $targetVersion->nodes->keyBy('node_key');

        $addedNodes = [];
        $removedNodes = [];
        $modifiedNodes = [];
        $highlights = [];

        // 1. Nodes added in target
        foreach ($targetNodes as $key => $targetNode) {
            if (! $sourceNodes->has($key)) {
                $addedNodes[] = [
                    'node_key' => $key,
                    'name' => $targetNode->name,
                    'type' => $targetNode->type->value,
                    'configuration' => $targetNode->configuration ?? [],
                ];
                $highlights[] = "+ Nuevo nodo [{$targetNode->type->value}]: '{$targetNode->name}' ({$key})";
            }
        }

        // 2. Nodes removed from source
        foreach ($sourceNodes as $key => $sourceNode) {
            if (! $targetNodes->has($key)) {
                $removedNodes[] = [
                    'node_key' => $key,
                    'name' => $sourceNode->name,
                    'type' => $sourceNode->type->value,
                    'configuration' => $sourceNode->configuration ?? [],
                ];
                $highlights[] = "- Nodo eliminado [{$sourceNode->type->value}]: '{$sourceNode->name}' ({$key})";
            }
        }

        // 3. Nodes modified
        foreach ($targetNodes as $key => $targetNode) {
            if ($sourceNodes->has($key)) {
                $sourceNode = $sourceNodes->get($key);
                $diffs = $this->diffNodeSemantics($sourceNode, $targetNode);

                if (! empty($diffs)) {
                    $modifiedNodes[] = [
                        'node_key' => $key,
                        'name' => $targetNode->name,
                        'type' => $targetNode->type->value,
                        'previous_name' => $sourceNode->name,
                        'previous_type' => $sourceNode->type->value,
                        'changes' => $diffs,
                    ];

                    foreach ($diffs as $diffDesc) {
                        $highlights[] = "~ Bloque '{$targetNode->name}': {$diffDesc}";
                    }
                }
            }
        }

        // 4. Edges comparison
        // Represent edge uniquely by source_node_key -> target_node_key (handle)
        $edgeKey = fn ($e) => "{$e->source_node_key}__".($e->condition_handle ?? 'default')."__{$e->target_node_key}";

        $sourceEdges = [];
        foreach ($sourceVersion->edges as $edge) {
            $sourceEdges[$edgeKey($edge)] = $edge;
        }

        $targetEdges = [];
        foreach ($targetVersion->edges as $edge) {
            $targetEdges[$edgeKey($edge)] = $edge;
        }

        $addedEdges = [];
        $removedEdges = [];

        foreach ($targetEdges as $k => $edge) {
            if (! isset($sourceEdges[$k])) {
                $addedEdges[] = [
                    'source_node_key' => $edge->source_node_key,
                    'target_node_key' => $edge->target_node_key,
                    'condition_handle' => $edge->condition_handle,
                ];
            }
        }

        foreach ($sourceEdges as $k => $edge) {
            if (! isset($targetEdges[$k])) {
                $removedEdges[] = [
                    'source_node_key' => $edge->source_node_key,
                    'target_node_key' => $edge->target_node_key,
                    'condition_handle' => $edge->condition_handle,
                ];
            }
        }

        if (count($addedEdges) > 0) {
            $highlights[] = '+ '.count($addedEdges).' '.(count($addedEdges) === 1 ? 'conexión agregada' : 'conexiones agregadas');
        }
        if (count($removedEdges) > 0) {
            $highlights[] = '- '.count($removedEdges).' '.(count($removedEdges) === 1 ? 'conexión eliminada' : 'conexiones eliminadas');
        }

        // 5. Variables comparison from metadata
        $sourceVars = collect($sourceVersion->metadata['variables'] ?? [])->keyBy('name');
        $targetVars = collect($targetVersion->metadata['variables'] ?? [])->keyBy('name');

        $addedVars = [];
        $removedVars = [];
        $modifiedVars = [];

        foreach ($targetVars as $varName => $targetVar) {
            if (! $sourceVars->has($varName)) {
                $addedVars[] = $targetVar;
                $highlights[] = "+ Variable agregada: '{$varName}'";
            } else {
                $sourceVar = $sourceVars->get($varName);
                if (($sourceVar['type'] ?? '') !== ($targetVar['type'] ?? '') ||
                    ($sourceVar['description'] ?? '') !== ($targetVar['description'] ?? '')) {
                    $modifiedVars[] = [
                        'name' => $varName,
                        'before' => $sourceVar,
                        'after' => $targetVar,
                    ];
                    $highlights[] = "~ Variable modificada: '{$varName}'";
                }
            }
        }

        foreach ($sourceVars as $varName => $sourceVar) {
            if (! $targetVars->has($varName)) {
                $removedVars[] = $sourceVar;
                $highlights[] = "- Variable eliminada: '{$varName}'";
            }
        }

        $stats = [
            'nodes_added' => count($addedNodes),
            'nodes_removed' => count($removedNodes),
            'nodes_modified' => count($modifiedNodes),
            'edges_added' => count($addedEdges),
            'edges_removed' => count($removedEdges),
            'variables_changed' => count($addedVars) + count($removedVars) + count($modifiedVars),
        ];

        $hasChanges = $stats['nodes_added'] > 0
            || $stats['nodes_removed'] > 0
            || $stats['nodes_modified'] > 0
            || $stats['edges_added'] > 0
            || $stats['edges_removed'] > 0
            || $stats['variables_changed'] > 0;

        $summaryParts = [];
        if ($stats['nodes_added'] > 0) {
            $summaryParts[] = "+{$stats['nodes_added']} ".($stats['nodes_added'] === 1 ? 'nodo agregado' : 'nodos agregados');
        }
        if ($stats['nodes_removed'] > 0) {
            $summaryParts[] = "-{$stats['nodes_removed']} ".($stats['nodes_removed'] === 1 ? 'nodo eliminado' : 'nodos eliminados');
        }
        if ($stats['nodes_modified'] > 0) {
            $summaryParts[] = "~{$stats['nodes_modified']} ".($stats['nodes_modified'] === 1 ? 'nodo modificado' : 'nodos modificados');
        }
        if ($stats['edges_added'] > 0) {
            $summaryParts[] = "+{$stats['edges_added']} ".($stats['edges_added'] === 1 ? 'conexión' : 'conexiones');
        }
        if ($stats['edges_removed'] > 0) {
            $summaryParts[] = "-{$stats['edges_removed']} ".($stats['edges_removed'] === 1 ? 'conexión' : 'conexiones');
        }

        $summary = ! empty($summaryParts) ? implode(', ', $summaryParts) : 'Sin cambios estructurales ni de contenido';

        return [
            'source_version' => [
                'id' => $sourceVersion->id,
                'version_number' => $sourceVersion->version_number,
                'status' => $sourceVersion->status->value,
            ],
            'target_version' => [
                'id' => $targetVersion->id,
                'version_number' => $targetVersion->version_number,
                'status' => $targetVersion->status->value,
            ],
            'has_changes' => $hasChanges,
            'stats' => $stats,
            'summary' => $summary,
            'nodes' => [
                'added' => $addedNodes,
                'removed' => $removedNodes,
                'modified' => $modifiedNodes,
            ],
            'edges' => [
                'added' => $addedEdges,
                'removed' => $removedEdges,
            ],
            'variables' => [
                'added' => $addedVars,
                'removed' => $removedVars,
                'modified' => $modifiedVars,
            ],
            'highlights' => $highlights,
        ];
    }

    /**
     * Inspect semantic changes between two versions of the same node key.
     * Ignores pure visual positions.
     *
     * @return array<string>
     */
    protected function diffNodeSemantics($sourceNode, $targetNode): array
    {
        $diffs = [];

        if ($sourceNode->name !== $targetNode->name) {
            $diffs[] = "Nombre cambiado de '{$sourceNode->name}' a '{$targetNode->name}'";
        }

        if ($sourceNode->type !== $targetNode->type) {
            $diffs[] = "Tipo de bloque cambiado de '{$sourceNode->type->value}' a '{$targetNode->type->value}'";
        }

        $sourceConf = $sourceNode->configuration ?? [];
        $targetConf = $targetNode->configuration ?? [];

        // Check specific configuration fields according to node type
        switch ($targetNode->type->value) {
            case 'message':
                $sText = $sourceConf['text'] ?? '';
                $tText = $targetConf['text'] ?? '';
                if ($sText !== $tText) {
                    $diffs[] = 'Texto del mensaje actualizado';
                }
                break;

            case 'question':
                $sText = $sourceConf['text'] ?? '';
                $tText = $targetConf['text'] ?? '';
                if ($sText !== $tText) {
                    $diffs[] = 'Texto de la pregunta actualizado';
                }
                $sVar = $sourceConf['variable'] ?? '';
                $tVar = $targetConf['variable'] ?? '';
                if ($sVar !== $tVar) {
                    $diffs[] = "Variable de destino cambiada de '{$sVar}' a '{$tVar}'";
                }
                $sType = $sourceConf['input_type'] ?? 'text';
                $tType = $targetConf['input_type'] ?? 'text';
                if ($sType !== $tType) {
                    $diffs[] = "Tipo de entrada cambiado de '{$sType}' a '{$tType}'";
                }
                $sChoices = json_encode($sourceConf['choices'] ?? []);
                $tChoices = json_encode($targetConf['choices'] ?? []);
                if ($sChoices !== $tChoices) {
                    $diffs[] = 'Opciones de selección actualizadas';
                }
                break;

            case 'menu':
                $sTitle = $sourceConf['title'] ?? ($sourceConf['text'] ?? '');
                $tTitle = $targetConf['title'] ?? ($targetConf['text'] ?? '');
                if ($sTitle !== $tTitle) {
                    $diffs[] = 'Título del menú actualizado';
                }
                $sOpts = $sourceConf['options'] ?? [];
                $tOpts = $targetConf['options'] ?? [];
                if (json_encode($sOpts) !== json_encode($tOpts)) {
                    $diffs[] = 'Opciones del menú modificadas';
                }
                break;

            case 'condition':
                $sRules = json_encode($sourceConf['rules'] ?? [$sourceConf['variable'] ?? '', $sourceConf['operator'] ?? '']);
                $tRules = json_encode($targetConf['rules'] ?? [$targetConf['variable'] ?? '', $targetConf['operator'] ?? '']);
                if ($sRules !== $tRules) {
                    $diffs[] = 'Reglas de condición modificadas';
                }
                break;

            case 'goto':
                $sTgt = $sourceConf['target_node_key'] ?? '';
                $tTgt = $targetConf['target_node_key'] ?? '';
                if ($sTgt !== $tTgt) {
                    $diffs[] = "Destino del salto cambiado a '{$tTgt}'";
                }
                break;

            case 'set_variable':
                $sVar = $sourceConf['variable'] ?? '';
                $tVar = $targetConf['variable'] ?? '';
                $sVal = $sourceConf['value'] ?? '';
                $tVal = $targetConf['value'] ?? '';
                if ($sVar !== $tVar || $sVal !== $tVal) {
                    $diffs[] = "Asignación de variable actualizada ('{$tVar}')";
                }
                break;

            case 'http_request':
                $sUrl = $sourceConf['url'] ?? '';
                $tUrl = $targetConf['url'] ?? '';
                $sMethod = $sourceConf['method'] ?? 'GET';
                $tMethod = $targetConf['method'] ?? 'GET';
                if ($sUrl !== $tUrl || $sMethod !== $tMethod) {
                    $diffs[] = "Endpoint HTTP actualizado ({$tMethod} {$tUrl})";
                }
                break;

            case 'transfer_to_agent':
            case 'end':
                $sMsg = $sourceConf['message'] ?? '';
                $tMsg = $targetConf['message'] ?? '';
                if ($sMsg !== $tMsg) {
                    $diffs[] = 'Mensaje final/transferencia actualizado';
                }
                break;

            default:
                if (json_encode($sourceConf) !== json_encode($targetConf)) {
                    $diffs[] = 'Configuración del bloque modificada';
                }
                break;
        }

        return $diffs;
    }
}
