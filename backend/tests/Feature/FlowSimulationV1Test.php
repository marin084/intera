<?php

namespace Tests\Feature;

use App\Enums\FlowNodeType;
use App\Enums\FlowVersionStatus;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowSimulationV1Test extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $agentUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::factory()->create([
            'email' => 'admin_sim@conexa.local',
        ]);
        $this->adminUser->roles()->attach(Role::where('name', 'super_admin')->first());
        $this->adminUser->load('roles.permissions');

        $this->agentUser = User::factory()->create([
            'email' => 'agent_sim@conexa.local',
        ]);
        $this->agentUser->roles()->attach(Role::where('name', 'agent')->first());
        $this->agentUser->load('roles.permissions');
    }

    /**
     * Helper to create a flow with a draft version and nodes/edges.
     */
    protected function createFlowWithGraph(array $nodes, array $edges, string $versionStatus = 'draft'): array
    {
        $flow = Flow::create([
            'name' => 'Flujo de Prueba Simulador',
            'status' => 'draft',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::from($versionStatus),
            'description' => 'Versión para simulación',
        ]);

        $createdNodes = [];
        foreach ($nodes as $n) {
            $createdNodes[$n['node_key']] = FlowNode::create([
                'flow_version_id' => $version->id,
                'node_key' => $n['node_key'],
                'type' => FlowNodeType::from($n['type']),
                'name' => $n['name'] ?? $n['node_key'],
                'configuration' => $n['configuration'] ?? [],
                'position_x' => $n['position_x'] ?? 0,
                'position_y' => $n['position_y'] ?? 0,
            ]);
        }

        $createdEdges = [];
        foreach ($edges as $e) {
            $createdEdges[] = FlowEdge::create([
                'flow_version_id' => $version->id,
                'source_node_key' => $e['source'],
                'target_node_key' => $e['target'],
                'condition_handle' => $e['handle'] ?? null,
                'metadata' => $e['metadata'] ?? [],
            ]);
        }

        return [$flow, $version, $createdNodes, $createdEdges];
    }

    /**
     * Escenario A — Conversación básica: Inicio → Mensaje → Fin.
     */
    public function test_escenario_a_conversacion_basica(): void
    {
        [$flow, $version] = $this->createFlowWithGraph(
            nodes: [
                ['node_key' => 'node_start', 'type' => 'start', 'name' => 'Inicio'],
                ['node_key' => 'node_msg', 'type' => 'message', 'name' => 'Mensaje', 'configuration' => ['text' => '¡Hola, bienvenido al bot!']],
                ['node_key' => 'node_end', 'type' => 'end', 'name' => 'Fin', 'configuration' => ['message' => 'Adiós.']],
            ],
            edges: [
                ['source' => 'node_start', 'target' => 'node_msg'],
                ['source' => 'node_msg', 'target' => 'node_end'],
            ]
        );

        $res = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/start");

        $res->assertOk();
        $res->assertJsonPath('data.status', 'completed');
        $this->assertCount(2, $res->json('data.messages'));
        $this->assertEquals('¡Hola, bienvenido al bot!', $res->json('data.messages.0.content'));
        $this->assertEquals('Adiós.', $res->json('data.messages.1.content'));
        $this->assertNotEmpty($res->json('data.events'));
    }

    /**
     * Escenario B — Menú: Inicio → Menú → Opción A / Opción B.
     */
    public function test_escenario_b_menu_opciones(): void
    {
        [$flow, $version] = $this->createFlowWithGraph(
            nodes: [
                ['node_key' => 'node_start', 'type' => 'start', 'name' => 'Inicio'],
                [
                    'node_key' => 'node_menu',
                    'type' => 'menu',
                    'name' => 'Menú Principal',
                    'configuration' => [
                        'text' => 'Selecciona un departamento:',
                        'variable' => 'departamento',
                        'options' => [
                            ['id' => 'ventas', 'text' => 'Ventas', 'value' => 'ventas'],
                            ['id' => 'soporte', 'text' => 'Soporte', 'value' => 'soporte'],
                        ],
                    ],
                ],
                ['node_key' => 'node_ventas', 'type' => 'message', 'name' => 'Resp Ventas', 'configuration' => ['text' => 'Contactando con Ventas.']],
                ['node_key' => 'node_soporte', 'type' => 'message', 'name' => 'Resp Soporte', 'configuration' => ['text' => 'Contactando con Soporte.']],
                ['node_key' => 'node_end', 'type' => 'end', 'name' => 'Fin'],
            ],
            edges: [
                ['source' => 'node_start', 'target' => 'node_menu'],
                ['source' => 'node_menu', 'target' => 'node_ventas', 'handle' => 'opt_ventas'],
                ['source' => 'node_menu', 'target' => 'node_soporte', 'handle' => 'opt_soporte'],
                ['source' => 'node_ventas', 'target' => 'node_end'],
                ['source' => 'node_soporte', 'target' => 'node_end'],
            ]
        );

        // 1. Iniciar simulación (debe pausar en waiting_input en el menú)
        $startRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/start");

        $startRes->assertOk();
        $startRes->assertJsonPath('data.status', 'waiting_input');
        $startRes->assertJsonPath('data.current_node.node_key', 'node_menu');
        $executionId = $startRes->json('data.execution_id');

        // 2. Seleccionar opción Ventas
        $stepRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/step", [
                'execution_id' => $executionId,
                'message' => 'Ventas',
            ]);

        $stepRes->assertOk();
        $stepRes->assertJsonPath('data.status', 'completed');
        $stepRes->assertJsonPath('data.context.departamento', 'ventas');
        $messages = $stepRes->json('data.messages');
        $contents = array_column($messages, 'content');
        $this->assertContains('Contactando con Ventas.', $contents);
    }

    /**
     * Escenario C — Navegación: Menú principal → Submenú → Regresar.
     */
    public function test_escenario_c_navegacion_submenus(): void
    {
        [$flow, $version] = $this->createFlowWithGraph(
            nodes: [
                ['node_key' => 'node_start', 'type' => 'start', 'name' => 'Inicio'],
                [
                    'node_key' => 'node_main_menu',
                    'type' => 'menu',
                    'name' => 'Menú Principal',
                    'configuration' => [
                        'text' => 'Menú Principal',
                        'options' => [
                            ['id' => 'productos', 'text' => 'Productos'],
                            ['id' => 'salir', 'text' => 'Salir'],
                        ],
                    ],
                ],
                [
                    'node_key' => 'node_sub_menu',
                    'type' => 'menu',
                    'name' => 'Submenú Productos',
                    'configuration' => [
                        'text' => 'Elige categoría:',
                        'options' => [
                            ['id' => 'software', 'text' => 'Software'],
                            ['id' => 'volver', 'text' => 'Volver'],
                        ],
                    ],
                ],
                ['node_key' => 'node_soft_msg', 'type' => 'message', 'name' => 'Software Msg', 'configuration' => ['text' => 'Catálogo de software.']],
                ['node_key' => 'node_end', 'type' => 'end', 'name' => 'Fin'],
            ],
            edges: [
                ['source' => 'node_start', 'target' => 'node_main_menu'],
                ['source' => 'node_main_menu', 'target' => 'node_sub_menu', 'handle' => 'opt_productos'],
                ['source' => 'node_main_menu', 'target' => 'node_end', 'handle' => 'opt_salir'],
                ['source' => 'node_sub_menu', 'target' => 'node_soft_msg', 'handle' => 'opt_software'],
                ['source' => 'node_sub_menu', 'target' => 'node_main_menu', 'handle' => 'opt_volver'],
                ['source' => 'node_soft_msg', 'target' => 'node_end'],
            ]
        );

        $startRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/start");
        $executionId = $startRes->json('data.execution_id');

        // Navegar a Productos
        $step1 = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/step", [
                'execution_id' => $executionId,
                'message' => 'Productos',
            ]);
        $step1->assertJsonPath('data.current_node.node_key', 'node_sub_menu');

        // Volver al Menú Principal
        $step2 = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/step", [
                'execution_id' => $executionId,
                'message' => 'Volver',
            ]);
        $step2->assertJsonPath('data.current_node.node_key', 'node_main_menu');

        // Salir
        $step3 = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/step", [
                'execution_id' => $executionId,
                'message' => 'Salir',
            ]);
        $step3->assertJsonPath('data.status', 'completed');
    }

    /**
     * Escenario D — Captura: Inicio → Preguntar nombre → Guardar variable → Mostrar mensaje personalizado.
     */
    public function test_escenario_d_captura_y_personalizacion(): void
    {
        [$flow, $version] = $this->createFlowWithGraph(
            nodes: [
                ['node_key' => 'node_start', 'type' => 'start', 'name' => 'Inicio'],
                [
                    'node_key' => 'node_ask',
                    'type' => 'question',
                    'name' => 'Pregunta',
                    'configuration' => [
                        'text' => '¿Cuál es tu nombre completo?',
                        'variable' => 'nombre_cliente',
                        'input_type' => 'text',
                    ],
                ],
                [
                    'node_key' => 'node_greet',
                    'type' => 'message',
                    'name' => 'Saludo',
                    'configuration' => [
                        'text' => 'Mucho gusto, {{nombre_cliente}}. Tu registro fue exitoso.',
                    ],
                ],
                ['node_key' => 'node_end', 'type' => 'end', 'name' => 'Fin'],
            ],
            edges: [
                ['source' => 'node_start', 'target' => 'node_ask'],
                ['source' => 'node_ask', 'target' => 'node_greet'],
                ['source' => 'node_greet', 'target' => 'node_end'],
            ]
        );

        $startRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/start");
        $executionId = $startRes->json('data.execution_id');

        $stepRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/step", [
                'execution_id' => $executionId,
                'message' => 'Mariana Gómez',
            ]);

        $stepRes->assertOk();
        $stepRes->assertJsonPath('data.context.nombre_cliente', 'Mariana Gómez');
        $contents = array_column($stepRes->json('data.messages'), 'content');
        $this->assertContains('Mucho gusto, Mariana Gómez. Tu registro fue exitoso.', $contents);
    }

    /**
     * Escenario E — Condición: Inicio → Capturar dato → Evaluar condición → Ejecutar ruta correcta.
     */
    public function test_escenario_e_evaluacion_de_condicion(): void
    {
        [$flow, $version] = $this->createFlowWithGraph(
            nodes: [
                ['node_key' => 'node_start', 'type' => 'start', 'name' => 'Inicio'],
                [
                    'node_key' => 'node_ask_age',
                    'type' => 'question',
                    'name' => 'Edad',
                    'configuration' => [
                        'text' => '¿Cuántos años tienes?',
                        'variable' => 'edad',
                        'input_type' => 'number',
                    ],
                ],
                [
                    'node_key' => 'node_cond',
                    'type' => 'condition',
                    'name' => 'Es mayor de edad',
                    'configuration' => [
                        'variable' => 'edad',
                        'operator' => 'gte',
                        'value' => 18,
                    ],
                ],
                ['node_key' => 'node_adult', 'type' => 'message', 'name' => 'Mayor', 'configuration' => ['text' => 'Acceso concedido a adultos.']],
                ['node_key' => 'node_minor', 'type' => 'message', 'name' => 'Menor', 'configuration' => ['text' => 'Acceso restringido para menores.']],
                ['node_key' => 'node_end', 'type' => 'end', 'name' => 'Fin'],
            ],
            edges: [
                ['source' => 'node_start', 'target' => 'node_ask_age'],
                ['source' => 'node_ask_age', 'target' => 'node_cond'],
                ['source' => 'node_cond', 'target' => 'node_adult', 'handle' => 'true'],
                ['source' => 'node_cond', 'target' => 'node_minor', 'handle' => 'false'],
                ['source' => 'node_adult', 'target' => 'node_end'],
                ['source' => 'node_minor', 'target' => 'node_end'],
            ]
        );

        // Prueba rama TRUE: edad 25
        $startRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/start");
        $execId = $startRes->json('data.execution_id');

        $stepAdult = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/step", [
                'execution_id' => $execId,
                'message' => '25',
            ]);

        $stepAdult->assertOk();
        $this->assertContains('Acceso concedido a adultos.', array_column($stepAdult->json('data.messages'), 'content'));
        $this->assertEquals(25, $stepAdult->json('data.context.edad'));

        // Prueba rama FALSE: edad 15
        $startRes2 = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/start");
        $execId2 = $startRes2->json('data.execution_id');

        $stepMinor = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/step", [
                'execution_id' => $execId2,
                'message' => '15',
            ]);

        $stepMinor->assertOk();
        $this->assertContains('Acceso restringido para menores.', array_column($stepMinor->json('data.messages'), 'content'));
    }

    /**
     * Escenario F — Transferencia a agente:
     * En simulación debe emitir mensaje simulado "El bot transferiría esta conversación a un agente."
     * y NO contaminar el Inbox ni dejar la conversación como waiting_agent en la bandeja.
     */
    public function test_escenario_f_transferencia_simulada_sin_efectos_reales(): void
    {
        [$flow, $version] = $this->createFlowWithGraph(
            nodes: [
                ['node_key' => 'node_start', 'type' => 'start', 'name' => 'Inicio'],
                [
                    'node_key' => 'node_transfer',
                    'type' => 'transfer_to_agent',
                    'name' => 'Hablar con Agente',
                    'configuration' => [
                        'message' => 'Un momento por favor.',
                        'reason' => 'Consulta especializada de facturación',
                    ],
                ],
            ],
            edges: [
                ['source' => 'node_start', 'target' => 'node_transfer'],
            ]
        );

        $res = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/start");

        $res->assertOk();
        $messages = $res->json('data.messages');
        $allText = implode(' ', array_column($messages, 'content'));
        $this->assertStringContainsString('El bot transferiría esta conversación a un agente.', $allText);

        $convId = $res->json('data.conversation_id');
        $conv = Conversation::find($convId);
        $this->assertTrue($conv->is_simulation);

        // Comprobar que NO aparece en la lista de Inbox real
        $inboxRes = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/conversations');
        $inboxRes->assertOk();
        $inboxIds = array_column($inboxRes->json('data'), 'id');
        $this->assertNotContains($convId, $inboxIds);
    }

    /**
     * Escenario G — Ciclo permitido: Menú → Información → Regresar al menú.
     */
    public function test_escenario_g_ciclo_permitido_conversacional(): void
    {
        [$flow, $version] = $this->createFlowWithGraph(
            nodes: [
                ['node_key' => 'node_start', 'type' => 'start', 'name' => 'Inicio'],
                [
                    'node_key' => 'node_menu',
                    'type' => 'menu',
                    'name' => 'Menú',
                    'configuration' => [
                        'text' => 'Menú opciones:',
                        'options' => [
                            ['id' => 'info', 'text' => 'Info'],
                            ['id' => 'salir', 'text' => 'Salir'],
                        ],
                    ],
                ],
                ['node_key' => 'node_info', 'type' => 'message', 'name' => 'Info', 'configuration' => ['text' => 'Información de la empresa.']],
                ['node_key' => 'node_goto', 'type' => 'goto', 'name' => 'Regresar', 'configuration' => ['target_node_key' => 'node_menu']],
                ['node_key' => 'node_end', 'type' => 'end', 'name' => 'Fin'],
            ],
            edges: [
                ['source' => 'node_start', 'target' => 'node_menu'],
                ['source' => 'node_menu', 'target' => 'node_info', 'handle' => 'opt_info'],
                ['source' => 'node_menu', 'target' => 'node_end', 'handle' => 'opt_salir'],
                ['source' => 'node_info', 'target' => 'node_goto'],
            ]
        );

        $startRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/start");
        $execId = $startRes->json('data.execution_id');

        // Ciclo 1: Info -> Goto -> vuelve a Menú
        $step1 = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/step", [
                'execution_id' => $execId,
                'message' => 'Info',
            ]);
        $step1->assertJsonPath('data.status', 'waiting_input');
        $step1->assertJsonPath('data.current_node.node_key', 'node_menu');

        // Ciclo 2: Salir
        $step2 = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/step", [
                'execution_id' => $execId,
                'message' => 'Salir',
            ]);
        $step2->assertJsonPath('data.status', 'completed');
    }

    /**
     * Escenario H — Ciclo infinito automático:
     * Detección y detención de bucle automático sin interacción (Goto A -> Goto B -> Goto A).
     */
    public function test_escenario_h_deteccion_bucle_infinito_automatico(): void
    {
        [$flow, $version] = $this->createFlowWithGraph(
            nodes: [
                ['node_key' => 'node_start', 'type' => 'start', 'name' => 'Inicio'],
                ['node_key' => 'node_a', 'type' => 'goto', 'name' => 'Salto A', 'configuration' => ['target_node_key' => 'node_b']],
                ['node_key' => 'node_b', 'type' => 'goto', 'name' => 'Salto B', 'configuration' => ['target_node_key' => 'node_a']],
            ],
            edges: [
                ['source' => 'node_start', 'target' => 'node_a'],
            ]
        );

        $res = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/start");

        $res->assertOk();
        $res->assertJsonPath('data.status', 'failed');
        $res->assertJsonPath('data.failure_code', 'MAX_STEPS_EXCEEDED');
    }

    /**
     * Escenario I — Flujo inválido:
     * Destino inexistente manejado con error controlado y sin crash del servidor.
     */
    public function test_escenario_i_flujo_invalido_manejo_controlado(): void
    {
        [$flow, $version] = $this->createFlowWithGraph(
            nodes: [
                ['node_key' => 'node_start', 'type' => 'start', 'name' => 'Inicio'],
            ],
            edges: [
                ['source' => 'node_start', 'target' => 'node_fantasma'],
            ]
        );

        $res = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/start");

        $res->assertOk();
        $res->assertJsonPath('data.status', 'failed');
        $res->assertJsonPath('data.failure_code', 'NODE_NOT_FOUND');
    }

    /**
     * Escenario J — Reinicio:
     * Nueva sesión limpia sin herencia de variables o mensajes anteriores.
     */
    public function test_escenario_j_reinicio_de_simulacion(): void
    {
        [$flow, $version] = $this->createFlowWithGraph(
            nodes: [
                ['node_key' => 'node_start', 'type' => 'start', 'name' => 'Inicio'],
                ['node_key' => 'node_ask', 'type' => 'question', 'name' => 'Nombre', 'configuration' => ['text' => 'Tu nombre:', 'variable' => 'nombre']],
                ['node_key' => 'node_end', 'type' => 'end', 'name' => 'Fin'],
            ],
            edges: [
                ['source' => 'node_start', 'target' => 'node_ask'],
                ['source' => 'node_ask', 'target' => 'node_end'],
            ]
        );

        // 1. Iniciar y responder
        $start1 = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/start");
        $execId1 = $start1->json('data.execution_id');

        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/step", [
                'execution_id' => $execId1,
                'message' => 'ValorAntiguo',
            ]);

        // 2. Reiniciar
        $resetRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/reset", [
                'execution_id' => $execId1,
            ]);

        $resetRes->assertOk();
        $this->assertNotEquals($execId1, $resetRes->json('data.execution_id'));
        $this->assertEmpty($resetRes->json('data.context'));
        $this->assertEquals('waiting_input', $resetRes->json('data.status'));
    }

    /**
     * Escenario K — Aislamiento entre múltiples simulaciones concurrentes:
     * No comparten variables ni mensajes ni interfieren entre sí.
     */
    public function test_escenario_k_aislamiento_entre_simulaciones_concurrentes(): void
    {
        [$flow, $version] = $this->createFlowWithGraph(
            nodes: [
                ['node_key' => 'node_start', 'type' => 'start', 'name' => 'Inicio'],
                ['node_key' => 'node_ask', 'type' => 'question', 'name' => 'Color', 'configuration' => ['text' => 'Color favorito:', 'variable' => 'color']],
                ['node_key' => 'node_end', 'type' => 'end', 'name' => 'Fin'],
            ],
            edges: [
                ['source' => 'node_start', 'target' => 'node_ask'],
                ['source' => 'node_ask', 'target' => 'node_end'],
            ]
        );

        // Simulación A
        $simA = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/start");
        $execA = $simA->json('data.execution_id');

        // Simulación B
        $simB = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/start");
        $execB = $simB->json('data.execution_id');

        // Avanzar A con 'Azul'
        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/step", [
                'execution_id' => $execA,
                'message' => 'Azul',
            ]);

        // Avanzar B con 'Rojo'
        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/step", [
                'execution_id' => $execB,
                'message' => 'Rojo',
            ]);

        // Consultar estado A y B
        $stateA = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/{$execA}");
        $stateB = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/{$execB}");

        $this->assertEquals('Azul', $stateA->json('data.context.color'));
        $this->assertEquals('Rojo', $stateB->json('data.context.color'));
    }

    /**
     * Escenario L — Simulación de borrador:
     * Modificar un borrador sin publicar y probarlo en el simulador;
     * la versión publicada permanece intacta.
     */
    public function test_escenario_l_simulacion_de_borrador_sin_publicar(): void
    {
        // 1. Versión 1 publicada
        $flow = Flow::create([
            'name' => 'Flujo Versionado',
            'status' => 'published',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $v1Published = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::PUBLISHED,
            'published_at' => now(),
            'published_by' => $this->adminUser->id,
        ]);
        $flow->update(['active_version_id' => $v1Published->id]);

        FlowNode::create([
            'flow_version_id' => $v1Published->id,
            'node_key' => 'node_start',
            'type' => FlowNodeType::START,
            'name' => 'Inicio V1',
        ]);
        FlowNode::create([
            'flow_version_id' => $v1Published->id,
            'node_key' => 'node_msg_v1',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Mensaje V1',
            'configuration' => ['text' => 'Texto versión 1 publicada'],
        ]);
        FlowEdge::create([
            'flow_version_id' => $v1Published->id,
            'source_node_key' => 'node_start',
            'target_node_key' => 'node_msg_v1',
        ]);

        // 2. Versión 2 borrador con cambios
        $v2Draft = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 2,
            'status' => FlowVersionStatus::DRAFT,
        ]);
        FlowNode::create([
            'flow_version_id' => $v2Draft->id,
            'node_key' => 'node_start',
            'type' => FlowNodeType::START,
            'name' => 'Inicio V2',
        ]);
        FlowNode::create([
            'flow_version_id' => $v2Draft->id,
            'node_key' => 'node_msg_v2',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Mensaje V2',
            'configuration' => ['text' => 'Texto versión 2 en borrador'],
        ]);
        FlowEdge::create([
            'flow_version_id' => $v2Draft->id,
            'source_node_key' => 'node_start',
            'target_node_key' => 'node_msg_v2',
        ]);

        // Simular borrador V2
        $resDraft = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$v2Draft->id}/simulation/start");

        $resDraft->assertOk();
        $this->assertContains('Texto versión 2 en borrador', array_column($resDraft->json('data.messages'), 'content'));

        // Verificar que la versión 1 sigue publicada y es el active_version_id del flujo
        $flow->refresh();
        $this->assertEquals($v1Published->id, $flow->active_version_id);
        $v1Published->refresh();
        $this->assertEquals(FlowVersionStatus::PUBLISHED, $v1Published->status);
        $v2Draft->refresh();
        $this->assertEquals(FlowVersionStatus::DRAFT, $v2Draft->status);
    }

    /**
     * Seguridad y RBAC: Un agente sin permiso flows.view recibe 403 Forbidden.
     */
    public function test_seguridad_agente_sin_permiso_recibe_403(): void
    {
        [$flow, $version] = $this->createFlowWithGraph(
            nodes: [
                ['node_key' => 'node_start', 'type' => 'start', 'name' => 'Inicio'],
            ],
            edges: []
        );

        $res = $this->actingAs($this->agentUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/start");

        $res->assertForbidden();
    }
}
