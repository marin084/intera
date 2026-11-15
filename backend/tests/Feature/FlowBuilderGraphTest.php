<?php

namespace Tests\Feature;

use App\Enums\FlowNodeType;
use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FlowBuilderGraphTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::create([
            'name' => 'Admin Builder',
            'email' => 'admin_builder@test.com',
            'password' => Hash::make('TestSecuredPass@2026'),
            'is_active' => true,
        ]);

        $this->adminUser->roles()->attach(Role::where('name', 'super_admin')->first());
    }

    public function test_can_save_graph_with_custom_positions_and_configs(): void
    {
        $flow = Flow::create([
            'name' => 'Flujo con Posiciones',
            'status' => FlowStatus::DRAFT,
            'channel_applicability' => ['webchat'],
        ]);

        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::DRAFT,
        ]);

        $payload = [
            'nodes' => [
                [
                    'node_key' => 'start_1',
                    'type' => 'start',
                    'name' => 'Inicio',
                    'position_x' => 120.5,
                    'position_y' => 80.0,
                    'configuration' => [],
                ],
                [
                    'node_key' => 'msg_1',
                    'type' => 'message',
                    'name' => 'Bienvenida',
                    'position_x' => 120.5,
                    'position_y' => 250.0,
                    'configuration' => ['text' => 'Hola {{nombre}}'],
                ],
                [
                    'node_key' => 'end_1',
                    'type' => 'end',
                    'name' => 'Fin',
                    'position_x' => 120.5,
                    'position_y' => 450.0,
                    'configuration' => ['message' => 'Adiós'],
                ],
            ],
            'edges' => [
                [
                    'source_node_key' => 'start_1',
                    'target_node_key' => 'msg_1',
                ],
                [
                    'source_node_key' => 'msg_1',
                    'target_node_key' => 'end_1',
                ],
            ],
        ];

        $response = $this->actingAs($this->adminUser, 'sanctum')->putJson(
            "/api/v1/flows/{$flow->id}/versions/{$version->id}/graph",
            $payload
        );

        $response->assertStatus(200);

        // Verify database persistence
        $this->assertDatabaseHas('flow_nodes', [
            'flow_version_id' => $version->id,
            'node_key' => 'start_1',
            'position_x' => 120.5,
            'position_y' => 80.0,
        ]);

        $this->assertDatabaseHas('flow_nodes', [
            'flow_version_id' => $version->id,
            'node_key' => 'msg_1',
            'position_x' => 120.5,
            'position_y' => 250.0,
        ]);

        $this->assertDatabaseHas('flow_edges', [
            'flow_version_id' => $version->id,
            'source_node_key' => 'start_1',
            'target_node_key' => 'msg_1',
        ]);
    }

    public function test_cannot_save_graph_with_invalid_node_type(): void
    {
        $flow = Flow::create(['name' => 'Flow Invalid Node', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);

        $payload = [
            'nodes' => [
                [
                    'node_key' => 'node_hack',
                    'type' => 'unsupported_node_type_injection',
                    'name' => 'Bad Node',
                ],
            ],
            'edges' => [],
        ];

        $response = $this->actingAs($this->adminUser, 'sanctum')->putJson(
            "/api/v1/flows/{$flow->id}/versions/{$version->id}/graph",
            $payload
        );

        $response->assertStatus(422);
    }

    public function test_cannot_save_graph_with_edges_referencing_non_existent_nodes(): void
    {
        $flow = Flow::create(['name' => 'Flow Foreign Edges', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);

        $payload = [
            'nodes' => [
                [
                    'node_key' => 'start_1',
                    'type' => 'start',
                    'name' => 'Start',
                ],
            ],
            'edges' => [
                [
                    'source_node_key' => 'start_1',
                    'target_node_key' => 'foreign_node_not_in_payload',
                ],
            ],
        ];

        $response = $this->actingAs($this->adminUser, 'sanctum')->putJson(
            "/api/v1/flows/{$flow->id}/versions/{$version->id}/graph",
            $payload
        );

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Las conexiones contienen referencias a nodos inexistentes o de otra versión.',
        ]);
    }

    public function test_cannot_save_graph_with_duplicate_node_keys(): void
    {
        $flow = Flow::create(['name' => 'Flow Duplicate Keys', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);

        $payload = [
            'nodes' => [
                [
                    'node_key' => 'dup_1',
                    'type' => 'message',
                    'name' => 'Message 1',
                    'configuration' => ['text' => 'Hi'],
                ],
                [
                    'node_key' => 'dup_1',
                    'type' => 'message',
                    'name' => 'Message 2',
                    'configuration' => ['text' => 'Hi again'],
                ],
            ],
            'edges' => [],
        ];

        $response = $this->actingAs($this->adminUser, 'sanctum')->putJson(
            "/api/v1/flows/{$flow->id}/versions/{$version->id}/graph",
            $payload
        );

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'El grafo contiene identificadores de nodo (node_key) duplicados.',
        ]);
    }

    public function test_graph_save_is_atomic_and_rolls_back_on_failure(): void
    {
        $flow = Flow::create(['name' => 'Flow Atomic', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'initial_node',
            'type' => FlowNodeType::START,
            'name' => 'Original Node',
        ]);

        // Malformed payload that fails validation
        $payload = [
            'nodes' => [
                [
                    'node_key' => 'new_start',
                    'type' => 'invalid_type',
                    'name' => 'New Start',
                ],
            ],
            'edges' => [],
        ];

        $response = $this->actingAs($this->adminUser, 'sanctum')->putJson(
            "/api/v1/flows/{$flow->id}/versions/{$version->id}/graph",
            $payload
        );

        $response->assertStatus(422);

        // Verify original node is preserved untouched
        $this->assertDatabaseHas('flow_nodes', [
            'flow_version_id' => $version->id,
            'node_key' => 'initial_node',
        ]);
    }

    public function test_concurrency_conflict_returns_409_when_updated_at_mismatch(): void
    {
        $flow = Flow::create(['name' => 'Flow Concurrency', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);

        $payload = [
            'expected_updated_at' => '2020-01-01T00:00:00Z', // Outdated timestamp
            'nodes' => [
                [
                    'node_key' => 'start_1',
                    'type' => 'start',
                    'name' => 'Start',
                ],
            ],
            'edges' => [],
        ];

        $response = $this->actingAs($this->adminUser, 'sanctum')->putJson(
            "/api/v1/flows/{$flow->id}/versions/{$version->id}/graph",
            $payload
        );

        $response->assertStatus(409);
        $response->assertJson([
            'conflict' => true,
        ]);
    }

    public function test_validate_endpoint_returns_structured_errors_with_node_keys(): void
    {
        $flow = Flow::create(['name' => 'Flow Validate', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);

        // Start node without outgoing edge
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'name' => 'Start',
        ]);

        // Question missing variable and edge
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'q_orphan',
            'type' => FlowNodeType::QUESTION,
            'name' => 'Pregunta Huérfana',
            'configuration' => [
                'text' => '¿Cuál es tu color favorito?',
                'variable' => '',
            ],
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')->postJson(
            "/api/v1/flows/{$flow->id}/versions/{$version->id}/validate"
        );

        $response->assertStatus(200);
        $response->assertJson([
            'valid' => false,
            'is_valid' => false,
        ]);

        $data = $response->json();
        $this->assertNotEmpty($data['structured_errors']);

        $codes = collect($data['structured_errors'])->pluck('code')->all();
        $this->assertContains('START_MISSING_EDGE', $codes);
        $this->assertContains('QUESTION_VARIABLE_REQUIRED', $codes);
        $this->assertContains('NODE_UNREACHABLE', $codes);

        $keys = collect($data['structured_errors'])->pluck('node_key')->filter()->all();
        $this->assertContains('start_1', $keys);
        $this->assertContains('q_orphan', $keys);
    }

    public function test_question_node_with_invalid_variable_name_fails_validation(): void
    {
        $flow = Flow::create(['name' => 'Flow Var Validate', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'name' => 'Start',
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'q_invalid_var',
            'type' => FlowNodeType::QUESTION,
            'name' => 'Pregunta',
            'configuration' => [
                'text' => '¿Tu nombre?',
                'variable' => 'nombre con espacios y $imbolos',
            ],
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'end_1',
            'type' => FlowNodeType::END,
            'name' => 'End',
        ]);

        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'start_1', 'target_node_key' => 'q_invalid_var']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'q_invalid_var', 'target_node_key' => 'end_1']);

        $response = $this->actingAs($this->adminUser, 'sanctum')->postJson(
            "/api/v1/flows/{$flow->id}/versions/{$version->id}/validate"
        );

        $response->assertStatus(200);
        $response->assertJson(['valid' => false]);

        $codes = collect($response->json('structured_errors'))->pluck('code')->all();
        $this->assertContains('QUESTION_INVALID_VARIABLE_NAME', $codes);
    }

    public function test_clone_version_duplicates_nodes_configs_positions_and_edges(): void
    {
        $flow = Flow::create(['name' => 'Flow Clone', 'status' => FlowStatus::DRAFT]);
        $v1 = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::PUBLISHED]);

        FlowNode::create([
            'flow_version_id' => $v1->id,
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'name' => 'Start',
            'position_x' => 150.0,
            'position_y' => 200.0,
        ]);
        FlowNode::create([
            'flow_version_id' => $v1->id,
            'node_key' => 'end_1',
            'type' => FlowNodeType::END,
            'name' => 'End',
            'position_x' => 150.0,
            'position_y' => 400.0,
        ]);
        FlowEdge::create([
            'flow_version_id' => $v1->id,
            'source_node_key' => 'start_1',
            'target_node_key' => 'end_1',
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')->postJson(
            "/api/v1/flows/{$flow->id}/versions",
            ['from_version_id' => $v1->id, 'description' => 'Draft cloned from V1']
        );

        $response->assertStatus(201);
        $newVersionId = $response->json('data.id');

        $this->assertDatabaseHas('flow_versions', [
            'id' => $newVersionId,
            'version_number' => 2,
            'status' => 'draft',
        ]);

        $this->assertDatabaseHas('flow_nodes', [
            'flow_version_id' => $newVersionId,
            'node_key' => 'start_1',
            'position_x' => 150.0,
            'position_y' => 200.0,
        ]);

        $this->assertDatabaseHas('flow_edges', [
            'flow_version_id' => $newVersionId,
            'source_node_key' => 'start_1',
            'target_node_key' => 'end_1',
        ]);
    }

    public function test_can_save_and_retrieve_version_metadata_variables(): void
    {
        $flow = Flow::create([
            'name' => 'Flujo con Variables Personalizadas',
            'status' => FlowStatus::DRAFT,
            'channel_applicability' => ['webchat'],
        ]);

        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::DRAFT,
        ]);

        $customVariables = [
            ['name' => 'numero_pedido', 'type' => 'text', 'scope' => 'flow', 'description' => 'ID de pedido del cliente'],
            ['name' => 'total_carrito', 'type' => 'number', 'scope' => 'flow', 'description' => 'Monto acumulado'],
        ];

        $payload = [
            'nodes' => [
                [
                    'node_key' => 'start_1',
                    'type' => 'start',
                    'name' => 'Inicio',
                    'position_x' => 100,
                    'position_y' => 100,
                    'configuration' => [],
                ],
                [
                    'node_key' => 'end_1',
                    'type' => 'end',
                    'name' => 'Fin',
                    'position_x' => 100,
                    'position_y' => 300,
                    'configuration' => [],
                ],
            ],
            'edges' => [
                [
                    'source_node_key' => 'start_1',
                    'target_node_key' => 'end_1',
                ],
            ],
            'metadata' => [
                'variables' => $customVariables,
            ],
        ];

        $saveResponse = $this->actingAs($this->adminUser, 'sanctum')->putJson(
            "/api/v1/flows/{$flow->id}/versions/{$version->id}/graph",
            $payload
        );

        $saveResponse->assertStatus(200);

        // Fetch version and verify metadata is returned correctly
        $showResponse = $this->actingAs($this->adminUser, 'sanctum')->getJson(
            "/api/v1/flows/{$flow->id}/versions/{$version->id}"
        );

        $showResponse->assertStatus(200);
        $savedMetadata = $showResponse->json('data.metadata');
        $this->assertNotNull($savedMetadata);
        $this->assertEquals($customVariables, $savedMetadata['variables']);
    }

    public function test_clone_version_preserves_metadata(): void
    {
        $flow = Flow::create([
            'name' => 'Flujo Clonacion Metadata',
            'status' => FlowStatus::DRAFT,
            'channel_applicability' => ['webchat'],
        ]);

        $v1 = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::DRAFT,
            'metadata' => [
                'variables' => [
                    ['name' => 'cliente_vip', 'type' => 'boolean', 'scope' => 'flow'],
                ],
            ],
        ]);

        FlowNode::create([
            'flow_version_id' => $v1->id,
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'name' => 'Start',
            'position_x' => 150.0,
            'position_y' => 200.0,
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')->postJson(
            "/api/v1/flows/{$flow->id}/versions",
            ['from_version_id' => $v1->id, 'description' => 'Draft cloned with metadata']
        );

        $response->assertStatus(201);
        $newVersionId = $response->json('data.id');

        $newVersion = FlowVersion::findOrFail($newVersionId);
        $this->assertNotNull($newVersion->metadata);
        $this->assertEquals('cliente_vip', $newVersion->metadata['variables'][0]['name']);
    }

    public function test_unauthorized_agent_cannot_save_graph_or_publish(): void
    {
        $agent = User::create([
            'name' => 'Agent Role User',
            'email' => 'agent_builder_test@test.com',
            'password' => Hash::make('TestSecuredPass@2026'),
            'is_active' => true,
        ]);
        $agent->roles()->attach(Role::where('name', 'agent')->first());

        $flow = Flow::create([
            'name' => 'Flujo Protegido',
            'status' => FlowStatus::DRAFT,
        ]);

        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::DRAFT,
        ]);

        // Agent cannot save graph (requires flows.edit)
        $this->actingAs($agent, 'sanctum')
            ->putJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/graph", [
                'nodes' => [
                    ['node_key' => 's1', 'type' => 'start', 'name' => 'S', 'position_x' => 0, 'position_y' => 0],
                ],
                'edges' => [],
            ])
            ->assertStatus(403);

        // Agent cannot publish version (requires flows.publish)
        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/publish")
            ->assertStatus(403);
    }
}
