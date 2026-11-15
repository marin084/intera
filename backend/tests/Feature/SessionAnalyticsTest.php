<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SessionAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $agentUser;

    protected Channel $webchatChannel;

    protected Channel $whatsappChannel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        // Ensure clean conversation slate for analytics tests
        Message::query()->delete();
        Conversation::query()->delete();
        Contact::query()->delete();

        $this->adminUser = User::factory()->create();
        $this->adminUser->roles()->attach(Role::where('name', 'super_admin')->first());

        $this->agentUser = User::factory()->create();
        $this->agentUser->roles()->attach(Role::where('name', 'agent')->first());

        $this->webchatChannel = Channel::create(['name' => 'Webchat', 'type' => ChannelType::WEBCHAT, 'is_active' => true]);
        $this->whatsappChannel = Channel::create(['name' => 'WhatsApp', 'type' => ChannelType::WHATSAPP, 'is_active' => true]);
    }

    public function test_users_and_sessions_kpis_and_distributions(): void
    {
        Sanctum::actingAs($this->adminUser);

        // Contact 1: Handled 100% by Bot
        $contactBot = Contact::create(['display_name' => 'Bot User', 'external_identifier' => 'bot-user-1']);
        $convBot = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contactBot->id,
            'status' => ConversationStatus::BOT,
            'started_at' => Carbon::now()->subDays(2),
        ]);
        Message::create([
            'conversation_id' => $convBot->id,
            'sender_type' => MessageSenderType::CONTACT,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Hola',
            'sent_at' => Carbon::now()->subDays(2),
        ]);
        Message::create([
            'conversation_id' => $convBot->id,
            'sender_type' => MessageSenderType::BOT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Hola, soy el bot',
            'sent_at' => Carbon::now()->subDays(2)->addSecond(),
        ]);

        // Contact 2: Assigned to human agent and closed by agent
        $contactAgent = Contact::create(['display_name' => 'Human User', 'external_identifier' => 'human-user-2']);
        $convAgent = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $contactAgent->id,
            'status' => ConversationStatus::CLOSED,
            'assigned_user_id' => $this->agentUser->id,
            'started_at' => Carbon::now()->subDays(1),
            'closed_at' => Carbon::now()->subDays(1)->addMinutes(10),
        ]);
        Message::create([
            'conversation_id' => $convAgent->id,
            'sender_type' => MessageSenderType::CONTACT,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Necesito un humano',
            'sent_at' => Carbon::now()->subDays(1),
        ]);
        Message::create([
            'conversation_id' => $convAgent->id,
            'sender_type' => MessageSenderType::AGENT,
            'sender_id' => $this->agentUser->id,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Hola, te habla un agente',
            'sent_at' => Carbon::now()->subDays(1)->addMinute(),
        ]);

        // Request users-and-sessions analytics
        $response = $this->getJson('/api/v1/analytics/users-and-sessions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'date_range',
                    'kpis' => [
                        'total_sessions',
                        'unique_contacts',
                        'agent_sessions',
                        'bot_only_sessions',
                        'closed_by_agents',
                        'agent_session_percentage',
                        'bot_only_percentage',
                        'message_counts' => [
                            'user_messages',
                            'bot_messages',
                            'agent_messages',
                            'total_messages',
                        ],
                    ],
                    'channel_distribution',
                    'time_series',
                ],
            ]);

        $data = $response->json('data');

        // Total sessions = 2, Unique contacts = 2
        $this->assertEquals(2, $data['kpis']['total_sessions']);
        $this->assertEquals(2, $data['kpis']['unique_contacts']);

        // Agent sessions = 1, Bot only = 1
        $this->assertEquals(1, $data['kpis']['agent_sessions']);
        $this->assertEquals(1, $data['kpis']['bot_only_sessions']);
        $this->assertEquals(50.0, $data['kpis']['agent_session_percentage']);
        $this->assertEquals(50.0, $data['kpis']['bot_only_percentage']);
        $this->assertEquals(1, $data['kpis']['closed_by_agents']);

        // Messages breakdown: 2 user + 1 bot + 1 agent = 4
        $this->assertEquals(2, $data['kpis']['message_counts']['user_messages']);
        $this->assertEquals(1, $data['kpis']['message_counts']['bot_messages']);
        $this->assertEquals(1, $data['kpis']['message_counts']['agent_messages']);
        $this->assertEquals(4, $data['kpis']['message_counts']['total_messages']);

        // Channel distribution: 1 webchat (50%), 1 whatsapp (50%)
        $this->assertCount(2, $data['channel_distribution']);
    }

    public function test_users_and_sessions_export_csv(): void
    {
        Sanctum::actingAs($this->adminUser);

        $contact = Contact::create([
            'display_name' => 'CSV Export Contact',
            'external_identifier' => 'exp-1',
            'phone' => '+1800123456',
            'email' => 'csv@conexa.ai',
        ]);

        Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
            'started_at' => Carbon::now()->subHours(3),
        ]);

        $response = $this->get('/api/v1/analytics/users-and-sessions/export');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('CSV Export Contact', $response->getContent());
        $this->assertStringContainsString('+1800123456', $response->getContent());
        $this->assertStringContainsString('csv@conexa.ai', $response->getContent());
    }
}
