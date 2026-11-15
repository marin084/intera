<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowPublication;
use App\Models\FlowSimulationSession;
use App\Models\FlowVersion;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class FlowDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $agentUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::create([
            'name' => 'Admin Deletion Tester',
            'email' => 'admin.deletion@conexa.test',
            'password' => Hash::make('Secret123456!'),
            'is_active' => true,
        ]);
        $this->adminUser->roles()->attach(Role::where('name', 'super_admin')->first());

        $this->agentUser = User::create([
            'name' => 'Agent No Delete',
            'email' => 'agent.nodelete@conexa.test',
            'password' => Hash::make('Secret123456!'),
            'is_active' => true,
        ]);
        $this->agentUser->roles()->attach(Role::where('name', 'agent')->first());
    }

    public function test_cannot_delete_flow_assigned_to_channel(): void
    {
        $flow = Flow::create([
            'name' => 'Flow In Channel',
            'channel_applicability' => ['webchat'],
        ]);

        $channel = Channel::create([
            'name' => 'Canal Webchat Principal',
            'type' => ChannelType::WEBCHAT,
            'is_active' => false,
            'entry_flow_id' => $flow->id,
            'configuration' => [],
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/flows/{$flow->id}");

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => "No se puede eliminar este flujo porque está configurado como flujo de entrada en los siguientes canales: {$channel->name}. Cambia o desvincula el flujo en esos canales primero.",
        ]);
        $this->assertContains($channel->name, $response->json('blocking_channels'));
        $this->assertDatabaseHas('flows', ['id' => $flow->id]);
    }

    public function test_requires_force_flag_if_flow_has_conversations(): void
    {
        $flow = Flow::create([
            'name' => 'Flow With Conversation',
            'channel_applicability' => ['webchat'],
        ]);

        $channel = Channel::create([
            'name' => 'Canal Soporte',
            'type' => ChannelType::WEBCHAT,
            'is_active' => false,
            'configuration' => [],
        ]);

        $contact = Contact::create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'identifier' => 'test-user-1',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
            'active_flow_id' => $flow->id,
        ]);

        // Attempt delete without force
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/flows/{$flow->id}");

        $response->assertStatus(422);
        $response->assertJson([
            'requires_force' => true,
            'conversations_count' => 1,
        ]);
        $this->assertDatabaseHas('flows', ['id' => $flow->id]);

        // Now delete with force: true
        $forceResponse = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/flows/{$flow->id}", ['force' => true]);

        $forceResponse->assertStatus(200);
        $this->assertDatabaseMissing('flows', ['id' => $flow->id]);

        // Ensure conversation has flow detached cleanly
        $conversation->refresh();
        $this->assertNull($conversation->active_flow_id);
    }

    public function test_can_delete_flow_with_complete_cascade_cleanup_and_audit(): void
    {
        $flow = Flow::create([
            'name' => 'Flow Full Lifecycle',
            'channel_applicability' => ['webchat'],
        ]);

        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => 'draft',
            'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
        ]);

        // Set active version (circular FK)
        $flow->update(['active_version_id' => $version->id]);

        $node1 = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start-node',
            'type' => 'start',
            'name' => 'Inicio',
            'position_x' => 100,
            'position_y' => 100,
            'configuration' => [],
        ]);

        $node2 = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg-node',
            'type' => 'message',
            'name' => 'Mensaje',
            'position_x' => 200,
            'position_y' => 200,
            'configuration' => ['text' => 'Hola'],
        ]);

        $node3 = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'end-node',
            'type' => 'end',
            'name' => 'Fin',
            'position_x' => 300,
            'position_y' => 300,
            'configuration' => ['message' => 'Adiós'],
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start-node',
            'target_node_key' => 'msg-node',
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'msg-node',
            'target_node_key' => 'end-node',
        ]);

        FlowPublication::create([
            'flow_id' => $flow->id,
            'flow_version_id' => $version->id,
            'publisher_id' => $this->adminUser->id,
            'summary' => 'Initial release',
            'published_at' => now(),
        ]);

        $simRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/start");
        $simRes->assertOk();

        $this->assertDatabaseHas('flow_simulation_sessions', ['flow_id' => $flow->id]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/flows/{$flow->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'message' => "Flujo 'Flow Full Lifecycle' eliminado exitosamente.",
        ]);

        // Verify database cascade cleanup
        $this->assertDatabaseMissing('flows', ['id' => $flow->id]);
        $this->assertDatabaseMissing('flow_versions', ['id' => $version->id]);
        $this->assertDatabaseMissing('flow_nodes', ['id' => $node1->id]);
        $this->assertDatabaseMissing('flow_nodes', ['id' => $node2->id]);
        $this->assertDatabaseMissing('flow_publications', ['flow_id' => $flow->id]);
        $this->assertDatabaseMissing('flow_simulation_sessions', ['flow_id' => $flow->id]);

        // Verify audit log
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'flow.deleted',
            'entity_type' => 'Flow',
            'entity_id' => $flow->id,
        ]);
    }

    public function test_agent_without_permission_cannot_delete_flow(): void
    {
        $flow = Flow::create([
            'name' => 'Forbidden Flow',
            'channel_applicability' => ['webchat'],
        ]);

        $response = $this->actingAs($this->agentUser, 'sanctum')
            ->deleteJson("/api/v1/flows/{$flow->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('flows', ['id' => $flow->id]);
    }
}
