<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $agent1;
    protected User $agent2;
    protected Channel $webchatChannel;
    protected Channel $whatsappChannel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Message::query()->delete();
        ConversationAssignment::query()->delete();
        Conversation::query()->delete();
        Contact::query()->delete();

        $superAdminRole = Role::where('name', 'super_admin')->first();
        $agentRole = Role::where('name', 'agent')->first();

        $this->admin = User::factory()->create(['is_active' => true, 'last_seen_at' => now()]);
        $this->admin->roles()->sync([$superAdminRole->id]);

        $this->agent1 = User::factory()->create([
            'name' => 'Carlos Operador',
            'email' => 'carlos@test.com',
            'is_active' => true,
            'last_seen_at' => now()->subMinute(),
        ]);
        $this->agent1->roles()->sync([$agentRole->id]);

        $this->agent2 = User::factory()->create([
            'name' => 'Mariana Agente',
            'email' => 'mariana@test.com',
            'is_active' => true,
            'last_seen_at' => now()->subHours(2), // offline
        ]);
        $this->agent2->roles()->sync([$agentRole->id]);

        $this->webchatChannel = Channel::firstOrCreate(['name' => 'Webchat Principal'], [
            'type' => ChannelType::WEBCHAT->value,
            'is_active' => true,
        ]);

        $this->whatsappChannel = Channel::firstOrCreate(['name' => 'WhatsApp Central'], [
            'type' => ChannelType::WHATSAPP->value,
            'is_active' => true,
        ]);
    }

    public function test_agents_metrics_endpoint_returns_kpis_and_agent_breakdown(): void
    {
        $contact = Contact::create(['display_name' => 'Roberto', 'phone' => '+5491199998888']);

        // Chat 1: Assigned to agent1, active
        $conv1 = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::ASSIGNED->value,
            'assigned_user_id' => $this->agent1->id,
            'started_at' => now()->subMinutes(30),
            'created_at' => now()->subMinutes(30),
        ]);

        ConversationAssignment::create([
            'conversation_id' => $conv1->id,
            'assigned_user_id' => $this->agent1->id,
            'assigned_at' => now()->subMinutes(25),
        ]);

        // Agent 1 replied
        Message::create([
            'conversation_id' => $conv1->id,
            'sender_type' => MessageSenderType::AGENT->value,
            'sender_id' => (string) $this->agent1->id,
            'direction' => MessageDirection::OUTBOUND->value,
            'content' => 'Hola Roberto, te ayudo con gusto',
            'sent_at' => now()->subMinutes(23), // 2 min first response (120s)
        ]);

        // Chat 2: Assigned to agent1, closed
        $conv2 = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::CLOSED->value,
            'assigned_user_id' => $this->agent1->id,
            'started_at' => now()->subHours(2),
            'created_at' => now()->subHours(2),
            'closed_at' => now()->subHour(),
        ]);

        // Request metrics
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/analytics/agents');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'date_range' => ['from', 'to'],
                    'kpis' => [
                        'total_assigned',
                        'total_active',
                        'total_closed',
                        'avg_first_response_seconds',
                        'avg_first_response_formatted',
                        'avg_response_time_seconds',
                        'avg_response_time_formatted',
                        'avg_attention_duration_seconds',
                        'avg_attention_duration_formatted',
                    ],
                    'agents' => [
                        '*' => [
                            'agent_id',
                            'name',
                            'email',
                            'presence_status',
                            'last_seen_at',
                            'assigned_conversations',
                            'active_conversations',
                            'closed_conversations',
                            'closed_percentage',
                            'waiting_response_count',
                            'avg_first_response_seconds',
                            'avg_first_response_formatted',
                        ],
                    ],
                ],
            ]);

        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(1, $data['kpis']['total_active']);
        $this->assertGreaterThanOrEqual(1, $data['kpis']['total_closed']);

        // Find agent 1 in results
        $carlos = collect($data['agents'])->firstWhere('agent_id', $this->agent1->id);
        $this->assertNotNull($carlos);
        $this->assertEquals('online', $carlos['presence_status']);
        $this->assertEquals(1, $carlos['active_conversations']);
        $this->assertEquals(1, $carlos['closed_conversations']);
        $this->assertEquals(120, $carlos['avg_first_response_seconds']);

        // Find agent 2 in results
        $mariana = collect($data['agents'])->firstWhere('agent_id', $this->agent2->id);
        $this->assertNotNull($mariana);
        $this->assertEquals('offline', $mariana['presence_status']);
    }

    public function test_agents_export_summary_csv(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->get('/api/v1/analytics/agents/export-summary');

        $response->assertOk();
        $this->assertEquals('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $content = $response->getContent();

        $this->assertStringContainsString('Nombre del Agente', $content);
        $this->assertStringContainsString('Carlos Operador', $content);
        $this->assertStringContainsString('Mariana Agente', $content);
    }

    public function test_agents_export_conversations_detail_csv(): void
    {
        $contact = Contact::create(['display_name' => 'Esteban', 'phone' => '+5491144443333']);

        Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::ASSIGNED->value,
            'assigned_user_id' => $this->agent1->id,
            'started_at' => now()->subMinutes(10),
            'created_at' => now()->subMinutes(10),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->get('/api/v1/analytics/agents/export-conversations');

        $response->assertOk();
        $this->assertEquals('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $content = $response->getContent();

        $this->assertStringContainsString('ID Conversación', $content);
        $this->assertStringContainsString('Esteban', $content);
        $this->assertStringContainsString('Carlos Operador', $content);
    }

    public function test_agent_user_only_sees_own_metrics_and_cannot_access_other_agents(): void
    {
        // Agent 1 queries agents endpoint trying to see agent 2
        $response = $this->actingAs($this->agent1, 'sanctum')
            ->getJson('/api/v1/analytics/agents?agent_id='.$this->agent2->id);

        $response->assertOk();
        $data = $response->json('data');

        // Should ONLY contain Agent 1, agent 2 is filtered out by controller restriction
        $this->assertCount(1, $data['agents']);
        $this->assertEquals($this->agent1->id, $data['agents'][0]['agent_id']);
        $this->assertEquals('Carlos Operador', $data['agents'][0]['name']);
    }

    public function test_agent_user_is_forbidden_from_realtime_sessions_and_funnels(): void
    {
        // Realtime endpoint
        $this->actingAs($this->agent1, 'sanctum')
            ->getJson('/api/v1/analytics/realtime')
            ->assertForbidden();

        // Users and sessions endpoint
        $this->actingAs($this->agent1, 'sanctum')
            ->getJson('/api/v1/analytics/users-and-sessions')
            ->assertForbidden();

        // Users and sessions export
        $this->actingAs($this->agent1, 'sanctum')
            ->get('/api/v1/analytics/users-and-sessions/export')
            ->assertForbidden();

        // Funnels endpoint
        $this->actingAs($this->agent1, 'sanctum')
            ->getJson('/api/v1/analytics/funnels')
            ->assertForbidden();

        // Funnel contacts endpoint
        $this->actingAs($this->agent1, 'sanctum')
            ->getJson('/api/v1/analytics/funnels/contacts?flow_id=00000000-0000-0000-0000-000000000000')
            ->assertForbidden();

        // Funnel step contacts endpoint
        $this->actingAs($this->agent1, 'sanctum')
            ->getJson('/api/v1/analytics/funnels/step-contacts?flow_id=00000000-0000-0000-0000-000000000000&step_number=1')
            ->assertForbidden();
    }

    public function test_agent_user_can_export_only_own_summary_and_conversations(): void
    {
        $contact = Contact::create(['display_name' => 'Lucas', 'phone' => '+5491100001111']);

        Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::ASSIGNED->value,
            'assigned_user_id' => $this->agent1->id,
            'started_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
        ]);

        Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::ASSIGNED->value,
            'assigned_user_id' => $this->agent2->id,
            'started_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
        ]);

        // Agent 1 summary export
        $summaryResp = $this->actingAs($this->agent1, 'sanctum')
            ->get('/api/v1/analytics/agents/export-summary?agent_id='.$this->agent2->id);

        $summaryResp->assertOk();
        $summaryContent = $summaryResp->getContent();
        $this->assertStringContainsString('Carlos Operador', $summaryContent);
        $this->assertStringNotContainsString('Mariana Agente', $summaryContent);

        // Agent 1 conversation detail export
        $detailResp = $this->actingAs($this->agent1, 'sanctum')
            ->get('/api/v1/analytics/agents/export-conversations?agent_id='.$this->agent2->id);

        $detailResp->assertOk();
        $detailContent = $detailResp->getContent();
        $this->assertStringContainsString('Carlos Operador', $detailContent);
        $this->assertStringNotContainsString('Mariana Agente', $detailContent);
    }
}
