<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $flowManagerUser;

    protected User $supervisorUser;

    protected User $agentUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => Hash::make('TestSecuredPass@2026'),
            'is_active' => true,
        ]);
        $this->adminUser->roles()->attach(Role::where('name', 'super_admin')->first());

        $this->flowManagerUser = User::create([
            'name' => 'Flow Manager User',
            'email' => 'flowmgr@test.com',
            'password' => Hash::make('TestSecuredPass@2026'),
            'is_active' => true,
        ]);
        $this->flowManagerUser->roles()->attach(Role::where('name', 'flow_manager')->first());

        $this->supervisorUser = User::create([
            'name' => 'Supervisor User',
            'email' => 'supervisor@test.com',
            'password' => Hash::make('TestSecuredPass@2026'),
            'is_active' => true,
        ]);
        $this->supervisorUser->roles()->attach(Role::where('name', 'supervisor')->first());

        $this->agentUser = User::create([
            'name' => 'Agent User',
            'email' => 'agent@test.com',
            'password' => Hash::make('TestSecuredPass@2026'),
            'is_active' => true,
        ]);
        $this->agentUser->roles()->attach(Role::where('name', 'agent')->first());
    }

    public function test_admin_can_list_and_create_users(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/users');

        $response->assertStatus(200);

        $createResponse = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'New User',
                'email' => 'newuser@test.com',
                'password' => 'SecurePass1234!',
                'roles' => ['agent'],
            ]);

        $createResponse->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'newuser@test.com']);
    }

    public function test_agent_cannot_access_user_management(): void
    {
        $response = $this->actingAs($this->agentUser, 'sanctum')
            ->getJson('/api/v1/users');

        $response->assertStatus(403);

        $createResponse = $this->actingAs($this->agentUser, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'Should Fail',
                'email' => 'fail@test.com',
                'password' => 'SecurePass1234!',
            ]);

        $createResponse->assertStatus(403);
    }

    public function test_agent_cannot_create_or_view_flows(): void
    {
        $this->actingAs($this->agentUser, 'sanctum')
            ->getJson('/api/v1/flows')
            ->assertStatus(403);

        $this->actingAs($this->agentUser, 'sanctum')
            ->postJson('/api/v1/flows', [
                'name' => 'Agent Flow',
            ])
            ->assertStatus(403);
    }

    public function test_flow_manager_can_create_flows_but_not_manage_users(): void
    {
        // Flow Manager can create flow
        $response = $this->actingAs($this->flowManagerUser, 'sanctum')
            ->postJson('/api/v1/flows', [
                'name' => 'Customer Welcome Flow',
                'description' => 'Handles greeting and routing',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('flows', ['name' => 'Customer Welcome Flow']);

        // Flow Manager CANNOT manage users (403)
        $this->actingAs($this->flowManagerUser, 'sanctum')
            ->getJson('/api/v1/users')
            ->assertStatus(403);
    }

    public function test_supervisor_can_view_conversations(): void
    {
        $response = $this->actingAs($this->supervisorUser, 'sanctum')
            ->getJson('/api/v1/conversations');

        $response->assertStatus(200);
    }

    public function test_supervisor_can_view_assigned_channel_summary_but_cannot_manage_channels(): void
    {
        $channel = \App\Models\Channel::create([
            'name' => 'Canal visible de supervisor',
            'type' => \App\Enums\ChannelType::WEBCHAT,
            'is_active' => true,
        ]);
        $this->grantChannelAccess($channel, $this->supervisorUser);

        $this->actingAs($this->supervisorUser, 'sanctum')
            ->getJson('/api/v1/channels')
            ->assertOk()
            ->assertJsonPath('data.0.id', $channel->id)
            ->assertJsonMissingPath('data.0.configuration');

        $this->actingAs($this->supervisorUser, 'sanctum')
            ->postJson('/api/v1/channels', [
                'name' => 'Webchat Test',
                'type' => 'webchat',
            ])
            ->assertStatus(403);
    }

    public function test_user_without_permission_receives_403(): void
    {
        $emptyUser = User::create([
            'name' => 'No Roles User',
            'email' => 'noroles@test.com',
            'password' => Hash::make('TestSecuredPass@2026'),
            'is_active' => true,
        ]);

        $this->actingAs($emptyUser, 'sanctum')
            ->getJson('/api/v1/users')
            ->assertStatus(403);

        $this->actingAs($emptyUser, 'sanctum')
            ->getJson('/api/v1/channels')
            ->assertStatus(403);

        $this->actingAs($emptyUser, 'sanctum')
            ->getJson('/api/v1/flows')
            ->assertStatus(403);
    }

    public function test_admin_can_update_role_max_concurrent_conversations(): void
    {
        $agentRole = Role::where('name', 'agent')->firstOrFail();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/roles/{$agentRole->id}", [
                'max_concurrent_conversations' => 8,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.max_concurrent_conversations', 8);

        $this->assertDatabaseHas('roles', [
            'id' => $agentRole->id,
            'max_concurrent_conversations' => 8,
        ]);
    }

    public function test_role_max_concurrent_conversations_validates_range(): void
    {
        $agentRole = Role::where('name', 'agent')->firstOrFail();

        // Min is 1
        $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/roles/{$agentRole->id}", [
                'max_concurrent_conversations' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['max_concurrent_conversations']);

        // Max is 50
        $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/roles/{$agentRole->id}", [
                'max_concurrent_conversations' => 51,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['max_concurrent_conversations']);
    }

    public function test_super_admin_and_flow_manager_can_be_set_to_zero_capacity(): void
    {
        $superAdmin = Role::where('name', 'super_admin')->firstOrFail();
        $flowManager = Role::where('name', 'flow_manager')->firstOrFail();

        $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/roles/{$superAdmin->id}", [
                'max_concurrent_conversations' => 0,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.max_concurrent_conversations', 0);

        $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/roles/{$flowManager->id}", [
                'max_concurrent_conversations' => 0,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.max_concurrent_conversations', 0);

        $this->assertDatabaseHas('roles', ['id' => $superAdmin->id, 'max_concurrent_conversations' => 0]);
        $this->assertDatabaseHas('roles', ['id' => $flowManager->id, 'max_concurrent_conversations' => 0]);
    }

    public function test_agent_and_supervisor_cannot_be_set_to_zero_capacity(): void
    {
        $agentRole = Role::where('name', 'agent')->firstOrFail();
        $supervisorRole = Role::where('name', 'supervisor')->firstOrFail();

        $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/roles/{$agentRole->id}", [
                'max_concurrent_conversations' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['max_concurrent_conversations']);

        $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/roles/{$supervisorRole->id}", [
                'max_concurrent_conversations' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['max_concurrent_conversations']);
    }

    public function test_agent_cannot_update_role(): void
    {
        $agentRole = Role::where('name', 'agent')->firstOrFail();

        $this->actingAs($this->agentUser, 'sanctum')
            ->patchJson("/api/v1/roles/{$agentRole->id}", [
                'max_concurrent_conversations' => 10,
            ])
            ->assertStatus(403);
    }
}
