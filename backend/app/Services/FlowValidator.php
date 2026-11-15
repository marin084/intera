<?php

namespace App\Services;

use App\Enums\FlowNodeType;
use App\Models\FlowVersion;
use App\Services\Runtime\ConditionEvaluator;

class FlowValidator
{
    /**
     * Convenience method to validate a version and return only its error messages.
     *
     * @return array<string>
     */
    public function validateVersion(FlowVersion $version): array
    {
        return $this->validate($version)['errors'];
    }

    /**
     * Validate the structural and semantic integrity of a FlowVersion.
     * Enforces the principle: "Publishable = Executable".
     *
     * @return array{is_valid: bool, errors: array<string>, structured_errors: array<array{code: string, node_key: ?string, message: string}>}
     */
    public function validate(FlowVersion $version): array
    {
        $version->loadMissing(['nodes', 'edges']);
        $nodes = $version->nodes;
        $edges = $version->edges;

        $errors = [];
        $structuredErrors = [];
        $warnings = [];
        $infos = [];

        $addError = function (string $message, string $code, ?string $nodeKey = null) use (&$errors, &$structuredErrors) {
            $errors[] = $message;
            $structuredErrors[] = [
                'code' => $code,
                'node_key' => $nodeKey,
                'message' => $message,
            ];
        };

        $addWarning = function (string $message, string $code, ?string $nodeKey = null) use (&$warnings) {
            $warnings[] = [
                'code' => $code,
                'node_key' => $nodeKey,
                'message' => $message,
            ];
        };

        if ($nodes->isEmpty()) {
            $addError('El flujo debe contener al menos un nodo.', 'EMPTY_FLOW', null);

            return [
                'is_valid' => false,
                'errors' => $errors,
                'structured_errors' => $structuredErrors,
            ];
        }

        // 1. Validate exactly one start node
        $startNodes = $nodes->filter(fn ($node) => $node->type === FlowNodeType::START);
        if ($startNodes->count() === 0) {
            $addError('El flujo debe tener exactamente un nodo de inicio (start).', 'START_NODE_MISSING', null);
        } elseif ($startNodes->count() > 1) {
            $addError('El flujo no puede tener más de un nodo de inicio (start). Encontrados: '.$startNodes->count().'.', 'MULTIPLE_START_NODES', null);
        }

        $nodeKeys = $nodes->pluck('node_key')->all();
        $nodeKeySet = array_flip($nodeKeys);

        // 2. Validate edge references (source and target must exist)
        foreach ($edges as $edge) {
            if (! isset($nodeKeySet[$edge->source_node_key])) {
                $addError("La conexión origen '{$edge->source_node_key}' no corresponde a ningún nodo válido.", 'INVALID_EDGE_SOURCE', $edge->source_node_key);
            }
            if (! isset($nodeKeySet[$edge->target_node_key])) {
                $addError("La conexión destino '{$edge->target_node_key}' no corresponde a ningún nodo válido.", 'INVALID_EDGE_TARGET', $edge->target_node_key);
            }

            // End nodes should not have outgoing edges
            $sourceNode = $nodes->firstWhere('node_key', $edge->source_node_key);
            if ($sourceNode && $sourceNode->type === FlowNodeType::END) {
                $addError("El nodo de fin '{$edge->source_node_key}' no puede tener conexiones salientes.", 'END_HAS_OUTGOING_EDGE', $edge->source_node_key);
            }

            // Start nodes should not have incoming edges
            $targetNode = $nodes->firstWhere('node_key', $edge->target_node_key);
            if ($targetNode && $targetNode->type === FlowNodeType::START) {
                $addError("El nodo de inicio '{$edge->target_node_key}' no puede tener conexiones entrantes.", 'START_HAS_INCOMING_EDGE', $edge->target_node_key);
            }
        }

        // 3. Per-node semantic rules and outgoing edge validation
        foreach ($nodes as $node) {
            $outgoingEdges = $edges->where('source_node_key', $node->node_key);
            $config = $node->configuration ?? [];

            switch ($node->type) {
                case FlowNodeType::START:
                    if ($outgoingEdges->count() !== 1) {
                        $addError("El nodo de inicio '{$node->node_key}' debe tener exactamente una conexión saliente. Encontradas: {$outgoingEdges->count()}.", 'START_MISSING_EDGE', $node->node_key);
                    }
                    break;

                case FlowNodeType::MESSAGE:
                    $text = $config['text'] ?? null;
                    if (empty($text) || ! is_string($text) || trim($text) === '') {
                        $addError("El nodo de mensaje '{$node->node_key}' debe contener el campo 'text' con texto no vacío.", 'MESSAGE_TEXT_REQUIRED', $node->node_key);
                    }
                    if ($outgoingEdges->count() !== 1) {
                        $addError("El nodo de mensaje '{$node->node_key}' debe tener exactamente una conexión saliente. Encontradas: {$outgoingEdges->count()}.", 'MESSAGE_MISSING_EDGE', $node->node_key);
                    }
                    break;

                case FlowNodeType::IMAGE:
                    $url = $config['url'] ?? null;
                    if (empty($url) || ! is_string($url) || trim($url) === '') {
                        $addError("El nodo de imagen '{$node->node_key}' debe contener una URL válida en el campo 'url'.", 'IMAGE_URL_REQUIRED', $node->node_key);
                    }
                    if ($outgoingEdges->count() !== 1) {
                        $addError("El nodo de imagen '{$node->node_key}' debe tener exactamente una conexión saliente. Encontradas: {$outgoingEdges->count()}.", 'IMAGE_MISSING_EDGE', $node->node_key);
                    }
                    break;

                case FlowNodeType::FILE:
                    $url = $config['url'] ?? null;
                    if (empty($url) || ! is_string($url) || trim($url) === '') {
                        $addError("El nodo de archivo '{$node->node_key}' debe contener una URL válida en el campo 'url'.", 'FILE_URL_REQUIRED', $node->node_key);
                    }
                    if ($outgoingEdges->count() !== 1) {
                        $addError("El nodo de archivo '{$node->node_key}' debe tener exactamente una conexión saliente. Encontradas: {$outgoingEdges->count()}.", 'FILE_MISSING_EDGE', $node->node_key);
                    }
                    break;

                case FlowNodeType::QUESTION:
                    $text = $config['text'] ?? null;
                    if (empty($text) || ! is_string($text) || trim($text) === '') {
                        $addError("El nodo de pregunta '{$node->node_key}' debe contener el campo 'text' con texto no vacío.", 'QUESTION_TEXT_REQUIRED', $node->node_key);
                    }
                    $variable = $config['variable'] ?? null;
                    if (empty($variable) || ! is_string($variable) || trim($variable) === '') {
                        $addError("El nodo de pregunta '{$node->node_key}' debe especificar una variable válida para almacenar la respuesta.", 'QUESTION_VARIABLE_REQUIRED', $node->node_key);
                    } elseif (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', trim($variable))) {
                        $addError("El nodo de pregunta '{$node->node_key}' especifica un nombre de variable inválido '{$variable}'. Debe iniciar con letra o guion bajo y no contener espacios.", 'QUESTION_INVALID_VARIABLE_NAME', $node->node_key);
                    }
                    $inputType = $config['input_type'] ?? 'text';
                    if (! in_array($inputType, ['text', 'number', 'email', 'choice', 'country'], true)) {
                        $addError("El nodo de pregunta '{$node->node_key}' tiene un tipo de entrada '{$inputType}' no soportado.", 'QUESTION_INVALID_INPUT_TYPE', $node->node_key);
                    }
                    if ($inputType === 'choice') {
                        $choices = $config['choices'] ?? null;
                        if (! is_array($choices) || empty($choices)) {
                            $addError("El nodo de pregunta '{$node->node_key}' con tipo 'choice' debe configurar una lista de 'choices' no vacía.", 'QUESTION_CHOICES_REQUIRED', $node->node_key);
                        }
                    }
                    $contactField = $config['contact_field'] ?? ($config['sync_contact_field'] ?? 'none');
                    if (! in_array($contactField, [null, 'none', 'display_name', 'email', 'phone', 'country'], true)) {
                        $addError("El nodo de pregunta '{$node->node_key}' tiene un mapeo de contacto '{$contactField}' no permitido. Valores permitidos: none, display_name, email, phone, country.", 'QUESTION_INVALID_CONTACT_FIELD', $node->node_key);
                    }
                    if ($outgoingEdges->count() !== 1) {
                        $addError("El nodo de pregunta '{$node->node_key}' debe tener exactamente una conexión saliente. Encontradas: {$outgoingEdges->count()}.", 'QUESTION_MISSING_EDGE', $node->node_key);
                    }
                    break;

                case FlowNodeType::CONDITION:
                    // Support multi-rule condition
                    if (isset($config['rules']) && is_array($config['rules']) && ! empty($config['rules'])) {
                        foreach ($config['rules'] as $idx => $rule) {
                            $rVar = $rule['variable'] ?? null;
                            $rOp = $rule['operator'] ?? null;
                            if (empty($rVar) || ! is_string($rVar) || trim($rVar) === '') {
                                $addError('La regla #'.($idx + 1)." del nodo de condición '{$node->node_key}' debe especificar una variable válida.", 'CONDITION_VARIABLE_REQUIRED', $node->node_key);
                            }
                            if (empty($rOp) || ! in_array($rOp, ConditionEvaluator::ALLOWED_OPERATORS, true)) {
                                $addError('La regla #'.($idx + 1)." del nodo de condición '{$node->node_key}' tiene un operador inválido: '{$rOp}'.", 'CONDITION_INVALID_OPERATOR', $node->node_key);
                            }
                        }
                    } else {
                        $variable = $config['variable'] ?? null;
                        if (empty($variable) || ! is_string($variable) || trim($variable) === '') {
                            $addError("El nodo de condición '{$node->node_key}' debe especificar una variable válida.", 'CONDITION_VARIABLE_REQUIRED', $node->node_key);
                        }
                        $operator = $config['operator'] ?? null;
                        if (empty($operator) || ! in_array($operator, ConditionEvaluator::ALLOWED_OPERATORS, true)) {
                            $addError("El nodo de condición '{$node->node_key}' tiene un operador no permitido o no válido: '{$operator}'.", 'CONDITION_INVALID_OPERATOR', $node->node_key);
                        }
                    }

                    $getHandle = function ($edge) {
                        $h = $edge->condition_handle ?? ($edge->sourceHandle ?? ($edge->source_handle ?? null));
                        return $h !== null ? strtolower(trim((string) $h)) : null;
                    };

                    $trueEdges = $outgoingEdges->filter(fn ($e) => $getHandle($e) === 'true');
                    $falseEdges = $outgoingEdges->filter(fn ($e) => $getHandle($e) === 'false');
                    $otherEdges = $outgoingEdges->filter(fn ($e) => ! in_array($getHandle($e), ['true', 'false'], true));

                    if ($trueEdges->count() !== 1) {
                        $addError("El nodo de condición '{$node->node_key}' debe tener exactamente una conexión de salida 'true'. Encontradas: {$trueEdges->count()}.", 'CONDITION_MISSING_TRUE_BRANCH', $node->node_key);
                    }
                    if ($falseEdges->count() !== 1) {
                        $addError("El nodo de condición '{$node->node_key}' debe tener exactamente una conexión de salida 'false'. Encontradas: {$falseEdges->count()}.", 'CONDITION_MISSING_FALSE_BRANCH', $node->node_key);
                    }
                    if ($otherEdges->isNotEmpty()) {
                        $addError("El nodo de condición '{$node->node_key}' contiene conexiones salientes con handles no permitidos o ambiguos.", 'CONDITION_AMBIGUOUS_HANDLES', $node->node_key);
                    }
                    break;

                case FlowNodeType::MENU:
                    $menuText = $config['text'] ?? ($config['title'] ?? null);
                    if (empty($menuText) || ! is_string($menuText) || trim($menuText) === '') {
                        $addError("El nodo de menú '{$node->node_key}' debe contener un texto o mensaje para el usuario.", 'MENU_TEXT_REQUIRED', $node->node_key);
                    }

                    $options = $config['options'] ?? null;
                    if (! is_array($options) || empty($options)) {
                        $addError("El nodo de menú '{$node->node_key}' debe tener al menos una opción configurada.", 'MENU_OPTIONS_REQUIRED', $node->node_key);
                    } else {
                        foreach ($options as $idx => $opt) {
                            $optText = is_array($opt) ? ($opt['text'] ?? null) : (string) $opt;
                            $optId = is_array($opt) ? ($opt['id'] ?? null) : (string) $idx;

                            if (empty($optText) || trim($optText) === '') {
                                $addError('La opción #'.($idx + 1)." del nodo de menú '{$node->node_key}' no tiene texto visible.", 'MENU_OPTION_EMPTY_TEXT', $node->node_key);
                            }

                            // Check destination: either edge with opt_{id} handle or target_node_key in option
                            $optHandle = 'opt_'.$optId;
                            $hasEdge = $outgoingEdges->where('condition_handle', $optHandle)->isNotEmpty();
                            $targetKey = is_array($opt) ? ($opt['target_node_key'] ?? null) : null;

                            if (! $hasEdge && (empty($targetKey) || ! isset($nodeKeySet[$targetKey]))) {
                                $addError("La opción '{$optText}' del nodo de menú '{$node->node_key}' no tiene un destino configurado ni una conexión saliente.", 'MENU_OPTION_MISSING_TARGET', $node->node_key);
                            }
                        }
                    }
                    break;

                case FlowNodeType::HTTP_REQUEST:
                    $url = $config['url'] ?? null;
                    if (empty($url) || ! is_string($url) || trim($url) === '') {
                        $addError("El nodo HTTP '{$node->node_key}' debe configurar una URL de destino.", 'HTTP_URL_REQUIRED', $node->node_key);
                    }
                    $method = strtoupper($config['method'] ?? 'GET');
                    if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                        $addError("El nodo HTTP '{$node->node_key}' tiene un método '{$method}' no permitido.", 'HTTP_INVALID_METHOD', $node->node_key);
                    }
                    if ($outgoingEdges->count() < 1) {
                        $addError("El nodo HTTP '{$node->node_key}' debe tener al menos una conexión saliente.", 'HTTP_MISSING_EDGE', $node->node_key);
                    }
                    break;

                case FlowNodeType::GOTO:
                    $targetKey = $config['target_node_key'] ?? null;
                    if (empty($targetKey) || ! is_string($targetKey) || trim($targetKey) === '') {
                        $addError("El bloque 'Ir a Bloque' ({$node->node_key}) debe seleccionar un bloque de destino.", 'GOTO_TARGET_REQUIRED', $node->node_key);
                    } elseif (! isset($nodeKeySet[$targetKey])) {
                        $addError("El bloque 'Ir a Bloque' ({$node->node_key}) referencia un destino inexistente: '{$targetKey}'.", 'GOTO_TARGET_NOT_FOUND', $node->node_key);
                    }
                    break;

                case FlowNodeType::SET_VARIABLE:
                    $setVar = $config['variable'] ?? $config['variable_name'] ?? null;
                    if (empty($setVar) || ! is_string($setVar) || trim($setVar) === '') {
                        $addError("El nodo 'Establecer Variable' ({$node->node_key}) debe especificar el nombre de una variable.", 'SET_VARIABLE_REQUIRED', $node->node_key);
                    } elseif (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', trim($setVar))) {
                        $addError("El nodo 'Establecer Variable' ({$node->node_key}) tiene un nombre de variable inválido '{$setVar}'.", 'SET_VARIABLE_INVALID_NAME', $node->node_key);
                    }
                    if ($outgoingEdges->count() !== 1) {
                        $addError("El nodo 'Establecer Variable' ({$node->node_key}) debe tener exactamente una conexión saliente.", 'SET_VARIABLE_MISSING_EDGE', $node->node_key);
                    }
                    break;

                case FlowNodeType::TRANSFER_TO_AGENT:
                    if ($outgoingEdges->count() > 0) {
                        $addError("El nodo de transferencia a agente '{$node->node_key}' no puede tener conexiones salientes.", 'TRANSFER_HAS_OUTGOING_EDGE', $node->node_key);
                    }
                    break;

                case FlowNodeType::END:
                    if ($outgoingEdges->count() > 0) {
                        $addError("El nodo de fin '{$node->node_key}' no puede tener conexiones salientes.", 'END_HAS_OUTGOING_EDGE', $node->node_key);
                    }
                    break;
            }
        }

