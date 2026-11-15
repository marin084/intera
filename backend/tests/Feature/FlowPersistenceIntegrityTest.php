<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FlowPersistenceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::create([
            'name' => 'Admin Auditor',
            'email' => 'auditor@conexa.test',
            'password' => Hash::make('TestSecuredPass@2026'),
            'is_active' => true,
        ]);
        $this->adminUser->roles()->attach(Role::where('name', 'super_admin')->first());
    }

    /**
     * Test the mandatory 16-step persistence & integrity scenario from Phase 6.
     */
    public function test_mandatory_16_step_persistence_and_integrity_lifecycle(): void
    {
        // 1. Crear un flujo nuevo
        $createRes = $this->actingAs($this->adminUser, 'sanctum')->postJson('/api/v1/flows', [
            'name' => 'Flujo Auditoría Fase 6',
            'description' => 'Validación exhaustiva de persistencia V3',
            'channel_applicability' => ['webchat'],
        ]);
        $createRes->assertStatus(201);
        $flowId = $createRes->json('data.id');
        $this->assertNotNull($flowId);

        // Fetch draft version 1
        $flowRes = $this->actingAs($this->adminUser, 'sanctum')->getJson("/api/v1/flows/{$flowId}");
        $flowRes->assertStatus(200);
        $versionId = $flowRes->json('data.versions.0.id');
        $this->assertNotNull($versionId);

        // 2. Agregar varios nodos
        // 3. Crear conexiones
        // 4. Configurar bloques
        // 5. Configurar un menú con opciones
        // 6. Configurar variables
        // 7. Mover nodos a diferentes posiciones
        $customVars = [
            ['name' => 'order_id', 'type' => 'text', 'scope' => 'flow', 'description' => 'Número de pedido'],
            ['name' => 'is_vip', 'type' => 'boolean', 'scope' => 'contact', 'description' => 'Cliente VIP'],
        ];

        $initialNodes = [
            [
                'node_key' => 'node_start',
                'type' => 'start',
                'name' => 'Inicio del Flujo',
                'position_x' => 100.0,
                'position_y' => 50.0,
                'configuration' => [],
            ],
            [
                'node_key' => 'node_welcome',
                'type' => 'message',
                'name' => 'Mensaje de Bienvenida',
                'position_x' => 100.0,
                'position_y' => 180.0,
                'configuration' => [
                    'text' => 'Bienvenido a Conexa Atención al Cliente {{order_id}}',
                ],
            ],
            [
                'node_key' => 'node_menu',
                'type' => 'menu',
                'name' => 'Menú de Opciones',
                'position_x' => 100.0,
                'position_y' => 340.0,
                'configuration' => [
                    'text' => 'Por favor selecciona una opción:',
                    'options' => [
                        ['id' => '1', 'text' => 'Consultar Pedido', 'value' => 'opt_1'],
                        ['id' => '2', 'text' => 'Hablar con Asesor', 'value' => 'opt_2'],
                        ['id' => '3', 'text' => 'Finalizar', 'value' => 'opt_3'],
                    ],
                ],
            ],
            [
                'node_key' => 'node_ask_order',
                'type' => 'question',
                'name' => 'Captura de Pedido',
                'position_x' => -150.0,
                'position_y' => 540.0,
                'configuration' => [
                    'text' => 'Ingresa tu número de pedido:',
                    'variable' => 'order_id',
                    'input_type' => 'text',
                ],
            ],
            [
                'node_key' => 'node_set_vip',
                'type' => 'set_variable',
                'name' => 'Marcar VIP',
                'position_x' => -150.0,
                'position_y' => 700.0,
                'configuration' => [
                    'variable_name' => 'is_vip',
                    'operation' => 'set',
                    'value' => 'true',
                ],
            ],
            [
                'node_key' => 'node_transfer',
                'type' => 'transfer_to_agent',
                'name' => 'Transferir a Humano',
                'position_x' => 120.0,
                'position_y' => 540.0,
                'configuration' => [
                    'message' => 'Derivando con un asesor humano...',
                ],
            ],
            [
                'node_key' => 'node_goto_end',
                'type' => 'goto',
                'name' => 'Salto a Despedida',
                'position_x' => -150.0,
                'position_y' => 860.0,
                'configuration' => [
                    'target_node_key' => 'node_end',
                ],
            ],
            [
                'node_key' => 'node_end',
                'type' => 'end',
                'name' => 'Fin de Conversación',
                'position_x' => 380.0,
                'position_y' => 540.0,
                'configuration' => [
                    'message' => 'Gracias por escribirnos. ¡Hasta luego!',
                ],
            ],
        ];

        $initialEdges = [
            ['source_node_key' => 'node_start', 'target_node_key' => 'node_welcome'],
            ['source_node_key' => 'node_welcome', 'target_node_key' => 'node_menu'],
            ['source_node_key' => 'node_menu', 'target_node_key' => 'node_ask_order', 'condition_handle' => 'opt_1'],
            ['source_node_key' => 'node_menu', 'target_node_key' => 'node_transfer', 'condition_handle' => 'opt_2'],
            ['source_node_key' => 'node_menu', 'target_node_key' => 'node_end', 'condition_handle' => 'opt_3'],
            ['source_node_key' => 'node_ask_order', 'target_node_key' => 'node_set_vip'],
            ['source_node_key' => 'node_set_vip', 'target_node_key' => 'node_goto_end'],
        ];

        // 8. Guardar
        $save1 = $this->actingAs($this->adminUser, 'sanctum')->putJson(
            "/api/v1/flows/{$flowId}/versions/{$versionId}/graph",
            [
                'nodes' => $initialNodes,
                'edges' => $initialEdges,
                'metadata' => [
                    'variables' => $customVars,
                ],
            ]
        );
        $save1->assertStatus(200);

        // 9. Salir del editor
        // 10. Volver a abrir el flujo
        // 11. Verificar estructura y configuración
        $reload1 = $this->actingAs($this->adminUser, 'sanctum')->getJson(
            "/api/v1/flows/{$flowId}/versions/{$versionId}"
        );
        $reload1->assertStatus(200);
        $verData = $reload1->json('data');

        $this->assertCount(8, $verData['nodes']);
        $this->assertCount(7, $verData['edges']);
        $this->assertEquals($customVars, $verData['metadata']['variables']);

        // Check positions survived exactly
        $startNode = collect($verData['nodes'])->firstWhere('node_key', 'node_start');
        $this->assertEquals(100.0, $startNode['position_x']);
        $this->assertEquals(50.0, $startNode['position_y']);

        $menuNode = collect($verData['nodes'])->firstWhere('node_key', 'node_menu');
        $this->assertCount(3, $menuNode['configuration']['options']);

        // 12. Modificar conexiones
        // 13. Eliminar nodos y conexiones
        // Let's remove node_goto_end, and connect node_set_vip directly to node_end
        $modifiedNodes = collect($initialNodes)
            ->filter(fn ($n) => $n['node_key'] !== 'node_goto_end')
            ->values()
            ->all();

        $modifiedEdges = [
            ['source_node_key' => 'node_start', 'target_node_key' => 'node_welcome'],
            ['source_node_key' => 'node_welcome', 'target_node_key' => 'node_menu'],
            ['source_node_key' => 'node_menu', 'target_node_key' => 'node_ask_order', 'condition_handle' => 'opt_1'],
            ['source_node_key' => 'node_menu', 'target_node_key' => 'node_transfer', 'condition_handle' => 'opt_2'],
            ['source_node_key' => 'node_menu', 'target_node_key' => 'node_end', 'condition_handle' => 'opt_3'],
            ['source_node_key' => 'node_ask_order', 'target_node_key' => 'node_set_vip'],
            ['source_node_key' => 'node_set_vip', 'target_node_key' => 'node_end'], // Reconnected directly!
        ];

        // 14. Guardar nuevamente
        $save2 = $this->actingAs($this->adminUser, 'sanctum')->putJson(
            "/api/v1/flows/{$flowId}/versions/{$versionId}/graph",
            [
                'nodes' => $modifiedNodes,
                'edges' => $modifiedEdges,
                'metadata' => [
                    'variables' => $customVars,
                ],
            ]
        );
        $save2->assertStatus(200);

        // 15. Recargar
        $reload2 = $this->actingAs($this->adminUser, 'sanctum')->getJson(
            "/api/v1/flows/{$flowId}/versions/{$versionId}"
        );
        $reload2->assertStatus(200);
        $finalData = $reload2->json('data');

        // 16. Verificar integridad final
        $this->assertCount(7, $finalData['nodes']);
        $this->assertCount(7, $finalData['edges']);
        $this->assertNull(collect($finalData['nodes'])->firstWhere('node_key', 'node_goto_end'));

        // Check no orphaned edges
        $finalNodeKeys = collect($finalData['nodes'])->pluck('node_key')->all();
        foreach ($finalData['edges'] as $edge) {
            $this->assertContains($edge['source_node_key'], $finalNodeKeys, 'Edge source must exist in nodes');
            $this->assertContains($edge['target_node_key'], $finalNodeKeys, 'Edge target must exist in nodes');
        }

        // Validate that flow passes full structural validation
        $validateRes = $this->actingAs($this->adminUser, 'sanctum')->postJson(
            "/api/v1/flows/{$flowId}/versions/{$versionId}/validate"
        );
        $validateRes->assertStatus(200);
        $this->assertTrue($validateRes->json('is_valid'));
        $this->assertEmpty($validateRes->json('errors'));
    }
}
