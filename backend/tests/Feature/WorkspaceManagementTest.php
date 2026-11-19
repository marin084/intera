<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WorkspaceManagementTest extends TestCase
{
    use RefreshDatabase;

    private Role $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->superAdmin = Role::where('name', 'super_admin')->firstOrFail();
    }

    public function test_platform_admin_can_create_workspace_and_becomes_owner(): void
    {
        $admin = $this->makeUser('owner@example.com', true);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/workspaces', [
            'name' => 'Ventas Costa Rica',
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'Ventas Costa Rica')->assertJsonPath('data.slug', 'ventas-costa-rica');
        $workspace = Workspace::where('slug', 'ventas-costa-rica')->firstOrFail();
        $this->assertSame($admin->id, $workspace->initial_owner_id);
        $this->assertDatabaseHas('workspace_user', ['workspace_id' => $workspace->id, 'user_id' => $admin->id, 'role_id' => $this->superAdmin->id, 'is_active' => true]);
    }

    public function test_non_administrator_cannot_create_or_manage_workspace_members(): void
    {
        $agentRole = Role::where('name', 'agent')->firstOrFail();
        $agent = $this->makeUser('agent@example.com', false, 'agent');
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'initial_owner_id' => $agent->id]);
        WorkspaceUser::create(['workspace_id' => $workspace->id, 'user_id' => $agent->id, 'role_id' => $agentRole->id, 'is_active' => true]);

        $this->actingAs($agent, 'sanctum')->postJson('/api/v1/workspaces', ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($agent, 'sanctum')->getJson("/api/v1/workspaces/{$workspace->id}/members")->assertForbidden();
    }

    public function test_platform_admin_lists_all_workspaces_with_member_counts(): void
    {
        $admin = $this->makeUser('platform@example.com', true);
        $workspaceA = Workspace::create(['name' => 'A', 'slug' => 'workspace-a', 'initial_owner_id' => $admin->id]);
        $workspaceB = Workspace::create(['name' => 'B', 'slug' => 'workspace-b']);
        WorkspaceUser::create(['workspace_id' => $workspaceA->id, 'user_id' => $admin->id, 'role_id' => $this->superAdmin->id, 'is_active' => true]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/workspaces');

        $response->assertOk()->assertJsonCount(3, 'data');
        $workspacePayload = collect($response->json('data'))->keyBy('id');
        $this->assertSame(1, $workspacePayload[$workspaceA->id]['member_count']);
        $this->assertSame(0, $workspacePayload[$workspaceB->id]['member_count']);
    }

    public function test_workspace_manager_can_add_update_and_deactivate_existing_user(): void
    {
        $owner = $this->makeUser('manager@example.com');
        $workspace = Workspace::create(['name' => 'Support', 'slug' => 'support', 'initial_owner_id' => $owner->id]);
        WorkspaceUser::create(['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'role_id' => $this->superAdmin->id, 'is_active' => true]);
        $member = $this->makeUser('member@example.com');

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/workspaces/{$workspace->id}/members", ['email' => $member->email, 'role' => 'agent'])->assertCreated();
        $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/workspaces/{$workspace->id}/members/{$member->id}", ['role' => 'supervisor'])->assertOk()->assertJsonPath('data.role.name', 'supervisor');
        $this->actingAs($owner, 'sanctum')->deleteJson("/api/v1/workspaces/{$workspace->id}/members/{$member->id}")->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('workspace_user', ['workspace_id' => $workspace->id, 'user_id' => $member->id, 'is_active' => false]);
    }

    public function test_workspace_admin_only_sees_and_manages_assigned_workspace(): void
    {
        $workspaceAdmin = User::create([
            'name' => 'Workspace Admin',
            'email' => 'workspace-admin@example.com',
            'password' => Hash::make('SecurePassword2026!'),
            'is_active' => true,
            'is_platform_admin' => false,
        ]);
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $workspaceA = Workspace::create(['name' => 'Workspace A', 'slug' => 'workspace-a', 'initial_owner_id' => $workspaceAdmin->id]);
        $workspaceB = Workspace::create(['name' => 'Workspace B', 'slug' => 'workspace-b']);
        WorkspaceUser::create(['workspace_id' => $workspaceA->id, 'user_id' => $workspaceAdmin->id, 'role_id' => $adminRole->id, 'is_active' => true]);

        $response = $this->actingAs($workspaceAdmin, 'sanctum')->getJson('/api/v1/workspaces');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $workspaceA->id);
        $this->actingAs($workspaceAdmin, 'sanctum')->getJson("/api/v1/workspaces/{$workspaceB->id}/members")->assertForbidden();
    }

    public function test_workspace_cannot_remove_owner_or_last_active_super_admin(): void
    {
        $owner = $this->makeUser('owner-safety@example.com');
        $workspace = Workspace::create(['name' => 'Safety', 'slug' => 'safety', 'initial_owner_id' => $owner->id]);
        WorkspaceUser::create(['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'role_id' => $this->superAdmin->id, 'is_active' => true]);

        $response = $this->actingAs($owner, 'sanctum')->deleteJson("/api/v1/workspaces/{$workspace->id}/members/{$owner->id}");

        $response->assertStatus(422)->assertJsonPath('message', 'El propietario inicial debe conservar acceso activo como administrador del workspace.');
        $this->assertDatabaseHas('workspace_user', ['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'is_active' => true]);
    }

    private function makeUser(string $email, bool $platformAdmin = false, string $roleName = 'super_admin'): User
    {
        $user = User::create(['name' => ucfirst(strstr($email, '@', true)), 'email' => $email, 'password' => Hash::make('SecurePassword2026!'), 'is_active' => true, 'is_platform_admin' => $platformAdmin]);
        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());
        return $user->fresh();
    }
}