        // 4. Reachability check from start node
        if ($startNodes->count() === 1) {
            $startKey = $startNodes->first()->node_key;
            $visited = [];
            $queue = [$startKey];

            // Build adjacency list
            $adjacency = [];
            foreach ($edges as $edge) {
                $adjacency[$edge->source_node_key][] = $edge->target_node_key;
            }

            // Also add Goto jumps and Menu option targets into adjacency list
            foreach ($nodes as $node) {
                if ($node->type === FlowNodeType::GOTO) {
                    $tgt = $node->configuration['target_node_key'] ?? null;
                    if ($tgt && isset($nodeKeySet[$tgt])) {
                        $adjacency[$node->node_key][] = $tgt;
                    }
                } elseif ($node->type === FlowNodeType::MENU) {
                    $opts = $node->configuration['options'] ?? [];
                    if (is_array($opts)) {
                        foreach ($opts as $opt) {
                            if (is_array($opt) && ! empty($opt['target_node_key']) && isset($nodeKeySet[$opt['target_node_key']])) {
                                $adjacency[$node->node_key][] = $opt['target_node_key'];
                            }
                        }
                    }
                }
            }

            while (! empty($queue)) {
                $current = array_shift($queue);
                if (isset($visited[$current])) {
                    continue;
                }
                $visited[$current] = true;

                if (isset($adjacency[$current])) {
                    foreach ($adjacency[$current] as $neighbor) {
                        if (! isset($visited[$neighbor])) {
                            $queue[] = $neighbor;
                        }
                    }
                }
            }

            // Check if any non-start node is unreachable
            foreach ($nodeKeys as $key) {
                if ($key !== $startKey && ! isset($visited[$key])) {
                    $addError("El nodo '{$key}' es inalcanzable desde el nodo de inicio.", 'NODE_UNREACHABLE', $key);
                }
            }
        }

        $infos[] = "El flujo contiene {$nodes->count()} ".($nodes->count() === 1 ? 'nodo' : 'nodos')." y {$edges->count()} ".($edges->count() === 1 ? 'conexión' : 'conexiones').'.';
        $variables = $version->metadata['variables'] ?? [];
        if (is_array($variables) && count($variables) > 0) {
            $infos[] = 'Variables definidas: '.count($variables).'.';
        }

        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
            'structured_errors' => $structuredErrors,
            'warnings' => $warnings,
            'infos' => $infos,
        ];
    }
}
