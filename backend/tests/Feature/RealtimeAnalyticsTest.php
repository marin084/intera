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
use App\Models\WebchatSession;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealtimeAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Channel $webchatChannel;
    protected Channel $whatsappChannel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Message::query()->delete();
        ConversationAssignment::query()->delete();
        WebchatSession::query()->delete();
        Conversation::query()->delete();
        Contact::query()->delete();

        $this->admin = User::factory()->create([
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $superAdminRole = Role::where('name', 'super_admin')->first();
        $this->admin->roles()->sync([$superAdminRole->id]);

        $this->webchatChannel = Channel::firstOrCreate(['name' => 'Webchat Soporte'], [
            'type' => ChannelType::WEBCHAT->value,
            'is_active' => true,
        ]);

        $this->whatsappChannel = Channel::firstOrCreate(['name' => 'WhatsApp Oficial'], [
            'type' => ChannelType::WHATSAPP->value,
            'is_active' => true,
        ]);
    }

    public function test_realtime_analytics_returns_accurate_kpis_and_queues(): void
    {
        $contact1 = Contact::create([
            'display_name' => 'Lucas Mora',
            'email' => 'lucas@test.com',
        ]);
        $contact2 = Contact::create([
            'display_name' => 'Sofia Rios',
            'email' => 'sofia@test.com',
        ]);

        // 1. Webchat active visitors: 2 active in last 15 min, 1 expired/old
        WebchatSession::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contact1->id,
            'token_hash' => hash('sha256', 'token-1'),
            'origin' => 'http://localhost',
            'last_seen_at' => now()->subMinutes(5),
            'expires_at' => now()->addHours(2),
        ]);
        WebchatSession::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contact2->id,
            'token_hash' => hash('sha256', 'token-2'),
            'origin' => 'http://localhost',
            'last_seen_at' => now()->subMinutes(10),
            'expires_at' => now()->addHours(2),
        ]);
        WebchatSession::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contact1->id,
            'token_hash' => hash('sha256', 'token-3'),
            'origin' => 'http://localhost',
            'last_seen_at' => now()->subMinutes(30), // Not in last 15m
            'expires_at' => now()->addHours(2),
        ]);

        // 2. Conversations in last hour
        $conv1 = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contact1->id,
            'status' => ConversationStatus::WAITING_AGENT->value,
            'started_at' => now()->subMinutes(20),
            'created_at' => now()->subMinutes(20),
        ]);

        $conv2 = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $contact2->id,
            'status' => ConversationStatus::ASSIGNED->value,
            'assigned_user_id' => $this->admin->id,
            'started_at' => now()->subMinutes(40),
            'created_at' => now()->subMinutes(40),
        ]);

        // Previous hour conversation (for trend calculation)
        Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contact1->id,
            'status' => ConversationStatus::CLOSED->value,
            'started_at' => now()->subMinutes(90),
            'created_at' => now()->subMinutes(90),
            'closed_at' => now()->subMinutes(70),
        ]);

        // Messages in last hour
        Message::create([
            'conversation_id' => $conv1->id,
            'sender_type' => MessageSenderType::CONTACT->value,
            'direction' => MessageDirection::INBOUND->value,
            'content' => 'Hola, necesito ayuda',
            'sent_at' => now()->subMinutes(15),
        ]);

        Message::create([
            'conversation_id' => $conv2->id,
            'sender_type' => MessageSenderType::AGENT->value,
            'sender_id' => (string) $this->admin->id,
            'direction' => MessageDirection::OUTBOUND->value,
            'content' => 'Hola, en qué te puedo servir?',
            'sent_at' => now()->subMinutes(10),
        ]);

        // Assignment for conv2
        ConversationAssignment::create([
            'conversation_id' => $conv2->id,
            'assigned_user_id' => $this->admin->id,
            'assigned_at' => now()->subMinutes(12),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/analytics/realtime');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'last_hour_kpis' => [
                        'active_webchat_visitors',
                        'unread_chats',
                        'chats_last_hour',
                        'chats_previous_hour',
                        'chats_trend_percentage',
                        'messages_last_hour' => [
                            'sent',
                            'received',
                            'total',
                        ],
                    ],
                    'operating_times' => [
                        'avg_queue_wait_seconds',
                        'avg_queue_wait_formatted',
                        'avg_first_response_seconds',
                        'avg_first_response_formatted',
                        'avg_resolution_seconds',
                        'avg_resolution_formatted',
                    ],
                    'agent_conversations' => [
                        'ongoing',
                        'closed_today',
                        'active_agents_count',
                    ],
                    'channels_summary',
                    'updated_at',
                ],
            ]);

        $data = $response->json('data');

        $this->assertEquals(2, $data['last_hour_kpis']['active_webchat_visitors']);
        $this->assertGreaterThanOrEqual(1, $data['last_hour_kpis']['unread_chats']);
        $this->assertEquals(2, $data['last_hour_kpis']['chats_last_hour']);
        $this->assertEquals(1, $data['last_hour_kpis']['chats_previous_hour']);
        $this->assertEquals(100.0, $data['last_hour_kpis']['chats_trend_percentage']);
        $this->assertEquals(1, $data['last_hour_kpis']['messages_last_hour']['sent']);
        $this->assertEquals(1, $data['last_hour_kpis']['messages_last_hour']['received']);
        $this->assertEquals(2, $data['last_hour_kpis']['messages_last_hour']['total']);

        // Ongoing conversations
        $this->assertEquals(1, $data['agent_conversations']['ongoing']);
        $this->assertEquals(1, $data['agent_conversations']['closed_today']);

        // Channels summary contains webchat and whatsapp
        $this->assertGreaterThanOrEqual(2, count($data['channels_summary']));
        $channelIds = collect($data['channels_summary'])->pluck('channel_id');
        $this->assertTrue($channelIds->contains($this->webchatChannel->id));
        $this->assertTrue($channelIds->contains($this->whatsappChannel->id));
    }

    public function test_realtime_analytics_filters_by_channel(): void
    {
        $contact = Contact::create(['display_name' => 'Carla', 'email' => 'carla@test.com']);

        Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::WAITING_AGENT->value,
            'started_at' => now()->subMinutes(10),
            'created_at' => now()->subMinutes(10),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/analytics/realtime?channel_id='.$this->whatsappChannel->id);

        $response->assertOk();
        $data = $response->json('data');

        $this->assertEquals(1, $data['last_hour_kpis']['chats_last_hour']);
        // Webchat visitors on whatsapp channel filter should be 0
        $this->assertEquals(0, $data['last_hour_kpis']['active_webchat_visitors']);
    }

    public function test_realtime_analytics_never_counts_another_workspace(): void
    {
        $otherWorkspace = Workspace::create([
            'name' => 'Workspace aislado',
            'slug' => 'workspace-aislado-'.uniqid(),
            'status' => 'active',
        ]);
        $otherChannel = Channel::create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Webchat aislado',
            'type' => ChannelType::WEBCHAT->value,
            'is_active' => true,
        ]);
        $otherContact = Contact::create([
            'workspace_id' => $otherWorkspace->id,
            'display_name' => 'Contacto aislado',
            'email' => 'aislado@example.test',
        ]);
        Conversation::create([
            'workspace_id' => $otherWorkspace->id,
            'channel_id' => $otherChannel->id,
            'contact_id' => $otherContact->id,
            'status' => ConversationStatus::WAITING_AGENT->value,
            'queued_at' => now()->subMinutes(2),
            'started_at' => now()->subMinutes(2),
            'created_at' => now()->subMinutes(2),
        ]);
        WebchatSession::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'channel_id' => $otherChannel->id,
            'contact_id' => $otherContact->id,
            'token_hash' => hash('sha256', 'isolated-token'),
            'origin' => 'http://isolated.example.test',
            'last_seen_at' => now()->subMinutes(2),
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/analytics/realtime');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertSame(0, $data['last_hour_kpis']['active_webchat_visitors']);
        $this->assertSame(0, $data['last_hour_kpis']['chats_last_hour']);
        $this->assertSame(0, $data['last_hour_kpis']['unread_chats']);
        $this->assertSame(0, $data['service_target_alerts']['queue']['tracked']);
        $this->assertFalse(collect($data['channels_summary'])->pluck('channel_id')->contains($otherChannel->id));
    }
}
