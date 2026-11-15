<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\Role;
use App\Models\SavedFunnel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedFunnelTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $agent;
    protected Flow $flow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $superAdminRole = Role::where('name', 'super_admin')->first();
        $agentRole = Role::where('name', 'agent')->first();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->roles()->sync([$superAdminRole->id]);

        $this->agent = User::factory()->create(['is_active' => true]);
        $this->agent->roles()->sync([$agentRole->id]);

        $this->flow = Flow::create([
            'name' => 'Flujo de Prueba',
            'status' => 'published',
        ]);
    }

    public function test_admin_can_list_create_update_and_delete_saved_funnels(): void
    {
        // 1. Create
        $createPayload = [
            'name' => 'Ruta Ofrendas a Peticiones',
            'flow_id' => $this->flow->id,
            'steps' => [
                [
                    'id' => 'step-1',
                    'label' => 'Inicio',
                    'node_id' => 'node-1',
                    'node_key' => 'start',
                    'is_active' => true,
                ],
                [
                    'id' => 'step-2',
                    'label' => 'Menú › Ofrendar',
                    'node_id' => 'node-2',
                    'node_key' => 'menu',
                    'option_id' => 'opt-ofrendar',
                    'option_title' => 'Ofrendar',
                    'is_active' => true,
                ],
            ],
        ];

        $createResponse = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/analytics/funnels/saved', $createPayload);

        $createResponse->assertCreated();
        $funnelId = $createResponse->json('data.id');
        $this->assertNotEmpty($funnelId);
        $this->assertEquals('Ruta Ofrendas a Peticiones', $createResponse->json('data.name'));
        $this->assertCount(2, $createResponse->json('data.steps'));
        $this->assertEquals($this->admin->id, $createResponse->json('data.created_by'));

        // 2. List
        $listResponse = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/analytics/funnels/saved?flow_id='.$this->flow->id);

        $listResponse->assertOk();
        $this->assertCount(1, $listResponse->json('data'));
        $this->assertEquals($funnelId, $listResponse->json('data.0.id'));

        // 3. Update
        $updatePayload = [
            'name' => 'Ruta Actualizada',
            'steps' => [
                [
                    'id' => 'step-1',
                    'label' => 'Inicio Modificado',
                    'node_id' => 'node-1',
                    'node_key' => 'start',
                    'is_active' => true,
                ],
            ],
        ];

        $updateResponse = $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/analytics/funnels/saved/'.$funnelId, $updatePayload);

        $updateResponse->assertOk();
        $this->assertEquals('Ruta Actualizada', $updateResponse->json('data.name'));
        $this->assertCount(1, $updateResponse->json('data.steps'));

        // 4. Delete
        $deleteResponse = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson('/api/v1/analytics/funnels/saved/'.$funnelId);

        $deleteResponse->assertOk();
        $this->assertDatabaseMissing('saved_funnels', ['id' => $funnelId]);
    }

    public function test_saved_funnels_can_be_filtered_by_flow_id(): void
    {
        $flow2 = Flow::create([
            'name' => 'Otro Flujo',
            'status' => 'published',
        ]);

        SavedFunnel::create([
            'name' => 'Funnel Flujo 1',
            'flow_id' => $this->flow->id,
            'steps' => [['label' => 'Paso A']],
        ]);

        SavedFunnel::create([
            'name' => 'Funnel Flujo 2',
            'flow_id' => $flow2->id,
            'steps' => [['label' => 'Paso B']],
        ]);

        $resp1 = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/analytics/funnels/saved?flow_id='.$this->flow->id);

        $resp1->assertOk();
        $this->assertCount(1, $resp1->json('data'));
        $this->assertEquals('Funnel Flujo 1', $resp1->json('data.0.name'));
    }

    public function test_agent_user_is_forbidden_from_managing_saved_funnels(): void
    {
        $funnel = SavedFunnel::create([
            'name' => 'Funnel Protegido',
            'flow_id' => $this->flow->id,
            'steps' => [['label' => 'Paso Inicial']],
        ]);

        // List
        $this->actingAs($this->agent, 'sanctum')
            ->getJson('/api/v1/analytics/funnels/saved')
            ->assertForbidden();

        // Create
        $this->actingAs($this->agent, 'sanctum')
            ->postJson('/api/v1/analytics/funnels/saved', [
                'name' => 'Intento Agente',
                'flow_id' => $this->flow->id,
                'steps' => [['label' => 'X']],
            ])
            ->assertForbidden();

        // Update
        $this->actingAs($this->agent, 'sanctum')
            ->putJson('/api/v1/analytics/funnels/saved/'.$funnel->id, [
                'name' => 'Nombre Cambiado',
            ])
            ->assertForbidden();

        // Delete
        $this->actingAs($this->agent, 'sanctum')
            ->deleteJson('/api/v1/analytics/funnels/saved/'.$funnel->id)
            ->assertForbidden();
    }
}
