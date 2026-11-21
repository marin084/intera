<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\FlowExecutionStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Events\ConversationAssigned;
use App\Events\ConversationClosed;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\Flow;
use App\Models\FlowExecution;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InboxOperabilityTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $supervisor;

    protected User $agentA;

    protected User $agentB;

    protected User $flowManager;

    protected Channel $channel;

    protected Contact $contact;

    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => Hash::make('AdminPass12345!'),
            'is_active' => true,
        ]);
        $this->admin->roles()->attach(Role::where('name', 'super_admin')->first());

        $this->supervisor = User::create([
            'name' => 'Supervisor User',
            'email' => 'supervisor@test.com',
            'password' => Hash::make('SuperPass12345!'),
            'is_active' => true,
        ]);
        $this->supervisor->roles()->attach(Role::where('name', 'supervisor')->first());

        $this->agentA = User::create([
            'name' => 'Agent Alice',
            'email' => 'alice@test.com',
            'password' => Hash::make('AlicePass12345!'),
            'is_active' => true,
        ]);
        $this->agentA->roles()->attach(Role::where('name', 'agent')->first());

        $this->agentB = User::create([
            'name' => 'Agent Bob',
            'email' => 'bob@test.com',
            'password' => Hash::make('BobPass12345!'),
            'is_active' => true,
        ]);
        $this->agentB->roles()->attach(Role::where('name', 'agent')->first());

        $this->flowManager = User::create([
            'name' => 'Flow Manager',
            'email' => 'flow@test.com',
            'password' => Hash::make('FlowPass12345!'),
            'is_active' => true,
        ]);
        $this->flowManager->roles()->attach(Role::where('name', 'flow_manager')->first());

        $this->channel = Channel::create([
            'name' => 'Webchat Main',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
        ]);
        $this->grantChannelAccess($this->channel, $this->supervisor, $this->agentA, $this->agentB, $this->flowManager);
        $this->team = $this->addToDefaultTeam($this->supervisor, $this->agentA, $this->agentB);

        $this->contact = Contact::create([
            'display_name' => 'Cliente Prueba',
            'email' => 'cliente@test.com',
        ]);
    }

    public function test_agent_can_view_waiting_agent_queue_and_their_own_assigned_conversations(): void
    {
        // 1. Unassigned waiting_agent
        $queueConv = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'started_at' => now(),
        ]);

        // 2. Assigned to Agent A
        $aliceConv = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agentA->id,
            'started_at' => now(),
        ]);

        // 3. Assigned to Agent B
        $bobConv = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agentB->id,
            'started_at' => now(),
        ]);

        // 4. Bot conversation
        $botConv = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::BOT,
            'started_at' => now(),
        ]);

        // Agent A indexes all accessible conversations (only assigned to Agent A)
        $res = $this->actingAs($this->agentA, 'sanctum')->getJson('/api/v1/conversations');
        $res->assertStatus(200);

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertNotContains($queueConv->id, $ids);
        $this->assertContains($aliceConv->id, $ids);
        $this->assertNotContains($bobConv->id, $ids);
        $this->assertNotContains($botConv->id, $ids);

        // Agent A with scope=mine
        $mineRes = $this->actingAs($this->agentA, 'sanctum')->getJson('/api/v1/conversations?scope=mine');
        $mineRes->assertStatus(200);
        $mineIds = collect($mineRes->json('data'))->pluck('id')->all();
        $this->assertContains($aliceConv->id, $mineIds);
        $this->assertNotContains($queueConv->id, $mineIds);

        // Supervisor can see all
        $supRes = $this->actingAs($this->supervisor, 'sanctum')->getJson('/api/v1/conversations');
        $supRes->assertStatus(200);
        $supIds = collect($supRes->json('data'))->pluck('id')->all();
        $this->assertContains($queueConv->id, $supIds);
        $this->assertContains($aliceConv->id, $supIds);
        $this->assertContains($bobConv->id, $supIds);
        $this->assertContains($botConv->id, $supIds);

        // Supervisor with scope=queue
        $supQueueRes = $this->actingAs($this->supervisor, 'sanctum')->getJson('/api/v1/conversations?scope=queue');
        $supQueueRes->assertStatus(200);
        $supQueueIds = collect($supQueueRes->json('data'))->pluck('id')->all();
        $this->assertContains($queueConv->id, $supQueueIds);
        $this->assertNotContains($aliceConv->id, $supQueueIds);
        $this->assertContains($botConv->id, $supIds);

        // Flow manager has no access to conversations
        $fmRes = $this->actingAs($this->flowManager, 'sanctum')->getJson('/api/v1/conversations');
        $fmRes->assertStatus(403);
    }

    public function test_agent_can_claim_waiting_agent_conversation_atomically(): void
    {
        Event::fake([ConversationAssigned::class]);

        $conv = Conversation::create([
            'channel_id' => $this->channel->id,
            'team_id' => $this->team->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'started_at' => now(),
        ]);

        $response = $this->actingAs($this->agentA, 'sanctum')
            ->postJson("/api/v1/conversations/{$conv->id}/claim");

        $response->assertStatus(200)
            ->assertJsonPath('data.assigned_user_id', $this->agentA->id)
            ->assertJsonPath('data.status', 'assigned');

        $this->assertDatabaseHas('conversations', [
            'id' => $conv->id,
            'status' => 'assigned',
            'assigned_user_id' => $this->agentA->id,
        ]);

        $this->assertDatabaseHas('conversation_assignments', [
            'conversation_id' => $conv->id,
            'assigned_user_id' => $this->agentA->id,
            'assigned_by_user_id' => $this->agentA->id,
            'reason' => 'Claimed by agent',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'conversation.claim',
            'entity_id' => $conv->id,
        ]);

        Event::assertDispatched(ConversationAssigned::class, function ($event) use ($conv) {
            return $event->conversation->id === $conv->id && $event->assignedUser->id === $this->agentA->id;
        });
    }

    public function test_zero_auto_assignment_capacity_does_not_block_manual_claim(): void
    {
        // Administrators have no automatic-routing capacity by design, but can
        // still take a conversation explicitly when they choose to handle it.
        $this->addToDefaultTeam($this->admin);

        $conv = Conversation::create([
            'channel_id' => $this->channel->id,
            'team_id' => $this->team->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'started_at' => now(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/conversations/{$conv->id}/claim");

        $response->assertStatus(200)
            ->assertJsonPath('data.assigned_user_id', $this->admin->id)
            ->assertJsonPath('data.status', 'assigned');
    }

    public function test_claim_conflict_prevents_double_assignment_and_returns_409(): void
    {
        Event::fake([ConversationAssigned::class]);

        $conv = Conversation::create([
            'channel_id' => $this->channel->id,
            'team_id' => $this->team->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'started_at' => now(),
        ]);

        // Agent A claims successfully
        $resA = $this->actingAs($this->agentA, 'sanctum')->postJson("/api/v1/conversations/{$conv->id}/claim");
        $resA->assertStatus(200);

        // Agent B attempts to claim the same conversation -> receives 409
        $resB = $this->actingAs($this->agentB, 'sanctum')->postJson("/api/v1/conversations/{$conv->id}/claim");
        $resB->assertStatus(409)
            ->assertJsonPath('code', 'conversation_already_claimed');

        // Confirm exactly 1 assignment exists
        $this->assertEquals(1, ConversationAssignment::where('conversation_id', $conv->id)->count());
        $this->assertEquals($this->agentA->id, $conv->fresh()->assigned_user_id);
        Event::assertDispatchedTimes(ConversationAssigned::class, 1);
    }

    public function test_supervisor_can_assign_and_reassign_conversation(): void
    {
        Event::fake([ConversationAssigned::class]);

        $conv = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'started_at' => now(),
        ]);

        // Supervisor assigns to Agent A
        $assignRes = $this->actingAs($this->supervisor, 'sanctum')
            ->postJson("/api/v1/conversations/{$conv->id}/assign", [
                'assigned_user_id' => $this->agentA->id,
                'reason' => 'Asignado a Alice',
            ]);
        $assignRes->assertStatus(200);

        $this->assertDatabaseHas('conversation_assignments', [
            'conversation_id' => $conv->id,
            'assigned_user_id' => $this->agentA->id,
            'unassigned_at' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'conversation.assign',
            'entity_id' => $conv->id,
        ]);

        // Supervisor reassigns to Agent B
        $reassignRes = $this->actingAs($this->supervisor, 'sanctum')
            ->postJson("/api/v1/conversations/{$conv->id}/assign", [
                'assigned_user_id' => $this->agentB->id,
                'reason' => 'Transferido a Bob por saturación',
            ]);
        $reassignRes->assertStatus(200);

        // Previous assignment closed
        $this->assertDatabaseHas('conversation_assignments', [
            'conversation_id' => $conv->id,
            'assigned_user_id' => $this->agentA->id,
            'unassigned_at' => now(),
        ]);

        // New assignment active
        $this->assertDatabaseHas('conversation_assignments', [
            'conversation_id' => $conv->id,
            'assigned_user_id' => $this->agentB->id,
            'unassigned_at' => null,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'conversation.reassign',
            'entity_id' => $conv->id,
        ]);
    }

    public function test_conversation_closure_authorization_and_lifecycle(): void
    {
        Event::fake([ConversationClosed::class]);

        $convAlice = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agentA->id,
            'started_at' => now(),
        ]);

        $convWaiting = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'started_at' => now(),
        ]);

        // 1. Agent B cannot close Agent A's conversation -> 403
        $failB = $this->actingAs($this->agentB, 'sanctum')
            ->postJson("/api/v1/conversations/{$convAlice->id}/close");
        $failB->assertStatus(403);

        // 2. Agent A cannot close an unassigned waiting_agent conversation -> 403
        $failWaiting = $this->actingAs($this->agentA, 'sanctum')
            ->postJson("/api/v1/conversations/{$convWaiting->id}/close");
        $failWaiting->assertStatus(403);

        // 3. Supervisor CAN close a waiting_agent conversation
        $supCloseWaiting = $this->actingAs($this->supervisor, 'sanctum')
            ->postJson("/api/v1/conversations/{$convWaiting->id}/close");
        $supCloseWaiting->assertStatus(200);
        $this->assertEquals(ConversationStatus::CLOSED, $convWaiting->fresh()->status);
        $this->assertNotNull($convWaiting->fresh()->closed_at);

        // 4. Agent A CAN close their own conversation
        $aliceClose = $this->actingAs($this->agentA, 'sanctum')
            ->postJson("/api/v1/conversations/{$convAlice->id}/close");
        $aliceClose->assertStatus(200);
        $this->assertEquals(ConversationStatus::CLOSED, $convAlice->fresh()->status);
        $this->assertNotNull($convAlice->fresh()->closed_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'conversation.closed',
            'entity_id' => $convAlice->id,
        ]);

        Event::assertDispatched(ConversationClosed::class, 2);

        // 5. Idempotent closing: attempting to close already closed conversation returns 200 without duplicate events
        $idempotentClose = $this->actingAs($this->supervisor, 'sanctum')
            ->postJson("/api/v1/conversations/{$convAlice->id}/close");
        $idempotentClose->assertStatus(200);
        Event::assertDispatchedTimes(ConversationClosed::class, 2);
    }

    public function test_closing_conversation_cancels_active_flow_execution(): void
    {
        $flow = Flow::create(['name' => 'Demo Flow', 'status' => 'published']);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => 'published']);

        $conv = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'started_at' => now(),
        ]);

        $execution = FlowExecution::create([
            'flow_id' => $flow->id,
            'flow_version_id' => $version->id,
            'conversation_id' => $conv->id,
            'contact_id' => $this->contact->id,
            'channel_id' => $this->channel->id,
            'status' => FlowExecutionStatus::WAITING_AGENT,
            'context' => [],
            'started_at' => now(),
        ]);

        $this->actingAs($this->supervisor, 'sanctum')->postJson("/api/v1/conversations/{$conv->id}/close")->assertStatus(200);

        $this->assertEquals(FlowExecutionStatus::CANCELLED, $execution->fresh()->status);
        $this->assertNotNull($execution->fresh()->completed_at);
    }

    public function test_sending_messages_to_closed_non_meta_conversation_allows_followups(): void
    {
        $conv = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::CLOSED,
            'assigned_user_id' => $this->agentA->id,
            'started_at' => now(),
            'closed_at' => now(),
        ]);

        // Webchat follow-up from the assigned agent is allowed after closure.
        $agentRes = $this->actingAs($this->agentA, 'sanctum')
            ->postJson("/api/v1/conversations/{$conv->id}/messages", [
                'content' => 'Intento de mensaje en conversación cerrada',
            ]);
        $agentRes->assertStatus(201);

        // A supervisor can also send a non-Meta follow-up.
        $supRes = $this->actingAs($this->supervisor, 'sanctum')
            ->postJson("/api/v1/conversations/{$conv->id}/messages", [
                'content' => 'Intento de supervisor',
            ]);
        $supRes->assertStatus(201);

        $this->assertEquals(2, Message::where('conversation_id', $conv->id)->count());
    }

    public function test_read_state_tracking_and_independent_unread_count_per_user(): void
    {
        $conv = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agentA->id,
            'started_at' => now(),
        ]);

        // 1. Initial inbound message from contact
        $msg1 = Message::create([
            'conversation_id' => $conv->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $this->contact->id,
            'direction' => MessageDirection::INBOUND,
            'content' => 'Hola, tengo una consulta',
            'sent_at' => now()->subMinutes(10),
        ]);

        // Agent A and Supervisor index conversations: unread_count = 1
        $listAlice = $this->actingAs($this->agentA, 'sanctum')->getJson('/api/v1/conversations');
        $convItem = collect($listAlice->json('data'))->firstWhere('id', $conv->id);
        $this->assertEquals(1, $convItem['unread_count']);

        // Agent A marks conversation as read
        $readAlice = $this->actingAs($this->agentA, 'sanctum')->postJson("/api/v1/conversations/{$conv->id}/read");
        $readAlice->assertStatus(200)
            ->assertJsonPath('unread_count', 0);

        // Confirm Alice now has 0 unread
        $listAliceAfter = $this->actingAs($this->agentA, 'sanctum')->getJson('/api/v1/conversations');
        $convAliceItem = collect($listAliceAfter->json('data'))->firstWhere('id', $conv->id);
        $this->assertEquals(0, $convAliceItem['unread_count']);

        // Supervisor's unread state remains independent (Supervisor still has 1 unread)
        $listSup = $this->actingAs($this->supervisor, 'sanctum')->getJson('/api/v1/conversations');
        $convSupItem = collect($listSup->json('data'))->firstWhere('id', $conv->id);
        $this->assertEquals(1, $convSupItem['unread_count']);

        // 2. New inbound message arrives
        $msg2 = Message::create([
            'conversation_id' => $conv->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $this->contact->id,
            'direction' => MessageDirection::INBOUND,
            'content' => 'Sigues ahí?',
            'sent_at' => now(),
        ]);

        // Alice unread count increments to 1; Supervisor increments to 2
        $listAlice2 = $this->actingAs($this->agentA, 'sanctum')->getJson('/api/v1/conversations');
        $this->assertEquals(1, collect($listAlice2->json('data'))->firstWhere('id', $conv->id)['unread_count']);

        $listSup2 = $this->actingAs($this->supervisor, 'sanctum')->getJson('/api/v1/conversations');
        $this->assertEquals(2, collect($listSup2->json('data'))->firstWhere('id', $conv->id)['unread_count']);

        // Agent outbound reply does NOT increment unread
        Message::create([
            'conversation_id' => $conv->id,
            'sender_type' => MessageSenderType::AGENT,
            'sender_id' => (string) $this->agentA->id,
            'direction' => MessageDirection::OUTBOUND,
            'content' => 'Sí, aquí estoy.',
            'sent_at' => now()->addSecond(),
        ]);

        $listAlice3 = $this->actingAs($this->agentA, 'sanctum')->getJson('/api/v1/conversations');
        $this->assertEquals(1, collect($listAlice3->json('data'))->firstWhere('id', $conv->id)['unread_count']);
    }

    public function test_message_history_pagination_with_120_messages(): void
    {
        $conv = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agentA->id,
            'started_at' => now()->subHours(2),
        ]);

        // Seed 120 messages with strictly increasing timestamps
        $baseTime = now()->subMinutes(120);
        for ($i = 1; $i <= 120; $i++) {
            Message::create([
                'conversation_id' => $conv->id,
                'sender_type' => $i % 2 === 1 ? MessageSenderType::CONTACT : MessageSenderType::AGENT,
                'sender_id' => (string) ($i % 2 === 1 ? $this->contact->id : $this->agentA->id),
                'direction' => $i % 2 === 1 ? MessageDirection::INBOUND : MessageDirection::OUTBOUND,
                'content' => "Mensaje número {$i}",
                'sent_at' => (clone $baseTime)->addMinutes($i),
            ]);
        }

        // 1. Initial show returns latest 50 messages
        $showRes = $this->actingAs($this->agentA, 'sanctum')->getJson("/api/v1/conversations/{$conv->id}");
        $showRes->assertStatus(200);

        $initialMessages = $showRes->json('messages.data');
        $this->assertCount(50, $initialMessages);
        $this->assertTrue($showRes->json('messages.has_more'));

        // Earliest message in this page should be message #71, latest should be #120
        $this->assertEquals('Mensaje número 71', $initialMessages[0]['content']);
        $this->assertEquals('Mensaje número 120', $initialMessages[49]['content']);

        // 2. Fetch previous page using before cursor
        $oldestId = $showRes->json('messages.oldest_message_id');
        $this->assertNotNull($oldestId);

        $page2Res = $this->actingAs($this->agentA, 'sanctum')
            ->getJson("/api/v1/conversations/{$conv->id}/messages?before={$oldestId}");
        $page2Res->assertStatus(200);

        $page2Messages = $page2Res->json('data');
        $this->assertCount(50, $page2Messages);
        $this->assertTrue($page2Res->json('has_more'));
        $this->assertEquals('Mensaje número 21', $page2Messages[0]['content']);
        $this->assertEquals('Mensaje número 70', $page2Messages[49]['content']);

        // 3. Fetch earliest page (remaining 20 messages)
        $oldestPage2Id = $page2Res->json('oldest_message_id');
        $page3Res = $this->actingAs($this->agentA, 'sanctum')
            ->getJson("/api/v1/conversations/{$conv->id}/messages?before={$oldestPage2Id}");
        $page3Res->assertStatus(200);

        $page3Messages = $page3Res->json('data');
        $this->assertCount(20, $page3Messages);
        $this->assertFalse($page3Res->json('has_more'));
        $this->assertEquals('Mensaje número 1', $page3Messages[0]['content']);
        $this->assertEquals('Mensaje número 20', $page3Messages[19]['content']);

        // Ensure zero duplicates across pages
        $allIds = array_merge(
            array_column($initialMessages, 'id'),
            array_column($page2Messages, 'id'),
            array_column($page3Messages, 'id')
        );
        $this->assertCount(120, array_unique($allIds));
    }

    public function test_message_id_is_uuid7_monotonic_and_cursor_stable_on_identical_timestamps(): void
    {
        $conv = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agentA->id,
            'started_at' => now(),
        ]);

        // 1. Verify UUIDv7 format and monotonic ordering
        $msgA = Message::create([
            'conversation_id' => $conv->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $this->contact->id,
            'direction' => MessageDirection::INBOUND,
            'content' => 'First message',
            'sent_at' => now(),
        ]);

        usleep(2000); // 2ms to guarantee distinct millisecond in UUIDv7

        $msgB = Message::create([
            'conversation_id' => $conv->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $this->contact->id,
            'direction' => MessageDirection::INBOUND,
            'content' => 'Second message',
            'sent_at' => now(),
        ]);

        // Check UUIDv7 version nibble (character at index 14 is '7': xxxxxxxx-xxxx-7xxx-...)
        $this->assertEquals('7', substr($msgA->id, 14, 1), 'Message ID must be UUIDv7');
        $this->assertEquals('7', substr($msgB->id, 14, 1), 'Message ID must be UUIDv7');
        $this->assertTrue(strcmp($msgA->id, $msgB->id) < 0, 'UUIDv7 must be strictly monotonic');

        // 2. Cursor stability with identical sent_at timestamp
        $identicalTime = now()->subMinutes(5);
        $tieMsg1 = Message::create([
            'conversation_id' => $conv->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $this->contact->id,
            'direction' => MessageDirection::INBOUND,
            'content' => 'Tie message 1',
            'sent_at' => $identicalTime,
        ]);

        $tieMsg2 = Message::create([
            'conversation_id' => $conv->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $this->contact->id,
            'direction' => MessageDirection::INBOUND,
            'content' => 'Tie message 2',
            'sent_at' => $identicalTime,
        ]);

        $this->assertTrue(strcmp($tieMsg1->id, $tieMsg2->id) < 0);

        // Fetch messages with before=$tieMsg2->id; should return $tieMsg1 despite identical sent_at
        $pagedRes = $this->actingAs($this->agentA, 'sanctum')
            ->getJson("/api/v1/conversations/{$conv->id}/messages?before={$tieMsg2->id}&limit=10");
        $pagedRes->assertStatus(200);

        $pagedContents = collect($pagedRes->json('data'))->pluck('content')->all();
        $this->assertContains('Tie message 1', $pagedContents);
        $this->assertNotContains('Tie message 2', $pagedContents);
    }
}
