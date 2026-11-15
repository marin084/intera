<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\FlowNodeType;
use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Events\ContactUpdated;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\Runtime\ConversationIngressService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReleaseCandidatePolishTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $agentA;

    protected User $agentB;

    protected Channel $channel;

    protected ConversationIngressService $ingress;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->ingress = app(ConversationIngressService::class);

        $this->admin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('AdminSecret123!'),
            'is_active' => true,
        ]);
        $this->admin->roles()->attach(Role::where('name', 'super_admin')->first());

        $this->agentA = User::create([
            'name' => 'Agent Alpha',
            'email' => 'agent.a@example.com',
            'password' => Hash::make('AgentSecret123!'),
            'is_active' => true,
        ]);
        $this->agentA->roles()->attach(Role::where('name', 'agent')->first());

        $this->agentB = User::create([
            'name' => 'Agent Beta',
            'email' => 'agent.b@example.com',
            'password' => Hash::make('AgentSecret123!'),
            'is_active' => true,
        ]);
        $this->agentB->roles()->attach(Role::where('name', 'agent')->first());

        $this->channel = Channel::create([
            'name' => 'Webchat Main',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
            'settings' => [],
        ]);
    }

    private function createFlowWithQuestion(array $questionConfig): FlowVersion
    {
        $flow = Flow::create([
            'name' => 'Question Contact Flow',
            'status' => FlowStatus::DRAFT,
            'channel_applicability' => ['webchat'],
        ]);

        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::DRAFT,
        ]);

        $startNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_node',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
            'configuration' => [],
        ]);

        $qNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'q_node',
            'type' => FlowNodeType::QUESTION,
            'name' => 'Pregunta',
            'configuration' => $questionConfig,
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start_node',
            'target_node_key' => 'q_node',
        ]);

        $version->update(['status' => FlowVersionStatus::PUBLISHED]);
        $flow->update(['status' => FlowStatus::PUBLISHED, 'active_version_id' => $version->id]);

        return $version->fresh(['nodes', 'edges', 'flow']);
    }

    public function test_question_node_updates_contact_display_name_and_broadcasts_event(): void
    {
        Event::fake([ContactUpdated::class]);

        $version = $this->createFlowWithQuestion([
            'text' => '¿Cómo te llamas?',
            'variable' => 'nombre',
            'input_type' => 'text',
            'contact_field' => 'display_name',
        ]);
        $this->channel->update(['entry_flow_id' => $version->flow_id]);

        $contactPayload = ['external_identifier' => 'visitor_001'];

        // 1. Initial inbound triggers flow and pauses at question
        $this->ingress->handleInboundMessage($this->channel, $contactPayload, 'Hola');

        // 2. Visitor provides their name
        $this->ingress->handleInboundMessage($this->channel, $contactPayload, 'Carlos Santana');

        $contact = Contact::where('external_identifier', 'visitor_001')
            ->first();

        $this->assertNotNull($contact);
        $this->assertEquals('Carlos Santana', $contact->display_name);

        Event::assertDispatched(ContactUpdated::class, function ($event) use ($contact) {
            return $event->contact->id === $contact->id && $event->contact->display_name === 'Carlos Santana';
        });
    }

    public function test_question_node_validates_and_updates_contact_email(): void
    {
        $version = $this->createFlowWithQuestion([
            'text' => '¿Cuál es tu correo?',
            'variable' => 'correo',
            'input_type' => 'email',
            'contact_field' => 'email',
        ]);
        $this->channel->update(['entry_flow_id' => $version->flow_id]);

        $contactPayload = ['external_identifier' => 'visitor_002'];

        // Initial message
        $this->ingress->handleInboundMessage($this->channel, $contactPayload, 'Hola');

        // Invalid email attempt
        $this->ingress->handleInboundMessage($this->channel, $contactPayload, 'not-an-email');

        $contact = Contact::where('external_identifier', 'visitor_002')
            ->first();
        $this->assertNull($contact->email);

        // Valid email attempt
        $this->ingress->handleInboundMessage($this->channel, $contactPayload, 'carlos@santana.com');

        $contact->refresh();
        $this->assertEquals('carlos@santana.com', $contact->email);
    }

    public function test_question_node_updates_contact_phone(): void
    {
        $version = $this->createFlowWithQuestion([
            'text' => '¿Cuál es tu número?',
            'variable' => 'telefono',
            'input_type' => 'text',
            'contact_field' => 'phone',
        ]);
        $this->channel->update(['entry_flow_id' => $version->flow_id]);

        $contactPayload = ['external_identifier' => 'visitor_003'];

        $this->ingress->handleInboundMessage($this->channel, $contactPayload, 'Hola');
        $this->ingress->handleInboundMessage($this->channel, $contactPayload, '+52 55 1234 5678');

        $contact = Contact::where('external_identifier', 'visitor_003')
            ->first();

        $this->assertEquals('+525512345678', $contact->phone);
    }

    public function test_inbox_search_by_contact_name_email_and_phone(): void
    {
        $contact1 = Contact::create([
            'external_identifier' => 'ext_juan',
            'display_name' => 'Juan Pérez',
            'email' => 'juan@test.com',
            'phone' => '+521111111111',
        ]);
        Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $contact1->id,
            'status' => 'waiting_agent',
        ]);

        $contact2 = Contact::create([
            'external_identifier' => 'ext_maria',
            'display_name' => 'María García',
            'email' => 'maria@empresa.com',
            'phone' => '+522222222222',
        ]);
        Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $contact2->id,
            'status' => 'waiting_agent',
        ]);

        $this->actingAs($this->admin);

        // Search by name
        $resName = $this->getJson('/api/v1/conversations?search=Juan');
        $resName->assertStatus(200);
        $this->assertCount(1, $resName->json('data'));
        $this->assertEquals('Juan Pérez', $resName->json('data.0.contact.display_name'));

        // Search by email
        $resEmail = $this->getJson('/api/v1/conversations?search=empresa.com');
        $resEmail->assertStatus(200);
        $this->assertCount(1, $resEmail->json('data'));
        $this->assertEquals('María García', $resEmail->json('data.0.contact.display_name'));

        // Search by phone
        $resPhone = $this->getJson('/api/v1/conversations?search=1111111111');
        $resPhone->assertStatus(200);
        $this->assertCount(1, $resPhone->json('data'));
        $this->assertEquals('Juan Pérez', $resPhone->json('data.0.contact.display_name'));
    }

    public function test_inbox_filter_by_assigned_user(): void
    {
        $contactA = Contact::create(['external_identifier' => 'a']);
        $convA = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $contactA->id,
            'status' => 'assigned',
            'assigned_user_id' => $this->agentA->id,
        ]);

        $contactB = Contact::create(['external_identifier' => 'b']);
        $convB = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $contactB->id,
            'status' => 'assigned',
            'assigned_user_id' => $this->agentB->id,
        ]);

        $this->actingAs($this->admin);

        // Filter Agent A
        $resA = $this->getJson("/api/v1/conversations?assigned_user_id={$this->agentA->id}");
        $resA->assertStatus(200);
        $this->assertCount(1, $resA->json('data'));
        $this->assertEquals($convA->id, $resA->json('data.0.id'));

        // Filter Agent B
        $resB = $this->getJson("/api/v1/conversations?assigned_user_id={$this->agentB->id}");
        $resB->assertStatus(200);
        $this->assertCount(1, $resB->json('data'));
        $this->assertEquals($convB->id, $resB->json('data.0.id'));
    }

    public function test_inbox_channel_filter_applied_server_side_before_pagination(): void
    {
        $channel2 = Channel::create([
            'name' => 'Webchat Secondary',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
            'settings' => [],
        ]);

        // Create 25 conversations in $this->channel (Channel 1)
        for ($i = 1; $i <= 25; $i++) {
            $contact = Contact::create([
                'external_identifier' => "ch1_contact_{$i}",
                'display_name' => "User Ch1 {$i}",
            ]);
            Conversation::create([
                'channel_id' => $this->channel->id,
                'contact_id' => $contact->id,
                'status' => 'waiting_agent',
            ]);
        }

        // Create 10 conversations in $channel2 (Channel 2)
        for ($j = 1; $j <= 10; $j++) {
            $contact = Contact::create([
                'external_identifier' => "ch2_contact_{$j}",
                'display_name' => "User Ch2 {$j}",
            ]);
            Conversation::create([
                'channel_id' => $channel2->id,
                'contact_id' => $contact->id,
                'status' => 'waiting_agent',
            ]);
        }

        $this->actingAs($this->admin);

        // 1. Channel 1, Page 1: Should have exactly 20 items, total 25, last_page 2
        $resCh1P1 = $this->getJson("/api/v1/conversations?channel_id={$this->channel->id}&page=1");
        $resCh1P1->assertStatus(200);
        $this->assertEquals(25, $resCh1P1->json('total'));
        $this->assertEquals(1, $resCh1P1->json('current_page'));
        $this->assertEquals(2, $resCh1P1->json('last_page'));
        $this->assertCount(20, $resCh1P1->json('data'));
        foreach ($resCh1P1->json('data') as $item) {
            $this->assertEquals($this->channel->id, $item['channel_id']);
        }

        // 2. Channel 1, Page 2: Should have remaining 5 items, total 25
        $resCh1P2 = $this->getJson("/api/v1/conversations?channel_id={$this->channel->id}&page=2");
        $resCh1P2->assertStatus(200);
        $this->assertEquals(25, $resCh1P2->json('total'));
        $this->assertEquals(2, $resCh1P2->json('current_page'));
        $this->assertCount(5, $resCh1P2->json('data'));
        foreach ($resCh1P2->json('data') as $item) {
            $this->assertEquals($this->channel->id, $item['channel_id']);
        }

        // 3. Channel 2, Page 1: Should have exactly 10 items, total 10, last_page 1
        $resCh2P1 = $this->getJson("/api/v1/conversations?channel_id={$channel2->id}&page=1");
        $resCh2P1->assertStatus(200);
        $this->assertEquals(10, $resCh2P1->json('total'));
        $this->assertEquals(1, $resCh2P1->json('current_page'));
        $this->assertEquals(1, $resCh2P1->json('last_page'));
        $this->assertCount(10, $resCh2P1->json('data'));
        foreach ($resCh2P1->json('data') as $item) {
            $this->assertEquals($channel2->id, $item['channel_id']);
        }

        // 4. Combined: channel_id + status + search + page
        $resCombined = $this->getJson("/api/v1/conversations?channel_id={$this->channel->id}&status=waiting_agent&search=User Ch1 1&page=1");
        $resCombined->assertStatus(200);
        // Matches "User Ch1 1", "User Ch1 10" through "User Ch1 19" = 11 matches
        $this->assertEquals(11, $resCombined->json('total'));
        foreach ($resCombined->json('data') as $item) {
            $this->assertEquals($this->channel->id, $item['channel_id']);
            $this->assertEquals('waiting_agent', $item['status']);
        }
    }

    public function test_self_service_password_change_success_and_audit(): void
    {
        $this->actingAs($this->agentA);

        $response = $this->putJson('/api/v1/auth/password', [
            'current_password' => 'AgentSecret123!',
            'password' => 'NewSecurePassword456!',
            'password_confirmation' => 'NewSecurePassword456!',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Contraseña actualizada exitosamente.',
            ]);

        $this->agentA->refresh();
        $this->assertTrue(Hash::check('NewSecurePassword456!', $this->agentA->password));

        // Verify audit log exists and does NOT contain passwords
        $audit = AuditLog::where('action', 'user.password_changed')
            ->where('entity_id', (string) $this->agentA->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertArrayNotHasKey('password', $audit->metadata);
        $this->assertArrayNotHasKey('current_password', $audit->metadata);
    }

    public function test_self_service_password_change_validations(): void
    {
        $this->actingAs($this->agentA);

        // 1. Incorrect current password
        $resIncorrect = $this->putJson('/api/v1/auth/password', [
            'current_password' => 'WrongSecret123!',
            'password' => 'NewSecurePassword456!',
            'password_confirmation' => 'NewSecurePassword456!',
        ]);
        $resIncorrect->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        // 2. Short password (< 12 chars)
        $resShort = $this->putJson('/api/v1/auth/password', [
            'current_password' => 'AgentSecret123!',
            'password' => 'Short123!',
            'password_confirmation' => 'Short123!',
        ]);
        $resShort->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        // 3. Confirmation mismatch
        $resMismatch = $this->putJson('/api/v1/auth/password', [
            'current_password' => 'AgentSecret123!',
            'password' => 'NewSecurePassword456!',
            'password_confirmation' => 'DifferentPassword789!',
        ]);
        $resMismatch->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }
}
