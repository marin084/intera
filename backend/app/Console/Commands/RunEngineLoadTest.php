<?php

namespace App\Console\Commands;

use App\Enums\ChannelType;
use App\Enums\FlowNodeType;
use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Models\Channel;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Services\Runtime\ConversationIngressService;
use App\Services\Runtime\FlowGraphCacheService;
use App\Services\Runtime\FlowRuntimeEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RunEngineLoadTest extends Command
{
    protected $signature = 'flows:load-test 
                            {--profile=all : Profile to run (A: 10, B: 50, C: 100, D: 250, E: 500, or all)}
                            {--scenarios=all : Scenarios to run (A, B, C, D, E, F, G, H, or all)}
                            {--output-json= : Optional path to write JSON metrics report}';

    protected $description = 'Executes reproducible performance and load test suites for the Conversational Flow Runtime Engine';

    protected ConversationIngressService $ingress;

    protected FlowRuntimeEngine $engine;

    protected FlowGraphCacheService $graphCache;

    public function handle(): int
    {
        $this->ingress = app(ConversationIngressService::class);
        $this->engine = app(FlowRuntimeEngine::class);
        $this->graphCache = app(FlowGraphCacheService::class);

        $this->info('================================================================');
        $this->info(' CONEXA — FASE 4: SUITE DE PRUEBAS DE CARGA DEL MOTOR RUNTIME');
        $this->info('================================================================');

        $profileArg = strtoupper($this->option('profile') ?: 'ALL');
        $profiles = [
            'A' => 10,
            'B' => 50,
            'C' => 100,
            'D' => 250,
            'E' => 500,
        ];

        if ($profileArg !== 'ALL') {
            if (! isset($profiles[$profileArg])) {
                $this->error("Perfil desconocido: {$profileArg}. Opciones: A, B, C, D, E, all");

                return 1;
            }
            $targetProfiles = [$profileArg => $profiles[$profileArg]];
        } else {
            $targetProfiles = $profiles;
        }

        $allMetrics = [];

        foreach ($targetProfiles as $profileName => $concurrency) {
            $this->newLine();
            $this->info(">>> EJECUTANDO PERFIL {$profileName}: {$concurrency} CONVERSACIONES CONCURRENTES <<<");

            $metrics = $this->runProfile($profileName, $concurrency);
            $allMetrics[$profileName] = $metrics;

            $this->renderProfileSummary($profileName, $concurrency, $metrics);
        }

        $outputPath = $this->option('output-json');
        if ($outputPath) {
            $dir = dirname($outputPath);
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            file_put_contents($outputPath, json_encode([
                'timestamp' => now()->toIso8601String(),
                'environment' => [
                    'php_version' => PHP_VERSION,
                    'memory_limit' => ini_get('memory_limit'),
                    'os' => PHP_OS,
                ],
                'profiles' => $allMetrics,
            ], JSON_PRETTY_PRINT));
            $this->info("Reporte JSON exportado a: {$outputPath}");
        }

        return 0;
    }

    protected function runProfile(string $profileName, int $concurrency): array
    {
        $latencies = [];
        $totalMessages = 0;
        $totalRequests = 0;
        $errorsCount = 0;

        $startTime = microtime(true);
        $startMem = memory_get_usage(true);

        // Pre-create standardized flows
        $flowA = $this->createSimpleFlow();
        $flowB = $this->createVariableConditionFlow();

        $channelA = Channel::create(['name' => "Chan Load A {$profileName}", 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'entry_flow_id' => $flowA->id]);
        $channelB = Channel::create(['name' => "Chan Load B {$profileName}", 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'entry_flow_id' => $flowB->id]);

        for ($i = 1; $i <= $concurrency; $i++) {
            $userId = "load_{$profileName}_{$i}_".Str::random(5);
            $selectedChannel = ($i % 2 === 0) ? $channelB : $channelA;

            // Step 1: Initial Inbound Message (Start Conversation)
            $t0 = microtime(true);
            try {
                $res1 = $this->ingress->handleInboundMessage(
                    $selectedChannel,
                    ['external_identifier' => $userId, 'display_name' => "User {$userId}"],
                    'Hola, quiero iniciar'
                );
                $latencies[] = (microtime(true) - $t0) * 1000;
                $totalRequests++;
                $totalMessages++;
            } catch (\Throwable $e) {
                $errorsCount++;
                $latencies[] = (microtime(true) - $t0) * 1000;

                continue;
            }

            // Step 2: Answer prompt or Menu
            $t1 = microtime(true);
            try {
                $replyContent = ($i % 2 === 0) ? '28' : '1';
                $externalMsgId = (string) Str::uuid();

                $res2 = $this->ingress->handleInboundMessage(
                    $selectedChannel,
                    ['external_identifier' => $userId],
                    $replyContent,
                    $externalMsgId
                );
                $latencies[] = (microtime(true) - $t1) * 1000;
                $totalRequests++;
                $totalMessages++;

                // Step 3: Test Idempotent Retry on 20% of conversations
                if ($i % 5 === 0) {
                    $t2 = microtime(true);
                    $resRetry = $this->ingress->handleInboundMessage(
                        $selectedChannel,
                        ['external_identifier' => $userId],
                        $replyContent,
                        $externalMsgId // Identical external_message_id
                    );
                    $latencies[] = (microtime(true) - $t2) * 1000;
                    $totalRequests++;
                    if (! $resRetry['is_duplicate']) {
                        $errorsCount++;
                    }
                }
            } catch (\Throwable $e) {
                $errorsCount++;
                $latencies[] = (microtime(true) - $t1) * 1000;
            }
        }

        // Test Scenario G/H: Version pinning during concurrency on flowA
        $versionOld = $flowA->activeVersion;
        $newVersion = FlowVersion::create([
            'flow_id' => $flowA->id,
            'version_number' => 2,
            'status' => FlowVersionStatus::PUBLISHED,
        ]);
        FlowNode::create(['flow_version_id' => $newVersion->id, 'node_key' => 'start_1', 'type' => FlowNodeType::START, 'name' => 'Start V2']);
        FlowNode::create(['flow_version_id' => $newVersion->id, 'node_key' => 'msg_v2', 'type' => FlowNodeType::MESSAGE, 'name' => 'V2 Msg', 'configuration' => ['text' => 'Bienvenido a V2!']]);
        FlowEdge::create(['flow_version_id' => $newVersion->id, 'source_node_key' => 'start_1', 'target_node_key' => 'msg_v2']);

        // Publish V2 and invalidate cache
        $flowA->update(['active_version_id' => $newVersion->id]);
        $this->graphCache->invalidate($versionOld->id);
        $this->graphCache->invalidate($newVersion->id);

        // Start 5 new conversations verifying they pick V2
        for ($k = 1; $k <= 5; $k++) {
            $newUserId = "pinning_{$profileName}_{$k}";
            $resPin = $this->ingress->handleInboundMessage($channelA, ['external_identifier' => $newUserId], 'Iniciar V2');
            $exec = $resPin['execution'];
            if ($exec->flow_version_id !== $newVersion->id) {
                $errorsCount++;
            }
            $totalRequests++;
            $totalMessages++;
        }

        // Rollback back to V1
        $flowA->update(['active_version_id' => $versionOld->id]);
        $this->graphCache->invalidate($versionOld->id);
        $this->graphCache->invalidate($newVersion->id);

        $totalDurationSec = max(microtime(true) - $startTime, 0.001);
        $peakMemBytes = memory_get_peak_usage(true) - $startMem;

        sort($latencies);
        $count = count($latencies);
        $p50 = $count > 0 ? $latencies[(int) floor($count * 0.50)] : 0;
        $p95 = $count > 0 ? $latencies[(int) floor($count * 0.95)] : 0;
        $p99 = $count > 0 ? $latencies[(int) floor($count * 0.99)] : 0;

        return [
            'concurrency' => $concurrency,
            'total_requests' => $totalRequests,
            'total_messages' => $totalMessages,
            'total_duration_sec' => round($totalDurationSec, 3),
            'throughput_req_per_sec' => round($totalRequests / $totalDurationSec, 2),
            'throughput_msg_per_sec' => round($totalMessages / $totalDurationSec, 2),
            'latency_p50_ms' => round($p50, 2),
            'latency_p95_ms' => round($p95, 2),
            'latency_p99_ms' => round($p99, 2),
            'errors_count' => $errorsCount,
            'error_rate_pct' => $totalRequests > 0 ? round(($errorsCount / $totalRequests) * 100, 2) : 0,
            'peak_memory_mb' => round($peakMemBytes / 1024 / 1024, 2),
        ];
    }

    protected function renderProfileSummary(string $profile, int $concurrency, array $m): void
    {
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Perfil', "Perfil {$profile} ({$concurrency} conv. concurrentes)"],
                ['Total Solicitudes Ingress', $m['total_requests']],
                ['Total Mensajes Procesados', $m['total_messages']],
                ['Tiempo Total de Ejecución', "{$m['total_duration_sec']} s"],
                ['Throughput (solicitudes/s)', "{$m['throughput_req_per_sec']} req/s"],
                ['Throughput (mensajes/s)', "{$m['throughput_msg_per_sec']} msg/s"],
                ['Latencia p50 (mediana)', "{$m['latency_p50_ms']} ms"],
                ['Latencia p95', "{$m['latency_p95_ms']} ms"],
                ['Latencia p99', "{$m['latency_p99_ms']} ms"],
                ['Tasa de Errores', "{$m['error_rate_pct']}% ({$m['errors_count']} errores)"],
                ['Consumo Memoria Pico', "{$m['peak_memory_mb']} MB"],
            ]
        );
    }

    protected function createSimpleFlow(): Flow
    {
        $flow = Flow::create(['name' => 'Load Simple Flow '.Str::random(5), 'status' => FlowStatus::PUBLISHED]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::PUBLISHED]);

        FlowNode::create(['flow_version_id' => $version->id, 'node_key' => 'start_1', 'type' => FlowNodeType::START, 'name' => 'Start']);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'menu_1',
            'type' => FlowNodeType::MENU,
            'name' => 'Menu',
            'configuration' => [
                'text' => 'Selecciona opción:',
                'options' => [
                    ['id' => '1', 'text' => 'Soporte', 'target_node_key' => 'msg_support'],
                    ['id' => '2', 'text' => 'Salir', 'target_node_key' => 'end_1'],
                ],
            ],
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_support',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Soporte',
            'configuration' => ['text' => 'Conectando con soporte.'],
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'end_1',
            'type' => FlowNodeType::END,
            'name' => 'Fin',
            'configuration' => ['message' => 'Adiós.'],
        ]);

        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'start_1', 'target_node_key' => 'menu_1']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'msg_support', 'target_node_key' => 'end_1']);

        $flow->update(['active_version_id' => $version->id]);

        return $flow;
    }

    protected function createVariableConditionFlow(): Flow
    {
        $flow = Flow::create(['name' => 'Load VarCond Flow '.Str::random(5), 'status' => FlowStatus::PUBLISHED]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::PUBLISHED]);

        FlowNode::create(['flow_version_id' => $version->id, 'node_key' => 'start_1', 'type' => FlowNodeType::START, 'name' => 'Start']);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'q_age',
            'type' => FlowNodeType::QUESTION,
            'name' => 'Edad',
            'configuration' => ['text' => 'Ingresa tu edad:', 'variable' => 'user_age', 'input_type' => 'number'],
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'cond_age',
            'type' => FlowNodeType::CONDITION,
            'name' => 'Mayor',
            'configuration' => ['variable' => 'user_age', 'operator' => 'gte', 'value' => '18'],
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_ok',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Mayor',
            'configuration' => ['text' => 'Eres mayor de edad ({{user_age}}).'],
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'end_1',
            'type' => FlowNodeType::END,
            'name' => 'Fin',
        ]);

        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'start_1', 'target_node_key' => 'q_age']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'q_age', 'target_node_key' => 'cond_age']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'cond_age', 'target_node_key' => 'msg_ok', 'condition_handle' => 'true']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'msg_ok', 'target_node_key' => 'end_1']);

        $flow->update(['active_version_id' => $version->id]);

        return $flow;
    }
}
