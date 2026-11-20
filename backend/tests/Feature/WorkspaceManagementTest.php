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
        $workspaceAdmin = Role::where('name', 'admin')->firstOrFail();
        $this->assertDatabaseHas('workspace_user', ['workspace_id' => $workspace->id, 'user_id' => $admin->id, 'role_id' => $workspaceAdmin->id, 'is_active' => true]);
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

    public function test_workspace_member_lookup_suggests_the_existing_workspace_role(): void
    {
        $platformAdmin = $this->makeUser('platform-lookup@example.com', true);
        $sourceWorkspace = Workspace::create(['name' => 'Source', 'slug' => 'source', 'initial_owner_id' => $platformAdmin->id]);
        $targetWorkspace = Workspace::create(['name' => 'Target', 'slug' => 'target']);
        $member = $this->makeUser('multi-workspace@example.com');
        $supervisorRole = Role::where('name', 'supervisor')->firstOrFail();
        WorkspaceUser::create(['workspace_id' => $sourceWorkspace->id, 'user_id' => $member->id, 'role_id' => $supervisorRole->id, 'is_active' => true]);

        $response = $this->actingAs($platformAdmin, 'sanctum')->getJson("/api/v1/workspaces/{$targetWorkspace->id}/members/lookup?email={$member->email}");

        $response->assertOk()
            ->assertJsonPath('data.email', $member->email)
            ->assertJsonPath('data.already_member', false)
            ->assertJsonPath('data.suggested_role.name', 'supervisor')
            ->assertJsonPath('data.suggested_role.display_name', $supervisorRole->display_name);
    }

    public function test_platform_admin_creates_a_user_only_in_the_selected_workspaces(): void
    {
        $platformAdmin = $this->makeUser('platform-create@example.com', true);
        $sourceWorkspace = Workspace::create(['name' => 'Current', 'slug' => 'current', 'initial_owner_id' => $platformAdmin->id]);
        $targetWorkspace = Workspace::create(['name' => 'Selected', 'slug' => 'selected']);
        $secondTargetWorkspace = Workspace::create(['name' => 'Another', 'slug' => 'another']);
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $agentRole = Role::where('name', 'agent')->firstOrFail();
        WorkspaceUser::create(['workspace_id' => $sourceWorkspace->id, 'user_id' => $platformAdmin->id, 'role_id' => $adminRole->id, 'is_active' => true]);
        $platformAdmin->forceFill(['active_workspace_id' => $sourceWorkspace->id])->save();

        $response = $this->actingAs($platformAdmin, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Selected User',
            'email' => 'selected-user@example.com',
            'password' => 'SecurePassword2026!',
            'workspace_assignments' => [
                ['workspace_id' => $targetWorkspace->id, 'role' => 'admin'],
                ['workspace_id' => $secondTargetWorkspace->id, 'role' => 'agent'],
            ],
        ]);

        $response->assertCreated();
        $created = User::where('email', 'selected-user@example.com')->firstOrFail();
        $this->assertDatabaseHas('workspace_user', ['workspace_id' => $targetWorkspace->id, 'user_id' => $created->id, 'role_id' => $adminRole->id, 'is_active' => true]);
        $this->assertDatabaseHas('workspace_user', ['workspace_id' => $secondTargetWorkspace->id, 'user_id' => $created->id, 'role_id' => $agentRole->id, 'is_active' => true]);
        $this->assertDatabaseMissing('workspace_user', ['workspace_id' => $sourceWorkspace->id, 'user_id' => $created->id]);
    }

    public function test_workspace_admin_only_sees_users_in_current_workspace_and_cannot_access_platform_sections(): void
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
        $workspaceAdmin->forceFill(['active_workspace_id' => $workspaceA->id])->save();

        $workspaceMember = User::create([
            'name' => 'Workspace Member',
            'email' => 'workspace-member@example.com',
            'password' => Hash::make('SecurePassword2026!'),
            'is_active' => true,
        ]);
        $agentRole = Role::where('name', 'agent')->firstOrFail();
        WorkspaceUser::create(['workspace_id' => $workspaceA->id, 'user_id' => $workspaceMember->id, 'role_id' => $agentRole->id, 'is_active' => true]);

        $foreignMember = User::create([
            'name' => 'Foreign Member',
            'email' => 'foreign-member@example.com',
            'password' => Hash::make('SecurePassword2026!'),
            'is_active' => true,
        ]);
        WorkspaceUser::create(['workspace_id' => $workspaceB->id, 'user_id' => $foreignMember->id, 'role_id' => $agentRole->id, 'is_active' => true]);

        $this->actingAs($workspaceAdmin, 'sanctum')
            ->postJson("/api/v1/workspaces/{$workspaceA->id}/members", [
                'email' => $workspaceMember->email,
                'role' => 'super_admin',
            ])
            ->assertUnprocessable();

        $usersResponse = $this->actingAs($workspaceAdmin, 'sanctum')->getJson('/api/v1/users');

        $usersResponse->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(['workspace-admin@example.com', 'workspace-member@example.com'], collect($usersResponse->json('data'))->pluck('email')->sort()->values()->all());

        $this->actingAs($workspaceAdmin, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Created In A',
            'email' => 'created-in-a@example.com',
            'password' => 'SecurePassword2026!',
            'roles' => ['super_admin'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['roles.0']);

        $this->actingAs($workspaceAdmin, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Created In A',
            'email' => 'created-in-a@example.com',
            'password' => 'SecurePassword2026!',
            'roles' => ['agent'],
        ])->assertCreated();

        $created = User::where('email', 'created-in-a@example.com')->firstOrFail();
        $this->assertDatabaseHas('workspace_user', ['workspace_id' => $workspaceA->id, 'user_id' => $created->id, 'is_active' => true]);
        $this->assertDatabaseMissing('workspace_user', ['workspace_id' => $workspaceB->id, 'user_id' => $created->id]);

        $this->actingAs($workspaceAdmin, 'sanctum')->getJson('/api/v1/workspaces')->assertForbidden();
        $this->actingAs($workspaceAdmin, 'sanctum')->getJson("/api/v1/workspaces/{$workspaceB->id}/members")->assertForbidden();
        $this->actingAs($workspaceAdmin, 'sanctum')->getJson('/api/v1/audit-logs')->assertForbidden();

        $rolesResponse = $this->actingAs($workspaceAdmin, 'sanctum')->getJson('/api/v1/roles');
        $rolesResponse->assertOk();
        $this->assertNotContains('super_admin', collect($rolesResponse->json('data'))->pluck('name')->all());
        $this->assertContains('admin', collect($rolesResponse->json('data'))->pluck('name')->all());
    }

    public function test_workspace_cannot_remove_owner_or_last_active_admin(): void
    {
        $owner = $this->makeUser('owner-safety@example.com');
        $workspace = Workspace::create(['name' => 'Safety', 'slug' => 'safety', 'initial_owner_id' => $owner->id]);
        $workspaceAdmin = Role::where('name', 'admin')->firstOrFail();
        WorkspaceUser::create(['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'role_id' => $workspaceAdmin->id, 'is_active' => true]);

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
