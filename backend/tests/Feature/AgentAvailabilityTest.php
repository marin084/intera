<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Presence\AgentAvailabilityService;
use App\Services\Runtime\ExecutionContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AgentAvailabilityService::resetFake();
        $this->seed(RolePermissionSeeder::class);
        User::query()->update(['last_seen_at' => null]);
    }

    protected function tearDown(): void
    {
        AgentAvailabilityService::resetFake();
        parent::tearDown();
    }

    public function test_detects_online_agents_count_and_has_online_agents(): void
    {
        $this->assertEquals(0, AgentAvailabilityService::getOnlineAgentsCount());
        $this->assertFalse(AgentAvailabilityService::hasOnlineAgents());

        $agentRole = Role::where('name', 'agent')->first();
        $adminRole = Role::where('name', 'super_admin')->first();

        // 1. Create an online agent (seen 1 minute ago)
        $agent = User::create([
            'name' => 'Agent One',
            'email' => 'agent1@example.com',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
            'last_seen_at' => now()->subMinute(),
        ]);
        $agent->roles()->attach($agentRole);

        $this->assertEquals(1, AgentAvailabilityService::getOnlineAgentsCount());
        $this->assertTrue(AgentAvailabilityService::hasOnlineAgents());

        // 2. Create another online super_admin (seen just now)
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $admin->roles()->attach($adminRole);

        $this->assertEquals(2, AgentAvailabilityService::getOnlineAgentsCount());
        $this->assertTrue(AgentAvailabilityService::hasOnlineAgents());

        // 3. User with activity older than 5 minutes (inactive/offline)
        $offlineAgent = User::create([
            'name' => 'Offline Agent',
            'email' => 'offline@example.com',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
            'last_seen_at' => now()->subMinutes(10),
        ]);
        $offlineAgent->roles()->attach($agentRole);

        // Count should still be 2
        $this->assertEquals(2, AgentAvailabilityService::getOnlineAgentsCount());

        // 4. Inactive user (is_active = false) should NOT count
        $inactiveAgent = User::create([
            'name' => 'Inactive Agent',
            'email' => 'inactive@example.com',
            'password' => Hash::make('Secret123!'),
            'is_active' => false,
            'last_seen_at' => now(),
        ]);
        $inactiveAgent->roles()->attach($agentRole);

        $this->assertEquals(2, AgentAvailabilityService::getOnlineAgentsCount());
    }

    public function test_presence_heartbeat_endpoint_updates_last_seen(): void
    {
        $agentRole = Role::where('name', 'agent')->first();
        $user = User::create([
            'name' => 'Heartbeat Agent',
            'email' => 'heartbeat@example.com',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
            'last_seen_at' => null,
        ]);
        $user->roles()->attach($agentRole);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/presence/heartbeat');
        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'has_agents' => true,
            ]);

        $this->assertEquals(1, $response->json('online_agents'));
        $this->assertNotNull($user->fresh()->last_seen_at);
    }

    public function test_execution_context_injects_system_agents_online_and_count(): void
    {
        // When 0 agents online
        $context = ExecutionContext::fromArray(['test_var' => '123']);
        $this->assertEquals('FALSE', $context->get('system.agents_online'));
        $this->assertEquals(0, $context->get('system.agents_count'));

        // When agents are online (using fake or active user)
        AgentAvailabilityService::fake(3);

        $contextOnline = ExecutionContext::fromArray([]);
        $this->assertEquals('TRUE', $contextOnline->get('system.agents_online'));
        $this->assertEquals(3, $contextOnline->get('system.agents_count'));
        $this->assertTrue($contextOnline->has('system.agents_online'));
        $this->assertTrue($contextOnline->has('system.agents_count'));
    }
}
