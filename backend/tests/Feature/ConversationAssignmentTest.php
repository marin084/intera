<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Events\ConversationAssigned;
use App\Events\ConversationUpdated;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Role;
use App\Models\User;
use App\Services\Presence\AgentAvailabilityService;
use App\Services\Routing\ConversationAssignmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConversationAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $agentA;

    protected User $agentB;

    protected Channel $channel;

    protected Contact $contact;

    protected ConversationAssignmentService $assignmentService;

    protected function setUp(): void
    {
        parent::setUp();
        AgentAvailabilityService::resetFake();
        $this->seed(RolePermissionSeeder::class);

        Conversation::query()->delete();
        Contact::query()->delete();
        Channel::query()->delete();

        $superAdminRole = Role::where('name', 'super_admin')->first();
        $agentRole = Role::where('name', 'agent')->first();

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $this->admin->roles()->attach($superAdminRole);

        $this->agentA = User::create([
            'name' => 'Agent Alpha',
            'email' => 'agent.a@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $this->agentA->roles()->attach($agentRole);

        $this->agentB = User::create([
            'name' => 'Agent Beta',
            'email' => 'agent.b@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $this->agentB->roles()->attach($agentRole);

        $this->channel = Channel::create([
            'name' => 'WhatsApp Test',
            'type' => ChannelType::WHATSAPP,
            'status' => 'active',
            'configuration' => [
                'phone_number_id' => '123456789',
                'access_token' => 'token-abc',
            ],
        ]);

        $this->contact = Contact::create([
            'display_name' => 'Test Contact',
            'external_identifier' => 'test-user-1',
            'phone' => '+1234567890',
        ]);

        $this->assignmentService = app(ConversationAssignmentService::class);
    }

    protected function tearDown(): void
    {
        AgentAvailabilityService::resetFake();
        parent::tearDown();
    }

    /**
     * 1. Least-Busy Load Balancer: agent with 1 conversation receives new chat before agent with 3.
     */
    public function test_least_busy_agent_receives_chat_before_busier_agent(): void
    {
        // Agent A has 1 active conversation
        Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agentA->id,
            'is_simulation' => false,
        ]);

        // Agent B has 3 active conversations
        for ($i = 0; $i < 3; $i++) {
            Conversation::create([
                'channel_id' => $this->channel->id,
                'contact_id' => $this->contact->id,
                'status' => ConversationStatus::ASSIGNED,
                'assigned_user_id' => $this->agentB->id,
                'is_simulation' => false,
            ]);
        }

        // New conversation needing assignment
        $newConversation = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'is_simulation' => false,
        ]);

        Event::fake([ConversationUpdated::class, ConversationAssigned::class]);

        $assignedUser = $this->assignmentService->autoAssign($newConversation, 'least_busy');

        $this->assertNotNull($assignedUser);
        $this->assertEquals($this->agentA->id, $assignedUser->id);
        $this->assertEquals($this->agentA->id, $newConversation->fresh()->assigned_user_id);
        $this->assertEquals(ConversationStatus::ASSIGNED, $newConversation->fresh()->status);

        Event::assertDispatched(ConversationUpdated::class);
        Event::assertDispatched(ConversationAssigned::class);
    }

    /**
     * 2. Capacity filter: an agent who has reached 5 conversations receives no more auto-assignments.
     */
    public function test_agent_reaching_5_conversations_does_not_receive_auto_assignments(): void
    {
        // Put Agent A and Agent B at 5 active conversations
        for ($i = 0; $i < 5; $i++) {
            Conversation::create([
                'channel_id' => $this->channel->id,
                'contact_id' => $this->contact->id,
                'status' => ConversationStatus::ASSIGNED,
                'assigned_user_id' => $this->agentA->id,
                'is_simulation' => false,
            ]);
            Conversation::create([
                'channel_id' => $this->channel->id,
                'contact_id' => $this->contact->id,
                'status' => ConversationStatus::ASSIGNED,
                'assigned_user_id' => $this->agentB->id,
                'is_simulation' => false,
            ]);
        }

        // Mark admin offline so no other agent is eligible
        $this->admin->update(['last_seen_at' => now()->subMinutes(10)]);

        $newConversation = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'is_simulation' => false,
        ]);

        $result = $this->assignmentService->autoAssign($newConversation);

        $this->assertNull($result);
        $this->assertNull($newConversation->fresh()->assigned_user_id);
        $this->assertEquals(ConversationStatus::WAITING_AGENT, $newConversation->fresh()->status);
    }

    /**
     * 3. Admin override: administrator can manually assign a 6th conversation to an agent with 5.
     */
    public function test_admin_can_manually_assign_6th_conversation_override(): void
    {
        // Agent A has 5 conversations
        for ($i = 0; $i < 5; $i++) {
            Conversation::create([
                'channel_id' => $this->channel->id,
                'contact_id' => $this->contact->id,
                'status' => ConversationStatus::ASSIGNED,
                'assigned_user_id' => $this->agentA->id,
                'is_simulation' => false,
            ]);
        }

        $conv6 = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'is_simulation' => false,
        ]);

        Sanctum::actingAs($this->admin);

        // Via PATCH /api/v1/conversations/{id}
        $response = $this->patchJson("/api/v1/conversations/{$conv6->id}", [
            'assigned_user_id' => $this->agentA->id,
            'reason' => 'Admin manual override',
        ]);

        $response->assertStatus(200);
        $this->assertEquals($this->agentA->id, $conv6->fresh()->assigned_user_id);
        $this->assertEquals(ConversationStatus::ASSIGNED, $conv6->fresh()->status);

        // Agent A now has 6 active conversations
        $activeCount = Conversation::where('assigned_user_id', $this->agentA->id)
            ->where('status', ConversationStatus::ASSIGNED)
            ->count();
        $this->assertEquals(6, $activeCount);

        // Also via POST /api/v1/conversations/{id}/assign for 7th conversation
        $conv7 = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'is_simulation' => false,
        ]);

        $responsePost = $this->postJson("/api/v1/conversations/{$conv7->id}/assign", [
            'assigned_user_id' => $this->agentA->id,
            'reason' => 'Admin second manual override',
        ]);

        $responsePost->assertStatus(200);
        $this->assertEquals($this->agentA->id, $conv7->fresh()->assigned_user_id);
    }

    /**
     * 4. Visibility restriction: role 'agent' can ONLY access their own assigned conversations.
     */
    public function test_agent_can_only_access_own_assigned_conversations(): void
    {
        $convAgentA = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agentA->id,
            'is_simulation' => false,
        ]);

        $convAgentB = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agentB->id,
            'is_simulation' => false,
        ]);

        $convWaiting = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'is_simulation' => false,
        ]);

        $convBot = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::BOT,
            'assigned_user_id' => null,
            'is_simulation' => false,
        ]);

        Sanctum::actingAs($this->agentA);

        // 1. Can view own conversation
        $this->getJson("/api/v1/conversations/{$convAgentA->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $convAgentA->id);

        $this->getJson("/api/v1/conversations/{$convAgentA->id}/messages")
            ->assertStatus(200);

        // 2. Forbidden (403) accessing other agent's conversation
        $this->getJson("/api/v1/conversations/{$convAgentB->id}")
            ->assertStatus(403);

        $this->getJson("/api/v1/conversations/{$convAgentB->id}/messages")
            ->assertStatus(403);

        // 3. Forbidden (403) accessing unassigned waiting_agent conversation
        $this->getJson("/api/v1/conversations/{$convWaiting->id}")
            ->assertStatus(403);

        // 4. Forbidden (403) accessing bot conversation
        $this->getJson("/api/v1/conversations/{$convBot->id}")
            ->assertStatus(403);

        // 5. Index list for Agent A returns ONLY conversations assigned to Agent A
        $indexResponse = $this->getJson('/api/v1/conversations');
        $indexResponse->assertStatus(200);
        $items = $indexResponse->json('data');
        $this->assertCount(1, $items);
        $this->assertEquals($convAgentA->id, $items[0]['id']);

        // Tab counts for agent reflect only their conversations
        $counts = $indexResponse->json('counts');
        $this->assertEquals(0, $counts['bot']);
        $this->assertEquals(0, $counts['waiting_agent']);
        $this->assertEquals(1, $counts['assigned']);
        $this->assertEquals(1, $counts['all']);

        // Admin can see everything
        Sanctum::actingAs($this->admin);
        $adminIndex = $this->getJson('/api/v1/conversations');
        $adminIndex->assertStatus(200);
        $adminItems = $adminIndex->json('data');
        $this->assertCount(4, $adminItems);
        $adminCounts = $adminIndex->json('counts');
        $this->assertEquals(1, $adminCounts['bot']);
        $this->assertEquals(1, $adminCounts['waiting_agent']);
        $this->assertEquals(2, $adminCounts['assigned']);
        $this->assertEquals(4, $adminCounts['all']);
    }

    /**
     * 5. Trigger on quota freed: closing a conversation auto-assigns the oldest waiting conversation.
     */
    public function test_closing_conversation_auto_assigns_oldest_waiting_conversation(): void
    {
        // Agent A has an assigned conversation
        $activeConv = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agentA->id,
            'is_simulation' => false,
        ]);

        // Two waiting conversations (one older, one newer)
        $oldWaiting = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'is_simulation' => false,
            'created_at' => now()->subMinutes(15),
        ]);

        $newWaiting = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'is_simulation' => false,
            'created_at' => now()->subMinutes(5),
        ]);

        Sanctum::actingAs($this->agentA);

        // Close the active conversation
        $closeResponse = $this->postJson("/api/v1/conversations/{$activeConv->id}/close");
        $closeResponse->assertStatus(200);

        // The oldest waiting conversation should now be assigned to Agent A!
        $this->assertEquals($this->agentA->id, $oldWaiting->fresh()->assigned_user_id);
        $this->assertEquals(ConversationStatus::ASSIGNED, $oldWaiting->fresh()->status);

        // The newer waiting conversation remains waiting
        $this->assertNull($newWaiting->fresh()->assigned_user_id);
        $this->assertEquals(ConversationStatus::WAITING_AGENT, $newWaiting->fresh()->status);
    }

    /**
     * 6. Dynamic role capacity: changing max_concurrent_conversations restricts and expands auto-assignment limit.
     */
    public function test_dynamic_role_capacity_restricts_and_expands_assignment_limit(): void
    {
        $agentRole = Role::where('name', 'agent')->first();
        $agentRole->update(['max_concurrent_conversations' => 3]);

        // Put Agent A at 3 active conversations (at capacity)
        for ($i = 0; $i < 3; $i++) {
            Conversation::create([
                'channel_id' => $this->channel->id,
                'contact_id' => $this->contact->id,
                'status' => ConversationStatus::ASSIGNED,
                'assigned_user_id' => $this->agentA->id,
                'is_simulation' => false,
            ]);
        }

        // Put Agent B offline and admin offline
        $this->agentB->update(['last_seen_at' => now()->subMinutes(10)]);
        $this->admin->update(['last_seen_at' => now()->subMinutes(10)]);

        $conv4 = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'is_simulation' => false,
        ]);

        // Agent A has 3/3 => cannot receive 4th auto-assignment
        $assignedUser = $this->assignmentService->autoAssign($conv4);
        $this->assertNull($assignedUser);
        $this->assertNull($conv4->fresh()->assigned_user_id);
        $this->assertEquals(ConversationStatus::WAITING_AGENT, $conv4->fresh()->status);

        // Expand capacity to 7
        $agentRole->update(['max_concurrent_conversations' => 7]);

        // Now Agent A has capacity (3/7) and receives the assignment
        $assignedUser2 = $this->assignmentService->autoAssign($conv4);
        $this->assertNotNull($assignedUser2);
        $this->assertEquals($this->agentA->id, $assignedUser2->id);
        $this->assertEquals($this->agentA->id, $conv4->fresh()->assigned_user_id);
        $this->assertEquals(ConversationStatus::ASSIGNED, $conv4->fresh()->status);
    }

    /**
     * 7. Zero capacity role: users whose effective role capacity is 0 never receive auto-assignments.
     */
    public function test_user_with_zero_capacity_role_never_receives_auto_assignment(): void
    {
        // Admin has role super_admin with max_concurrent_conversations = 0
        $superAdminRole = Role::where('name', 'super_admin')->first();
        $this->assertEquals(0, $superAdminRole->max_concurrent_conversations);

        // Put Agent A and Agent B offline
        $this->agentA->update(['last_seen_at' => now()->subMinutes(10)]);
        $this->agentB->update(['last_seen_at' => now()->subMinutes(10)]);

        // Admin is online
        $this->admin->update(['last_seen_at' => now()]);

        $conversation = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'is_simulation' => false,
        ]);

        $assignedUser = $this->assignmentService->autoAssign($conversation);

        // Admin is excluded because capacity <= 0
        $this->assertNull($assignedUser);
        $this->assertNull($conversation->fresh()->assigned_user_id);
        $this->assertEquals(ConversationStatus::WAITING_AGENT, $conversation->fresh()->status);
    }

    /**
     * 8. Uniform candidate pool across all eligible agents with available capacity.
     * Confirms that agents with different load (Agent A has 1, Agent B has 3) but available capacity
     * are both included in the candidate pool for random selection without least-busy bias.
     */
    public function test_default_strategy_draws_randomly_across_all_eligible_agents_with_capacity_without_least_busy_bias(): void
    {
        // Admin offline, Agent A and Agent B online
        $this->admin->update(['last_seen_at' => now()->subMinutes(10)]);
        $this->agentA->update(['last_seen_at' => now()]);
        $this->agentB->update(['last_seen_at' => now()]);

        // Agent A has 1 active conversation
        Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agentA->id,
            'is_simulation' => false,
        ]);

        // Agent B has 3 active conversations (both have capacity < 5, but Agent B has higher load)
        for ($i = 0; $i < 3; $i++) {
            Conversation::create([
                'channel_id' => $this->channel->id,
                'contact_id' => $this->contact->id,
                'status' => ConversationStatus::ASSIGNED,
                'assigned_user_id' => $this->agentB->id,
                'is_simulation' => false,
            ]);
        }

        $testConv = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'is_simulation' => false,
        ]);

        // Deterministic check: agents with different load (Agent A has 1, Agent B has 3) but available capacity (max 5)
        // are both included in the candidate pool without least-busy filtering
        $candidates = $this->assignmentService->getEligibleCandidates($testConv);
        $candidateIds = $candidates->pluck('id')->all();
        $this->assertCount(2, $candidates);
        $this->assertContains($this->agentA->id, $candidateIds, 'Agent A (load 1) must be in the eligible candidate pool');
        $this->assertContains($this->agentB->id, $candidateIds, 'Agent B (load 3) must be in the eligible candidate pool');

        $assigned = $this->assignmentService->autoAssign($testConv);
        $this->assertNotNull($assigned);
        $this->assertContains($assigned->id, [$this->agentA->id, $this->agentB->id]);
        $this->assertEquals($assigned->id, $testConv->fresh()->assigned_user_id);
        $this->assertEquals(ConversationStatus::ASSIGNED, $testConv->fresh()->status);
    }

    /**
     * 9. Saturated agents and offline agents are strictly excluded from assignment.
     */
    public function test_auto_assign_excludes_offline_and_full_capacity_agents(): void
    {
        // Admin offline, Agent A and Agent B online
        $this->admin->update(['last_seen_at' => now()->subMinutes(10)]);
        $this->agentA->update(['last_seen_at' => now()]);
        $this->agentB->update(['last_seen_at' => now()]);

        // Agent A has 5 active conversations (100% full capacity)
        for ($i = 0; $i < 5; $i++) {
            Conversation::create([
                'channel_id' => $this->channel->id,
                'contact_id' => $this->contact->id,
                'status' => ConversationStatus::ASSIGNED,
                'assigned_user_id' => $this->agentA->id,
                'is_simulation' => false,
            ]);
        }

        // Agent B has 2 active conversations (available capacity 2/5)
        for ($i = 0; $i < 2; $i++) {
            Conversation::create([
                'channel_id' => $this->channel->id,
                'contact_id' => $this->contact->id,
                'status' => ConversationStatus::ASSIGNED,
                'assigned_user_id' => $this->agentB->id,
                'is_simulation' => false,
            ]);
        }

        // Agent C is offline
        $agentRole = Role::where('name', 'agent')->first();
        $agentC = User::create([
            'name' => 'Agent Offline',
            'email' => 'agent.c@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
            'last_seen_at' => now()->subHours(2),
        ]);
        $agentC->roles()->attach($agentRole);

        $conv = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'is_simulation' => false,
        ]);

        // Deterministically verify that only Agent B is in the eligible pool
        $candidates = $this->assignmentService->getEligibleCandidates($conv);
        $this->assertCount(1, $candidates);
        $this->assertEquals($this->agentB->id, $candidates->first()->id);

        $assigned = $this->assignmentService->autoAssign($conv);
        $this->assertNotNull($assigned);
        $this->assertEquals($this->agentB->id, $assigned->id);

        // Now saturate Agent B as well (add 3 more active conversations so Agent B has 5/5)
        for ($i = 0; $i < 3; $i++) {
            Conversation::create([
                'channel_id' => $this->channel->id,
                'contact_id' => $this->contact->id,
                'status' => ConversationStatus::ASSIGNED,
                'assigned_user_id' => $this->agentB->id,
                'is_simulation' => false,
            ]);
        }

        $conv2 = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'is_simulation' => false,
        ]);

        $candidates2 = $this->assignmentService->getEligibleCandidates($conv2);
        $this->assertTrue($candidates2->isEmpty());

        $assigned2 = $this->assignmentService->autoAssign($conv2);
        $this->assertNull($assigned2);
        $this->assertNull($conv2->fresh()->assigned_user_id);
        $this->assertEquals(ConversationStatus::WAITING_AGENT, $conv2->fresh()->status);
    }
}
