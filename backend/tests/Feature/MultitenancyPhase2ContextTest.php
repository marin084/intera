<?php

namespace Tests\Feature;

use App\Models\AgentPresenceSession;
use App\Models\Channel;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MultitenancyPhase2ContextTest extends TestCase
{
    use RefreshDatabase;

    private Role $superAdminRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->superAdminRole = Role::where('name', 'super_admin')->firstOrFail();
    }

    public function test_authenticated_business_queries_are_scoped_to_active_workspace(): void
    {
        [$user, $workspaceA, $workspaceB] = $this->makeUserWithTwoWorkspaces();
        $channelA = Channel::create(['workspace_id' => $workspaceA->id, 'name' => 'Canal A', 'type' => 'webchat']);
        $channelB = Channel::create(['workspace_id' => $workspaceB->id, 'name' => 'Canal B', 'type' => 'webchat']);

        $responseA = $this->actingAs($user, 'sanctum')->getJson('/api/v1/channels');
        $responseA->assertOk()->assertJsonPath('data.0.id', $channelA->id);
        $responseA->assertJsonMissing(['id' => $channelB->id]);

        $responseB = $this->withHeader('X-Workspace-Id', $workspaceB->id)
            ->actingAs($user, 'sanctum')
            ->getJson('/api/v1/channels');
        $responseB->assertOk()->assertJsonPath('data.0.id', $channelB->id);
        $responseB->assertJsonMissing(['id' => $channelA->id]);
    }

    public function test_workspace_header_must_be_an_active_membership(): void
    {
        [$user, $workspaceA] = $this->makeUserWithTwoWorkspaces();
        $workspaceOutside = Workspace::create(['name' => 'Outside', 'slug' => 'outside']);

        $response = $this->withHeader('X-Workspace-Id', $workspaceOutside->id)
            ->actingAs($user, 'sanctum')
            ->getJson('/api/v1/channels');

        $response->assertForbidden()->assertJsonPath('code', 'workspace_unauthorized');
        $this->assertSame($workspaceA->id, $user->active_workspace_id);
    }

    public function test_switching_workspace_updates_active_workspace_and_ends_old_presence(): void
    {
        [$user, $workspaceA, $workspaceB] = $this->makeUserWithTwoWorkspaces();
        $session = AgentPresenceSession::create([
            'workspace_id' => $workspaceA->id,
            'user_id' => $user->id,
            'session_key' => hash('sha256', 'browser-session'),
            'started_at' => now()->subMinute(),
            'last_heartbeat_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/workspaces/switch', ['workspace_id' => $workspaceB->id]);

        $response->assertOk()
            ->assertJsonPath('active_workspace_id', $workspaceB->id)
            ->assertJsonPath('data.id', $workspaceB->id);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'active_workspace_id' => $workspaceB->id]);
        $this->assertNotNull($session->fresh()->ended_at);
    }

    public function test_workspace_permissions_do_not_fall_back_to_legacy_roles(): void
    {
        [$user, $workspaceA, $workspaceB] = $this->makeUserWithTwoWorkspaces();
        $agentRole = Role::where('name', 'agent')->firstOrFail();
        WorkspaceUser::where('workspace_id', $workspaceB->id)->where('user_id', $user->id)->update([
            'role_id' => $agentRole->id,
        ]);

        $response = $this->withHeader('X-Workspace-Id', $workspaceB->id)
            ->actingAs($user, 'sanctum')
            ->getJson('/api/v1/flows');

        $response->assertForbidden();
    }

    /** @return array{0: User, 1: Workspace, 2: Workspace} */
    private function makeUserWithTwoWorkspaces(): array
    {
        $user = User::create([
            'name' => 'Workspace Admin',
            'email' => 'workspace-admin@example.com',
            'password' => Hash::make('SecurePassword2026!'),
            'is_active' => true,
        ]);
        $user->roles()->attach($this->superAdminRole);

        $workspaceA = Workspace::create(['name' => 'Workspace A', 'slug' => 'workspace-a']);
        $workspaceB = Workspace::create(['name' => 'Workspace B', 'slug' => 'workspace-b']);

        WorkspaceUser::create([
            'workspace_id' => $workspaceA->id,
            'user_id' => $user->id,
            'role_id' => $this->superAdminRole->id,
            'is_active' => true,
        ]);
        WorkspaceUser::create([
            'workspace_id' => $workspaceB->id,
            'user_id' => $user->id,
            'role_id' => $this->superAdminRole->id,
            'is_active' => true,
        ]);
        $user->update(['active_workspace_id' => $workspaceA->id]);

        return [$user->fresh(), $workspaceA, $workspaceB];
    }
}
