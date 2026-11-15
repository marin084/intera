<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\FlowExecutionStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageType;
use App\Events\ConversationClosed;
use App\Events\ConversationUpdated;
use App\Events\MessageCreated;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\Flow;
use App\Models\FlowExecution;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Services\ConversationInactivityService;
use App\Services\Runtime\Channels\InboundMessage;
use App\Services\Runtime\ConversationIngressService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConversationInactivityTest extends TestCase
{
    use RefreshDatabase;

    protected Channel $webchatChannel;

    protected Channel $whatsappChannel;

    protected Contact $contact;

    protected User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $agentRole = Role::where('name', 'agent')->first();
        $this->agent = User::create([
            'name' => 'Agente Soporte',
            'email' => 'agente@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
            'role_id' => $agentRole->id,
        ]);

        $this->webchatChannel = Channel::create([
            'name' => 'Webchat Test',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
            'configuration' => [
                'inactivity_enabled' => true,
                'inactivity_warning_minutes' => 10,
                'inactivity_warning_message' => 'Hola {{nombre}}, ¿sigues por aquí?',
                'inactivity_close_minutes' => 15,
                'inactivity_close_message' => 'Hasta pronto {{nombre}}, cerramos por inactividad.',
            ],
        ]);

        $this->whatsappChannel = Channel::create([
            'name' => 'WhatsApp Test',
            'type' => ChannelType::WHATSAPP,
            'is_active' => true,
            'configuration' => [
                'inactivity_enabled' => true,
                'inactivity_warning_minutes' => 15,
                'inactivity_close_minutes' => 30,
            ],
        ]);

        $this->contact = Contact::create([
            'channel_id' => $this->webchatChannel->id,
            'external_identifier' => 'visitor_123',
            'display_name' => 'Carlos Morales',
        ]);
    }

    public function test_inactive_outbound_conversation_receives_warning_and_records_warned_at(): void
    {
        Event::fake([MessageCreated::class]);

        $conversation = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::BOT,
            'metadata' => [],
            'started_at' => now()->subMinutes(20),
            'last_message_at' => now()->subMinutes(11),
        ]);

        // Outbound message sent 11 minutes ago (threshold is 10)
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::OUTBOUND,
            'sender_type' => MessageSenderType::BOT,
            'message_type' => MessageType::TEXT,
            'content' => '¿En qué puedo ayudarte?',
            'sent_at' => now()->subMinutes(11),
        ]);

        $this->artisan('conversations:check-inactivity')
            ->assertSuccessful();

        $conversation->refresh();

        // Must record inactivity_warned_at in metadata
        $this->assertNotNull($conversation->metadata['inactivity_warned_at'] ?? null);

        // A bot warning message must be created
        $warningMessage = Message::where('conversation_id', $conversation->id)
            ->where('metadata->is_inactivity_warning', true)
            ->first();

        $this->assertNotNull($warningMessage);
        $this->assertSame(MessageDirection::OUTBOUND, $warningMessage->direction);
        $this->assertSame(MessageSenderType::BOT, $warningMessage->sender_type);
        $this->assertStringContainsString('Carlos Morales', $warningMessage->content);

        Event::assertDispatched(MessageCreated::class, function ($event) use ($warningMessage) {
            return $event->message->id === $warningMessage->id;
        });
    }

    public function test_does_not_send_second_warning_if_already_warned(): void
    {
        Event::fake([MessageCreated::class]);

        $conversation = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::BOT,
            'metadata' => [
                'inactivity_warned_at' => now()->subMinutes(2)->toIso8601String(),
            ],
            'started_at' => now()->subMinutes(25),
            'last_message_at' => now()->subMinutes(2),
        ]);

        // Prior outbound message
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::OUTBOUND,
            'sender_type' => MessageSenderType::BOT,
            'message_type' => MessageType::TEXT,
            'content' => 'Mensaje inicial',
            'sent_at' => now()->subMinutes(12),
        ]);

        // Warning message already created 2 minutes ago
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::OUTBOUND,
            'sender_type' => MessageSenderType::BOT,
            'message_type' => MessageType::TEXT,
            'content' => 'Hola Carlos, ¿sigues por aquí?',
            'metadata' => ['is_inactivity_warning' => true],
            'sent_at' => now()->subMinutes(2),
        ]);

        $this->artisan('conversations:check-inactivity')
            ->assertSuccessful();

        // Still active, no new warning message dispatched
        $conversation->refresh();
        $this->assertSame(ConversationStatus::BOT, $conversation->status);

        $warningsCount = Message::where('conversation_id', $conversation->id)
            ->where('metadata->is_inactivity_warning', true)
            ->count();
        $this->assertSame(1, $warningsCount);
        Event::assertNotDispatched(MessageCreated::class);
    }

    public function test_closes_conversation_cancels_execution_and_dispatches_events_when_close_time_reached(): void
    {
        Event::fake([MessageCreated::class, ConversationUpdated::class, ConversationClosed::class]);

        $flow = Flow::create(['name' => 'Demo Flow', 'status' => 'published']);
        $flowVersion = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => 'published',
            'definition' => ['nodes' => [], 'edges' => []],
        ]);

        $conversation = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agent->id,
            'metadata' => [
                'inactivity_warned_at' => now()->subMinutes(6)->toIso8601String(),
            ],
            'started_at' => now()->subMinutes(30),
            'last_message_at' => now()->subMinutes(6),
        ]);

        // Execution in progress
        $execution = FlowExecution::create([
            'conversation_id' => $conversation->id,
            'flow_id' => $flow->id,
            'flow_version_id' => $flowVersion->id,
            'status' => FlowExecutionStatus::RUNNING,
            'started_at' => now()->subMinutes(30),
        ]);

        $assignment = ConversationAssignment::create([
            'conversation_id' => $conversation->id,
            'assigned_user_id' => $this->agent->id,
            'assigned_by_user_id' => $this->agent->id,
            'assigned_at' => now()->subMinutes(30),
        ]);

        // Prior outbound message sent 16 minutes ago (close threshold is 15 min total, warning was at 10 min, diff is 5 min)
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::OUTBOUND,
            'sender_type' => MessageSenderType::AGENT,
            'message_type' => MessageType::TEXT,
            'content' => '¿Sigues allí?',
            'sent_at' => now()->subMinutes(16),
        ]);

        // Warning message sent 6 minutes ago (> 5 min diff)
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::OUTBOUND,
            'sender_type' => MessageSenderType::BOT,
            'message_type' => MessageType::TEXT,
            'content' => 'Hola Carlos Morales, ¿sigues por aquí?',
            'metadata' => ['is_inactivity_warning' => true],
            'sent_at' => now()->subMinutes(6),
        ]);

        $this->artisan('conversations:check-inactivity')
            ->assertSuccessful();

        $conversation->refresh();
        $execution->refresh();

        // 1. Conversation status is closed with closed_at and closed_reason, and assignment is released immediately
        $this->assertSame(ConversationStatus::CLOSED, $conversation->status);
        $this->assertNotNull($conversation->closed_at);
        $this->assertNull($conversation->assigned_user_id);
        $this->assertSame('inactivity_timeout', $conversation->metadata['closed_reason'] ?? null);
        $this->assertNotNull($assignment->fresh()->unassigned_at);
        $this->assertSame($this->agent->id, $conversation->closing_assigned_user_id);

        // 2. Active execution cancelled
        $this->assertSame(FlowExecutionStatus::CANCELLED, $execution->status);
        $this->assertNotNull($execution->completed_at);

        // 3. Goodbye message dispatched
        $closeMessage = Message::where('conversation_id', $conversation->id)
            ->where('metadata->is_inactivity_close', true)
            ->first();
        $this->assertNotNull($closeMessage);
        $this->assertStringContainsString('Carlos Morales', $closeMessage->content);

        // 4. WebSocket events dispatched
        Event::assertDispatched(MessageCreated::class);
        Event::assertDispatched(ConversationUpdated::class, function ($event) use ($conversation) {
            return $event->conversation->id === $conversation->id;
        });
        Event::assertDispatched(ConversationClosed::class);
    }

    public function test_if_last_message_was_inbound_never_warns_nor_closes(): void
    {
        Event::fake([MessageCreated::class, ConversationUpdated::class, ConversationClosed::class]);

        $conversation = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'metadata' => [],
            'started_at' => now()->subHours(2),
            'last_message_at' => now()->subHours(1),
        ]);

        // Outbound sent 2 hours ago
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::OUTBOUND,
            'sender_type' => MessageSenderType::BOT,
            'message_type' => MessageType::TEXT,
            'content' => 'Un momento por favor...',
            'sent_at' => now()->subHours(2),
        ]);

        // Last message is INBOUND sent 1 hour ago (waiting for agent/bot to respond)
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::INBOUND,
            'sender_type' => MessageSenderType::CONTACT,
            'message_type' => MessageType::TEXT,
            'content' => '¿Hola? Sigo esperando que me atiendan',
            'sent_at' => now()->subHours(1),
        ]);

        $this->artisan('conversations:check-inactivity')
            ->assertSuccessful();

        $conversation->refresh();

        // Must remain active (WAITING_AGENT) and have no warning or close
        $this->assertSame(ConversationStatus::WAITING_AGENT, $conversation->status);
        $this->assertNull($conversation->closed_at);
        $this->assertArrayNotHasKey('inactivity_warned_at', $conversation->metadata ?? []);

        Event::assertNotDispatched(MessageCreated::class);
        Event::assertNotDispatched(ConversationClosed::class);
    }

    public function test_inbound_response_after_warning_resets_inactivity_warned_at(): void
    {
        $conversation = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::BOT,
            'metadata' => [
                'inactivity_warned_at' => now()->subMinutes(5)->toIso8601String(),
            ],
            'started_at' => now()->subMinutes(20),
            'last_message_at' => now()->subMinutes(5),
        ]);

        $this->assertNotNull($conversation->metadata['inactivity_warned_at']);

        // Contact responds via ingress service
        $ingressService = app(ConversationIngressService::class);
        $inbound = new InboundMessage(
            channel: $this->webchatChannel,
            content: '¡Aquí estoy, perdón la demora!',
            contactExternalIdentifier: 'visitor_123',
            contactDisplayName: 'Carlos Morales'
        );

        $result = $ingressService->handleInbound($inbound);

        $this->assertFalse($result['is_duplicate']);

        $conversation->refresh();
        $this->assertNull($conversation->metadata['inactivity_warned_at']);
    }

    public function test_disabled_inactivity_on_channel_is_respected(): void
    {
        $disabledChannel = Channel::create([
            'name' => 'Disabled Inactivity Channel',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
            'configuration' => [
                'inactivity_enabled' => false,
                'inactivity_warning_minutes' => 5,
                'inactivity_close_minutes' => 10,
            ],
        ]);

        $conversation = Conversation::create([
            'channel_id' => $disabledChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::BOT,
            'metadata' => [],
            'started_at' => now()->subMinutes(60),
            'last_message_at' => now()->subMinutes(50),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::OUTBOUND,
            'sender_type' => MessageSenderType::BOT,
            'message_type' => MessageType::TEXT,
            'content' => 'Mensaje inicial',
            'sent_at' => now()->subMinutes(50),
        ]);

        $this->artisan('conversations:check-inactivity')
            ->assertSuccessful();

        $conversation->refresh();
        $this->assertSame(ConversationStatus::BOT, $conversation->status);
        $this->assertNull($conversation->closed_at);
        $this->assertArrayNotHasKey('inactivity_warned_at', $conversation->metadata ?? []);
    }

    public function test_channel_api_validates_and_stores_inactivity_configuration(): void
    {
        $admin = User::create([
            'name' => 'Admin Test',
            'email' => 'admin_test@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::where('name', 'super_admin')->first());
        Sanctum::actingAs($admin);

        // 1. Create channel with inactivity config
        $response = $this->postJson('/api/v1/channels', [
            'name' => 'Canal con Inactividad',
            'type' => 'webchat',
            'configuration' => [
                'inactivity_enabled' => true,
                'inactivity_warning_enabled' => false,
                'inactivity_warning_minutes' => 8,
                'inactivity_warning_message' => 'Advertencia personalizada para {{nombre}}',
                'inactivity_close_minutes' => 18,
                'inactivity_close_message' => 'Cierre definitivo personalizado',
            ],
            'webchat_settings' => [
                'display_name' => 'Widget Test',
                'allowed_origins' => ['http://localhost:3000'],
            ],
        ]);

        $response->assertStatus(201);
        $channelId = $response->json('data.id');

        $this->assertDatabaseHas('channels', [
            'id' => $channelId,
            'name' => 'Canal con Inactividad',
        ]);

        $channel = Channel::find($channelId);
        $this->assertTrue($channel->configuration['inactivity_enabled']);
        $this->assertFalse($channel->configuration['inactivity_warning_enabled']);
        $this->assertSame(8, $channel->configuration['inactivity_warning_minutes']);
        $this->assertSame(18, $channel->configuration['inactivity_close_minutes']);

        // 2. Update channel inactivity config
        $updateResponse = $this->putJson("/api/v1/channels/{$channelId}", [
            'configuration' => [
                'inactivity_enabled' => false,
                'inactivity_warning_enabled' => true,
                'inactivity_warning_minutes' => 12,
            ],
        ]);

        $updateResponse->assertStatus(200);
        $channel->refresh();
        $this->assertFalse($channel->configuration['inactivity_enabled']);
        $this->assertTrue($channel->configuration['inactivity_warning_enabled']);
        $this->assertSame(12, $channel->configuration['inactivity_warning_minutes']);
    }

    public function test_inactivity_warning_disabled_skips_warning_and_closes_directly_on_close_threshold(): void
    {
        Event::fake([MessageCreated::class, ConversationUpdated::class, ConversationClosed::class]);

        $noWarningChannel = Channel::create([
            'name' => 'No Warning Channel',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
            'configuration' => [
                'inactivity_enabled' => true,
                'inactivity_warning_enabled' => false,
                'inactivity_warning_minutes' => 10,
                'inactivity_close_minutes' => 15,
                'inactivity_close_message' => 'Cierre directo sin aviso previo para {{nombre}}.',
            ],
        ]);

        $conversation = Conversation::create([
            'channel_id' => $noWarningChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::BOT,
            'metadata' => [],
            'started_at' => now()->subMinutes(20),
            'last_message_at' => now()->subMinutes(11),
        ]);

        $outbound = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::OUTBOUND,
            'sender_type' => MessageSenderType::BOT,
            'message_type' => MessageType::TEXT,
            'content' => '¿Necesitas algo más?',
            'sent_at' => now()->subMinutes(11),
        ]);

        // 1. At 11 minutes (past warning threshold 10, but under close threshold 15):
        // Since warning is disabled, it should NOT send any warning message or set warned_at
        $this->artisan('conversations:check-inactivity')
            ->assertSuccessful();

        $conversation->refresh();
        $this->assertSame(ConversationStatus::BOT, $conversation->status);
        $this->assertNull($conversation->closed_at);
        $this->assertArrayNotHasKey('inactivity_warned_at', $conversation->metadata ?? []);

        $warningCount = Message::where('conversation_id', $conversation->id)
            ->where('metadata->is_inactivity_warning', true)
            ->count();
        $this->assertSame(0, $warningCount);
        Event::assertNotDispatched(MessageCreated::class);

        // 2. Advance time past close threshold (16 minutes ago):
        $outbound->update(['sent_at' => now()->subMinutes(16)]);

        $this->artisan('conversations:check-inactivity')
            ->assertSuccessful();

        $conversation->refresh();

        // Must be closed directly and dispatch goodbye message
        $this->assertSame(ConversationStatus::CLOSED, $conversation->status);
        $this->assertNotNull($conversation->closed_at);
        $this->assertSame('inactivity_timeout', $conversation->metadata['closed_reason'] ?? null);

        $closeMessage = Message::where('conversation_id', $conversation->id)
            ->where('metadata->is_inactivity_close', true)
            ->first();

        $this->assertNotNull($closeMessage);
        $this->assertSame(MessageDirection::OUTBOUND, $closeMessage->direction);
        $this->assertStringContainsString('Carlos Morales', $closeMessage->content);

        Event::assertDispatched(MessageCreated::class);
        Event::assertDispatched(ConversationUpdated::class);
        Event::assertDispatched(ConversationClosed::class);
    }
}
