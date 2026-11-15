<?php

namespace Tests\Feature;

use App\Enums\FlowNodeType;
use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowPublication;
use App\Models\FlowVersion;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowPublicationVersioningTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $flowManagerUser;

    protected User $agentUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::factory()->create();
        $this->adminUser->roles()->attach(Role::where('name', 'super_admin')->first());

        $this->flowManagerUser = User::factory()->create();
        $this->flowManagerUser->roles()->attach(Role::where('name', 'flow_manager')->first());

        $this->agentUser = User::factory()->create();
        $this->agentUser->roles()->attach(Role::where('name', 'agent')->first());
    }

    protected function createValidGraph(FlowVersion $version, string $msgText = 'Hola mundo'): void
    {
        $version->nodes()->delete();
        $version->edges()->delete();

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
            'position_x' => 100,
            'position_y' => 100,
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_1',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Mensaje Bienvenida',
            'configuration' => ['text' => $msgText],
            'position_x' => 100,
            'position_y' => 200,
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'end_1',
            'type' => FlowNodeType::END,
            'name' => 'Fin',
            'configuration' => ['message' => 'Adios'],
            'position_x' => 100,
            'position_y' => 300,
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start_1',
            'target_node_key' => 'msg_1',
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'msg_1',
            'target_node_key' => 'end_1',
        ]);
    }

    public function test_create_initial_draft_version(): void
    {
        $response = $this->actingAs($this->flowManagerUser, 'sanctum')
            ->postJson('/api/v1/flows', [
                'name' => 'Flujo de Ventas',
                'description' => 'Flujo para atender prospectos',
            ]);

        $response->assertStatus(201);
        $flowId = $response->json('data.id');

        $flow = Flow::with('versions.nodes')->find($flowId);
        $this->assertNotNull($flow);
        $this->assertEquals(FlowStatus::DRAFT, $flow->status);
        $this->assertCount(1, $flow->versions);

        $v1 = $flow->versions->first();
        $this->assertEquals(1, $v1->version_number);
        $this->assertEquals(FlowVersionStatus::DRAFT, $v1->status);
        $this->assertCount(1, $v1->nodes);
        $this->assertEquals(FlowNodeType::START, $v1->nodes->first()->type);
    }

    public function test_clone_version_preserves_nodes_edges_and_variables(): void
    {
        $flow = Flow::create([
            'name' => 'Flujo Soporte',
            'status' => FlowStatus::DRAFT,
            'created_by' => $this->flowManagerUser->id,
        ]);

        $v1 = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::DRAFT,
            'metadata' => [
                'variables' => [
                    ['name' => 'user_tier', 'type' => 'string', 'description' => 'Nivel de cliente'],
                ],
            ],
        ]);
        $this->createValidGraph($v1, 'Bienvenido al soporte técnico');

        $response = $this->actingAs($this->flowManagerUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions", [
                'from_version_id' => $v1->id,
                'description' => 'Borrador v2 basado en v1',
            ]);

        $response->assertStatus(201);
        $v2Id = $response->json('data.id');

        $v2 = FlowVersion::with(['nodes', 'edges'])->find($v2Id);
        $this->assertNotNull($v2);
        $this->assertEquals(2, $v2->version_number);
        $this->assertEquals(FlowVersionStatus::DRAFT, $v2->status);
        $this->assertCount(3, $v2->nodes);
        $this->assertCount(2, $v2->edges);
        $this->assertEquals($v1->metadata['variables'], $v2->metadata['variables']);
    }

    public function test_cannot_save_graph_on_published_version(): void
    {
        $flow = Flow::create([
            'name' => 'Flujo Inmutable',
            'status' => FlowStatus::PUBLISHED,
        ]);

        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::PUBLISHED,
        ]);
        $this->createValidGraph($version);

        $response = $this->actingAs($this->flowManagerUser, 'sanctum')
            ->putJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/graph", [
                'nodes' => [
                    [
                        'node_key' => 'start_1',
                        'type' => 'start',
                        'name' => 'Inicio',
                    ],
                ],
                'edges' => [],
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'No se puede modificar directamente una versión ya publicada. Cree una nueva versión borrador para continuar editando.',
        ]);
    }

    public function test_cannot_publish_invalid_graph(): void
    {
        $flow = Flow::create(['name' => 'Flujo Inválido', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::DRAFT,
        ]);

        // Start node without outgoing edge is invalid
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
        ]);

        $response = $this->actingAs($this->flowManagerUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/publish");

        $response->assertStatus(422);
        $this->assertFalse($response->json('is_valid', true));
        $this->assertNotEmpty($response->json('errors'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'flow.publish_failed',
            'entity_id' => (string) $flow->id,
        ]);

        // Ensure flow status remains draft
        $flow->refresh();
        $this->assertEquals(FlowStatus::DRAFT, $flow->status);
        $this->assertNull($flow->active_version_id);
    }

    public function test_publish_version_transactor_success_and_audited(): void
    {
        $flow = Flow::create(['name' => 'Flujo Válido', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::DRAFT,
        ]);
        $this->createValidGraph($version);

        $response = $this->actingAs($this->flowManagerUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/publish", [
                'notes' => 'Primera publicación oficial',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.active_version_id', $version->id);
        $response->assertJsonPath('data.status', 'published');

        $flow->refresh();
        $version->refresh();

        $this->assertEquals(FlowStatus::PUBLISHED, $flow->status);
        $this->assertEquals($version->id, $flow->active_version_id);
        $this->assertEquals(FlowVersionStatus::PUBLISHED, $version->status);
        $this->assertNotNull($version->published_at);
        $this->assertEquals($this->flowManagerUser->id, $version->published_by);

        // Check publication history record
        $this->assertDatabaseHas('flow_publications', [
            'flow_id' => $flow->id,
            'flow_version_id' => $version->id,
            'action' => 'publish',
            'notes' => 'Primera publicación oficial',
        ]);

        // Check audit log
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'flow.publish',
            'entity_id' => (string) $flow->id,
        ]);
    }

    public function test_publish_version_is_idempotent(): void
    {
        $flow = Flow::create(['name' => 'Flujo Idempotente', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::DRAFT,
        ]);
        $this->createValidGraph($version);

        // First publication
        $res1 = $this->actingAs($this->flowManagerUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/publish");
        $res1->assertStatus(200);

        $initialPublicationsCount = FlowPublication::where('flow_id', $flow->id)->count();
        $this->assertEquals(1, $initialPublicationsCount);

        // Repeated publication (double click / network retry)
        $res2 = $this->actingAs($this->flowManagerUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/publish");
        $res2->assertStatus(200);
        $this->assertStringContainsString('ya se encuentra publicada y activa', $res2->json('message'));

        // Publication count should not duplicate
        $this->assertEquals(1, FlowPublication::where('flow_id', $flow->id)->count());
    }

    public function test_only_one_active_version_per_flow(): void
    {
        $flow = Flow::create(['name' => 'Flujo Con Dos Versiones', 'status' => FlowStatus::DRAFT]);
        $v1 = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);
        $this->createValidGraph($v1, 'Mensaje v1');

        $this->actingAs($this->flowManagerUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$v1->id}/publish")
            ->assertStatus(200);

        $flow->refresh();
        $this->assertEquals($v1->id, $flow->active_version_id);

        // Create V2
        $v2 = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 2, 'status' => FlowVersionStatus::DRAFT]);
        $this->createValidGraph($v2, 'Mensaje v2');

        // Publish V2
        $this->actingAs($this->flowManagerUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$v2->id}/publish")
            ->assertStatus(200);

        $flow->refresh();
        $v1->refresh();
        $v2->refresh();

        // Single active version is V2
        $this->assertEquals($v2->id, $flow->active_version_id);
        // Both are immutable published versions in history
        $this->assertEquals(FlowVersionStatus::PUBLISHED, $v1->status);
        $this->assertEquals(FlowVersionStatus::PUBLISHED, $v2->status);
    }

    public function test_rollback_to_historical_version_succeeds_and_audited(): void
    {
        $flow = Flow::create(['name' => 'Flujo Rollback', 'status' => FlowStatus::DRAFT]);
        $v1 = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);
        $this->createValidGraph($v1, 'Version 1');
        $this->actingAs($this->flowManagerUser, 'sanctum')->postJson("/api/v1/flows/{$flow->id}/versions/{$v1->id}/publish");

        $v2 = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 2, 'status' => FlowVersionStatus::DRAFT]);
        $this->createValidGraph($v2, 'Version 2');
        $this->actingAs($this->flowManagerUser, 'sanctum')->postJson("/api/v1/flows/{$flow->id}/versions/{$v2->id}/publish");

        $flow->refresh();
        $this->assertEquals($v2->id, $flow->active_version_id);

        // Perform rollback to V1
        $rollbackRes = $this->actingAs($this->flowManagerUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$v1->id}/rollback", [
                'notes' => 'Incidencia en V2, restaurando V1',
            ]);

        $rollbackRes->assertStatus(200);
        $rollbackRes->assertJsonPath('data.active_version_id', $v1->id);
        $rollbackRes->assertJsonPath('data.active_version_number', 1);

        $flow->refresh();
        $this->assertEquals($v1->id, $flow->active_version_id);

        // Check publication record with rollback action
        $this->assertDatabaseHas('flow_publications', [
            'flow_id' => $flow->id,
            'flow_version_id' => $v1->id,
            'action' => 'rollback',
            'previous_version_id' => $v2->id,
        ]);

        // Check audit log
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'flow.rollback',
            'entity_id' => (string) $flow->id,
        ]);
    }

    public function test_rollback_to_draft_version_is_rejected(): void
    {
        $flow = Flow::create(['name' => 'Flujo Test', 'status' => FlowStatus::DRAFT]);
        $v1 = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);
        $this->createValidGraph($v1);

        $res = $this->actingAs($this->flowManagerUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$v1->id}/rollback");

        $res->assertStatus(422);
        $res->assertJsonFragment([
            'message' => 'Solo se puede realizar rollback hacia una versión previamente publicada e inmutable.',
        ]);
    }

    public function test_cannot_delete_active_or_published_or_in_use_version(): void
    {
        $flow = Flow::create(['name' => 'Flujo Protegido', 'status' => FlowStatus::PUBLISHED]);
        $v1 = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::PUBLISHED]);
        $flow->update(['active_version_id' => $v1->id]);

        // Attempt deleting active version
        $res1 = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/flows/{$flow->id}/versions/{$v1->id}");
        $res1->assertStatus(422);
        $res1->assertJsonFragment(['message' => 'No se puede eliminar la versión activa en producción.']);

        // Historical published version (not active)
        $v2 = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 2, 'status' => FlowVersionStatus::PUBLISHED]);
        $flow->update(['active_version_id' => $v2->id]);

        $res2 = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/flows/{$flow->id}/versions/{$v1->id}");
        $res2->assertStatus(422);
        $this->assertStringContainsString('inmutables', $res2->json('message'));
    }

    public function test_semantic_diff_service_compares_versions(): void
    {
        $flow = Flow::create(['name' => 'Flujo Diff', 'status' => FlowStatus::DRAFT]);
        $v1 = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::PUBLISHED]);
        $this->createValidGraph($v1, 'Mensaje Original');

        $v2 = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 2,
            'status' => FlowVersionStatus::DRAFT,
            'metadata' => [
                'variables' => [
                    ['name' => 'nuevo_dato', 'type' => 'string', 'description' => 'Dato nuevo'],
                ],
            ],
        ]);
        $this->createValidGraph($v2, 'Mensaje Modificado');

        // Add extra node in V2
        FlowNode::create([
            'flow_version_id' => $v2->id,
            'node_key' => 'question_1',
            'type' => FlowNodeType::QUESTION,
            'name' => 'Pregunta DNI',
            'configuration' => ['text' => '¿Tu DNI?', 'variable' => 'dni'],
        ]);

        $response = $this->actingAs($this->flowManagerUser, 'sanctum')
            ->getJson("/api/v1/flows/{$flow->id}/versions/{$v1->id}/compare/{$v2->id}");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertTrue($data['has_changes']);
        $this->assertEquals(1, $data['stats']['nodes_added']); // question_1
        $this->assertEquals(1, $data['stats']['nodes_modified']); // msg_1
        $this->assertNotEmpty($data['highlights']);
    }

    public function test_rbac_agent_cannot_publish_or_rollback(): void
    {
        $flow = Flow::create(['name' => 'Flujo RBAC', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);
        $this->createValidGraph($version);

        // Agent attempt to publish
        $pubRes = $this->actingAs($this->agentUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/publish");
        $pubRes->assertStatus(403);

        // Agent attempt to rollback
        $rbRes = $this->actingAs($this->agentUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/rollback");
        $rbRes->assertStatus(403);
    }
}
