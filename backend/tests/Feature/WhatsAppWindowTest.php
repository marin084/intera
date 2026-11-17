<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\FlowNodeType;
use App\Events\ConversationClosed;
use App\Events\ConversationUpdated;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Services\ConversationLifecycleService;
use App\Services\Presence\AgentAvailabilityService;
use App\Services\Runtime\Channels\WhatsAppOutboxService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WhatsAppWindowTest extends TestCase
{
    use RefreshDatabase;

    protected User $agent;

    protected User $supervisor;

    protected Channel $whatsappChannel;

    protected Channel $webchatChannel;

    protected Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $agentRole = Role::where('name', 'agent')->first();
        $this->agent = User::create([
            'name' => 'Agente WhatsApp',
            'email' => 'agente_wa@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
        ]);
        $this->agent->roles()->attach($agentRole);

        $supervisorRole = Role::where('name', 'supervisor')->first();
        $this->supervisor = User::create([
            'name' => 'Supervisor WhatsApp',
            'email' => 'supervisor_global@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
        ]);
        $this->supervisor->roles()->attach($supervisorRole);

        $this->whatsappChannel = Channel::create([
            'name' => 'WhatsApp Oficial',
            'type' => ChannelType::WHATSAPP,
            'is_active' => true,
            'configuration' => [
                'phone_number_id' => '1234567890',
                'business_account_id' => '9988776655',
                'access_token' => 'wa-test-token',
                'api_version' => 'v21.0',
            ],
        ]);

        $this->webchatChannel = Channel::create([
            'name' => 'Webchat Soporte',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
            'configuration' => [],
        ]);
        $this->grantChannelAccess($this->whatsappChannel, $this->agent, $this->supervisor);
        $this->grantChannelAccess($this->webchatChannel, $this->agent, $this->supervisor);
        $this->addToDefaultTeam($this->agent, $this->supervisor);

        $this->contact = Contact::create([
            'display_name' => 'Marcos Lima',
            'phone' => '+5215511223344',
            'external_identifier' => '+5215511223344',
        ]);
    }

    public function test_message_within_24h_is_dispatched_normally(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.123']]], 200),
        ]);

        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agent->id,
            'started_at' => now()->subHours(5),
        ]);

        // Inbound message 2 hours ago
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $this->contact->id,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Hola, tengo una pregunta',
            'sent_at' => now()->subHours(2),
        ]);

        Sanctum::actingAs($this->agent);

        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Hola Marcos, claro que sí, ¿en qué te ayudo?',
            'visibility' => 'public',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.content', 'Hola Marcos, claro que sí, ¿en qué te ayudo?');

        Http::assertSent(function (ClientRequest $request) {
            $data = $request->data();

            return str_contains($request->url(), '1234567890/messages')
                && $data['messaging_product'] === 'whatsapp'
                && $data['to'] === '5215511223344'
                && $data['text']['body'] === 'Hola Marcos, claro que sí, ¿en qué te ayudo?';
        });
    }

    public function test_message_after_24h_returns_422_with_code_whatsapp_24h_window_expired(): void
    {
        Http::fake();

        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agent->id,
            'started_at' => now()->subHours(30),
        ]);

        // Inbound message 25 hours ago (expired window)
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $this->contact->id,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Mensaje de ayer',
            'sent_at' => now()->subHours(25),
        ]);

        Sanctum::actingAs($this->agent);

        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Hola Marcos, ¿sigues ahí?',
            'visibility' => 'public',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'La ventana de atención de 24 horas de WhatsApp ha expirado. Meta solo permite reabrir la conversación mediante una plantilla preaprobada.',
                'error_code' => 'WHATSAPP_24H_WINDOW_EXPIRED',
            ]);

        Http::assertNothingSent();
    }

    public function test_internal_notes_are_allowed_even_when_24h_window_is_expired(): void
    {
        Http::fake();

        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agent->id,
            'started_at' => now()->subHours(30),
        ]);

        // Inbound message 30 hours ago
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $this->contact->id,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Mensaje viejo',
            'sent_at' => now()->subHours(30),
        ]);

        Sanctum::actingAs($this->agent);

        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Nota interna: Se llamará al cliente por teléfono debido a expiración de 24h',
            'visibility' => 'internal',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.visibility', 'internal');

        Http::assertNothingSent();
    }

    public function test_webchat_channels_are_never_restricted_by_24h_window(): void
    {
        $conversation = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agent->id,
            'started_at' => now()->subDays(5),
        ]);

        // Inbound message 5 days ago
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $this->contact->id,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Mensaje webchat de hace días',
            'sent_at' => now()->subDays(5),
        ]);

        Sanctum::actingAs($this->agent);

        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Respuesta en webchat sin límites',
            'visibility' => 'public',
        ]);

        $response->assertStatus(201);
    }

    public function test_whatsapp_window_is_properly_serialized_in_conversation_endpoints(): void
    {
        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agent->id,
            'started_at' => now()->subHours(3),
        ]);

        $inboundAt = now()->subHours(2);
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $this->contact->id,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Mensaje activo',
            'sent_at' => $inboundAt,
        ]);

        Sanctum::actingAs($this->agent);

        // 1. Check GET show endpoint
        $showResponse = $this->getJson("/api/v1/conversations/{$conversation->id}");
        $showResponse->assertOk()
            ->assertJsonPath('data.whatsapp_window.is_applicable', true)
            ->assertJsonPath('data.whatsapp_window.is_open', true)
            ->assertJsonPath('whatsapp_window.is_applicable', true)
            ->assertJsonPath('whatsapp_window.is_open', true);

        $this->assertNotNull($showResponse->json('data.whatsapp_window.last_inbound_at'));
        $this->assertNotNull($showResponse->json('data.whatsapp_window.expires_at'));
        $this->assertGreaterThan(70000, $showResponse->json('data.whatsapp_window.remaining_seconds'));

        // 2. Check GET index endpoint
        $indexResponse = $this->getJson('/api/v1/conversations');
        $indexResponse->assertOk();

        $item = collect($indexResponse->json('data'))->firstWhere('id', $conversation->id);
        $this->assertNotNull($item);
        $this->assertTrue($item['whatsapp_window']['is_applicable']);
        $this->assertTrue($item['whatsapp_window']['is_open']);
        $this->assertGreaterThan(70000, $item['whatsapp_window']['remaining_seconds']);
    }

    public function test_conversation_updated_event_broadcasts_whatsapp_window(): void
    {
        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agent->id,
            'started_at' => now()->subHours(2),
        ]);

        $inbound = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $this->contact->id,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Mensaje para verificar evento',
            'sent_at' => now()->subHours(1),
        ]);

        $event = new ConversationUpdated($conversation);
        $payload = $event->broadcastWith();

        $this->assertArrayHasKey('whatsapp_window', $payload);
        $this->assertTrue($payload['whatsapp_window']['is_applicable']);
        $this->assertTrue($payload['whatsapp_window']['is_open']);
        $this->assertNotNull($payload['whatsapp_window']['last_inbound_at']);
        $this->assertNotNull($payload['whatsapp_window']['expires_at']);
        $this->assertGreaterThan(70000, $payload['whatsapp_window']['remaining_seconds']);
    }

    public function test_closed_conversation_allows_authorized_send_when_meta_window_is_open_and_reopens_upon_wamid(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.HBgLReopen123']],
            ], 200),
        ]);

        $closedAt = now()->subMinutes(15);
        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::CLOSED,
            'assigned_user_id' => $this->agent->id,
            'started_at' => now()->subHours(5),
            'closed_at' => $closedAt,
        ]);

        // Inbound message 2 hours ago (within 24h window)
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $this->contact->id,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Hola, ya terminó el bot pero quiero preguntar algo',
            'sent_at' => now()->subHours(2),
        ]);

        Sanctum::actingAs($this->agent);

        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Hola! Te respondo con gusto.',
            'visibility' => 'public',
        ]);

        $response->assertStatus(201);

        // Verify conversation was reopened cleanly upon receiving WAMID from Meta (channel without flow falls back to waiting_agent)
        $fresh = $conversation->fresh();
        $this->assertEquals(ConversationStatus::WAITING_AGENT, $fresh->status);
        $this->assertNull($fresh->closed_at);
        $this->assertNull($fresh->assigned_user_id);
    }

    public function test_closed_conversation_blocks_send_when_whatsapp_window_is_expired(): void
    {
        Http::fake();

        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::CLOSED,
            'assigned_user_id' => $this->agent->id,
            'started_at' => now()->subHours(40),
            'closed_at' => now()->subHours(25),
        ]);

        // Inbound message 25 hours ago (window expired)
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $this->contact->id,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Mensaje viejo',
            'sent_at' => now()->subHours(25),
        ]);

        Sanctum::actingAs($this->agent);

        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Intento enviar en conversación cerrada fuera de ventana',
            'visibility' => 'public',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'La ventana de atención de 24 horas de WhatsApp ha expirado. Meta solo permite reabrir la conversación mediante una plantilla preaprobada.',
                'error_code' => 'WHATSAPP_24H_WINDOW_EXPIRED',
            ]);

        Http::assertNothingSent();
        $this->assertEquals(ConversationStatus::CLOSED, $conversation->fresh()->status);
    }

    public function test_closed_conversation_does_not_reopen_if_meta_fails_dispatch(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Message failed to send due to Meta API error',
                    'code' => 131047,
                ],
            ], 400),
        ]);

        $closedAt = now()->subMinutes(30);
        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::CLOSED,
            'assigned_user_id' => $this->agent->id,
            'started_at' => now()->subHours(5),
            'closed_at' => $closedAt,
        ]);

        // Inbound message 2 hours ago (window active)
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $this->contact->id,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Mensaje dentro de ventana',
            'sent_at' => now()->subHours(2),
        ]);

        Sanctum::actingAs($this->agent);

        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Mensaje que fallará en Meta',
            'visibility' => 'public',
        ]);

        $response->assertStatus(201);
        $messageId = $response->json('data.id');

        $message = Message::find($messageId);
        $this->assertEquals(MessageStatus::FAILED, $message->status);

        // The conversation must REMAIN closed!
        $fresh = $conversation->fresh();
        $this->assertEquals(ConversationStatus::CLOSED, $fresh->status);
        $this->assertNotNull($fresh->closed_at);
        $this->assertEquals($closedAt->toIso8601String(), $fresh->closed_at->toIso8601String());
    }

    public function test_accepted_bot_message_does_not_reopen_a_closed_conversation(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.BotMessageMustNotReopen']],
            ], 200),
        ]);

        $closedAt = now()->subMinutes(10);
        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::CLOSED,
            'assigned_user_id' => $this->agent->id,
            'started_at' => now()->subHours(3),
            'closed_at' => $closedAt,
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::BOT,
            'sender_id' => 'bot',
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'visibility' => 'public',
            'status' => MessageStatus::PENDING,
            'content' => 'Respuesta automática del flujo',
            'sent_at' => now(),
        ]);

        app(WhatsAppOutboxService::class)->dispatchSingleMessage($message, $this->whatsappChannel);

        $this->assertEquals(MessageStatus::SENT, $message->fresh()->status);
        $this->assertEquals(ConversationStatus::CLOSED, $conversation->fresh()->status);
        $this->assertEquals($closedAt->toIso8601String(), $conversation->fresh()->closed_at->toIso8601String());
    }

    public function test_post_wamid_acceptance_error_does_not_mark_message_as_failed(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.HBgLAcceptedWithPostReopenError']],
            ], 200),
        ]);

        $closedAt = now()->subMinutes(10);
        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::CLOSED,
            'assigned_user_id' => $this->agent->id,
            'started_at' => now()->subHours(2),
            'closed_at' => $closedAt,
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'sender_id' => (string) $this->agent->id,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'visibility' => 'public',
            'status' => MessageStatus::PENDING,
            'content' => 'Mensaje aceptado por Meta con fallo posterior',
            'sent_at' => now(),
        ]);

        // Force an exception during ConversationUpdated broadcast
        Event::listen(ConversationUpdated::class, function () {
            throw new \RuntimeException('Simulated post-acceptance broadcast failure');
        });

        // Dispatch via WhatsAppOutboxService
        app(WhatsAppOutboxService::class)->dispatchSingleMessage($message, $this->whatsappChannel);

        // Crucial verification: The message must remain SENT with its WAMID and NOT marked as FAILED
        $freshMessage = $message->fresh();
        $this->assertEquals(MessageStatus::SENT, $freshMessage->status);
        $this->assertEquals('wamid.HBgLAcceptedWithPostReopenError', $freshMessage->external_message_id);
        $this->assertEquals('sent', $freshMessage->metadata['delivery_status']);
    }

    public function test_closed_conversation_queued_message_reopens_only_when_wamid_received(): void
    {
        $closedAt = now()->subMinutes(20);
        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::CLOSED,
            'assigned_user_id' => $this->agent->id,
            'started_at' => now()->subHours(3),
            'closed_at' => $closedAt,
        ]);

        // Inbound message 1 hour ago
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $this->contact->id,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Inbound activo',
            'sent_at' => now()->subHour(),
        ]);

        // Simulate an in-flight message that is currently blocking the conversation outbox
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'sender_id' => (string) $this->agent->id,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'status' => MessageStatus::SENT,
            'content' => 'Mensaje en vuelo',
            'metadata' => [
                'delivery_status' => 'sent',
                'wamid' => 'wamid.inflight_999',
                'sent_at' => now()->subSeconds(5)->toIso8601String(),
            ],
            'sent_at' => now()->subSeconds(5),
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.HBgLQueuedReopened']],
            ], 200),
        ]);

        Sanctum::actingAs($this->agent);

        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Mensaje encolado en closed conversation',
            'visibility' => 'public',
        ]);

        $response->assertStatus(201);
        $newMsgId = $response->json('data.id');

        $newMsg = Message::find($newMsgId);
        // While blocked by in-flight message, it must stay PENDING and NOT dispatched yet
        $this->assertEquals(MessageStatus::PENDING, $newMsg->status);

        // And conversation must STILL be closed!
        $this->assertEquals(ConversationStatus::CLOSED, $conversation->fresh()->status);

        // Now, trigger outbox processing (simulating that in-flight delivered or queue drained)
        // Set delivery_status of in-flight to delivered
        Message::where('metadata->wamid', 'wamid.inflight_999')->update([
            'status' => MessageStatus::DELIVERED,
            'metadata' => ['delivery_status' => 'delivered', 'wamid' => 'wamid.inflight_999'],
        ]);

        $outboxService = app(WhatsAppOutboxService::class);
        $outboxService->processNextOutbound($conversation->id);

        // Now the message has been dispatched and accepted with WAMID, reopening the conversation!
        $fresh = $conversation->fresh();
        $this->assertEquals(ConversationStatus::WAITING_AGENT, $fresh->status);
        $this->assertNull($fresh->closed_at);
        $this->assertNull($fresh->assigned_user_id);
        $this->assertEquals(MessageStatus::SENT, $newMsg->fresh()->status);
    }

    public function test_closed_non_whatsapp_channel_is_blocked_by_closed_status(): void
    {
        $conversation = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::CLOSED,
            'assigned_user_id' => $this->agent->id,
            'started_at' => now()->subHours(2),
            'closed_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($this->agent);

        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Intento enviar en webchat cerrado',
            'visibility' => 'public',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'La conversación está cerrada y no admite nuevos mensajes.',
                'code' => 'conversation_closed',
            ]);
    }

    public function test_closing_conversation_releases_active_assignment_immediately_and_preserves_history(): void
    {
        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agent->id,
            'started_at' => now()->subHours(3),
        ]);

        $assignment = ConversationAssignment::create([
            'conversation_id' => $conversation->id,
            'assigned_user_id' => $this->agent->id,
            'assigned_by_user_id' => $this->agent->id,
            'assigned_at' => now()->subHours(3),
            'reason' => 'Test assignment',
        ]);

        // Close conversation via lifecycle service
        $lifecycleService = app(ConversationLifecycleService::class);
        $closed = $lifecycleService->close($conversation, $this->agent);

        // Active assignment must be freed immediately: assigned_user_id = null
        $this->assertEquals(ConversationStatus::CLOSED, $closed->status);
        $this->assertNull($closed->assigned_user_id);
        $this->assertNotNull($closed->closed_at);

        // Assignment record must have unassigned_at set, preserving history
        $assignment->refresh();
        $this->assertNotNull($assignment->unassigned_at);
        $this->assertDatabaseCount('conversation_assignments', 1);

        // Closing assigned user must be properly recognized via closing_assigned_user_id
        $this->assertEquals($this->agent->id, $closed->closing_assigned_user_id);
    }

    public function test_closing_idempotency_sanitizes_lingering_assignment_without_duplicating_events(): void
    {
        // Conversation is already marked CLOSED, but somehow retained assigned_user_id and unclosed assignment
        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::CLOSED,
            'assigned_user_id' => $this->agent->id,
            'started_at' => now()->subHours(3),
            'closed_at' => now()->subMinutes(10),
        ]);

        $assignment = ConversationAssignment::create([
            'conversation_id' => $conversation->id,
            'assigned_user_id' => $this->agent->id,
            'assigned_by_user_id' => $this->agent->id,
            'assigned_at' => now()->subHours(3),
            'reason' => 'Test unclosed assignment',
        ]);

        Event::fake([ConversationClosed::class]);

        $lifecycleService = app(ConversationLifecycleService::class);
        $result = $lifecycleService->close($conversation, $this->agent);

        // Verify lingering assigned_user_id was sanitized to null
        $this->assertNull($result->assigned_user_id);
        $this->assertEquals(ConversationStatus::CLOSED, $result->status);

        // Verify active assignment unassigned_at was closed
        $this->assertNotNull($assignment->fresh()->unassigned_at);

        // Verify closing owner was captured prior to sanitizing assigned_user_id
        $this->assertEquals($this->agent->id, $result->closing_assigned_user_id);
        $this->assertTrue($result->metadata['closing_assignment_captured'] ?? false);
        $this->assertEquals($this->agent->id, $result->metadata['last_assigned_user_id'] ?? null);

        // Events must not be duplicated for already closed conversation
        Event::assertNotDispatched(ConversationClosed::class);
    }

    public function test_only_closing_agent_and_supervisors_can_reply_to_closed_whatsapp_within_window_earlier_agent_gets_403(): void
    {
        $agentRole = Role::where('name', 'agent')->first();
        $earlierAgent = User::create([
            'name' => 'Agente Anterior',
            'email' => 'earlier_agent@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
        ]);
        $earlierAgent->roles()->attach($agentRole);

        $supervisorRole = Role::where('name', 'supervisor')->first();
        $supervisor = User::create([
            'name' => 'Supervisor Atencion',
            'email' => 'supervisor_wa@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
        ]);
        $supervisor->roles()->attach($supervisorRole);
        $this->grantChannelAccess($this->whatsappChannel, $earlierAgent, $supervisor);
        $this->addToDefaultTeam($earlierAgent, $supervisor);

        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $earlierAgent->id,
            'started_at' => now()->subHours(4),
        ]);

        // Historical assignment for earlier agent
        ConversationAssignment::create([
            'conversation_id' => $conversation->id,
            'assigned_user_id' => $earlierAgent->id,
            'assigned_by_user_id' => $earlierAgent->id,
            'assigned_at' => now()->subHours(4),
            'unassigned_at' => now()->subHours(2),
            'reason' => 'Initial assignment',
        ]);

        // Later reassigned to $this->agent
        $conversation->update(['assigned_user_id' => $this->agent->id]);
        ConversationAssignment::create([
            'conversation_id' => $conversation->id,
            'assigned_user_id' => $this->agent->id,
            'assigned_by_user_id' => $supervisor->id,
            'assigned_at' => now()->subHours(2),
            'reason' => 'Reassigned to current agent',
        ]);

        // Now close the conversation
        app(ConversationLifecycleService::class)->close($conversation, $this->agent);
        $conversation->refresh();

        $this->assertEquals(ConversationStatus::CLOSED, $conversation->status);
        $this->assertNull($conversation->assigned_user_id);
        $this->assertEquals($this->agent->id, $conversation->closing_assigned_user_id);

        // Add inbound message 1 hour ago (within 24h window)
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $this->contact->id,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Pregunta dentro de ventana',
            'sent_at' => now()->subHour(),
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.HBgLClosingOwnerTest']]], 200),
        ]);

        // Test 1: Earlier agent attempts to reply -> 403 Forbidden!
        Sanctum::actingAs($earlierAgent);
        $earlierResponse = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Intento de agente anterior',
            'visibility' => 'public',
        ]);
        $earlierResponse->assertStatus(403);

        // Test 2: Closing agent attempts to reply -> 201 Created!
        Sanctum::actingAs($this->agent);
        $closingAgentResponse = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Respuesta del agente de cierre',
            'visibility' => 'public',
        ]);
        $closingAgentResponse->assertStatus(201);

        // Test 3: Supervisor attempts to reply -> 201 Created!
        Sanctum::actingAs($supervisor);
        $supervisorResponse = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Respuesta de supervisor',
            'visibility' => 'public',
        ]);
        $supervisorResponse->assertStatus(201);
    }

    public function test_reopening_resets_closing_owner_metadata_so_subsequent_lifecycle_does_not_leak_stale_owner(): void
    {
        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agent->id,
            'started_at' => now()->subHours(2),
        ]);

        ConversationAssignment::create([
            'conversation_id' => $conversation->id,
            'assigned_user_id' => $this->agent->id,
            'assigned_by_user_id' => $this->agent->id,
            'assigned_at' => now()->subHours(2),
            'reason' => 'First lifecycle',
        ]);

        // Close conversation: agent is closing owner
        app(ConversationLifecycleService::class)->close($conversation, $this->agent);
        $conversation->refresh();
        $this->assertEquals($this->agent->id, $conversation->closing_assigned_user_id);
        $this->assertEquals($this->agent->id, $conversation->metadata['last_assigned_user_id']);

        // Inbound message inside window
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $this->contact->id,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Inbound para reabrir',
            'sent_at' => now()->subMinutes(30),
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.HBgLReopenedLifecycle']]], 200),
        ]);

        // Agent reopens conversation by sending message
        Sanctum::actingAs($this->agent);
        $res = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Reabriendo conversacion',
            'visibility' => 'public',
        ]);
        $res->assertStatus(201);

        // Reopening must clear last_assigned_user_id from metadata
        $conversation->refresh();
        $this->assertNull($conversation->closed_at);
        $this->assertArrayNotHasKey('last_assigned_user_id', $conversation->metadata ?? []);
    }

    public function test_reopen_restarts_entry_flow_and_auto_assigns_fairly_without_preferring_previous_agent(): void
    {
        AgentAvailabilityService::resetFake();
        $agentRole = Role::where('name', 'agent')->first();

        // Agent A (previous agent) is online with 1 active assigned conversation
        $this->agent->update(['last_seen_at' => now()]);
        Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agent->id,
            'started_at' => now()->subHours(1),
        ]);

        // Agent B (another eligible agent) is online with 0 active conversations (less busy)
        $agentB = User::create([
            'name' => 'Agent Bravo',
            'email' => 'agent_bravo@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $agentB->roles()->attach($agentRole);
        $this->grantChannelAccess($this->whatsappChannel, $agentB);
        $this->addToDefaultTeam($agentB);

        // Set up Flow with START -> TRANSFER_TO_AGENT
        $flow = Flow::create([
            'channel_id' => $this->whatsappChannel->id,
            'name' => 'Flow Reopen Entry',
            'status' => 'published',
            'is_active' => true,
        ]);

        $flowVersion = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => 'published',
            'definition' => [],
        ]);

        $startNode = FlowNode::create([
            'flow_version_id' => $flowVersion->id,
            'name' => 'Start',
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'configuration' => [],
        ]);

        $transferNode = FlowNode::create([
            'flow_version_id' => $flowVersion->id,
            'name' => 'Transfer',
            'node_key' => 'transfer_1',
            'type' => FlowNodeType::TRANSFER_TO_AGENT,
            'configuration' => [
                'message' => 'Te transferimos con un asesor.',
            ],
        ]);

        \App\Models\FlowEdge::create([
            'flow_version_id' => $flowVersion->id,
            'source_node_key' => 'start_1',
            'target_node_key' => 'transfer_1',
            'condition_handle' => 'default',
        ]);

        // Channel configuration has entry flow
        $flow->update(['active_version_id' => $flowVersion->id]);

        $this->whatsappChannel->update([
            'entry_flow_id' => $flow->id,
            'configuration' => array_merge($this->whatsappChannel->configuration, [
                'entry_flow_id' => $flow->id,
            ]),
        ]);

        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::CLOSED,
            'assigned_user_id' => null,
            'started_at' => now()->subHours(2),
            'closed_at' => now()->subMinutes(30),
            'metadata' => [
                'last_assigned_user_id' => $this->agent->id,
            ],
        ]);

        // Inbound message inside 24h window
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $this->contact->id,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Quiero hablar con soporte',
            'sent_at' => now()->subMinutes(15),
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.HBgLFlowReopenedAndTransferred']]], 200),
        ]);

        Sanctum::actingAs($this->agent);
        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Reanudamos tu caso',
            'visibility' => 'public',
        ]);
        $response->assertStatus(201);

        $fresh = $conversation->fresh();
        $this->assertEquals(ConversationStatus::ASSIGNED, $fresh->status);
        $this->assertNull($fresh->closed_at);

        // Uniform random draw among all eligible agents with capacity:
        // both Agent A (load 1) and Agent B (load 0) are in the eligible pool,
        // and neither has preference.
        $candidates = app(\App\Services\Routing\ConversationAssignmentService::class)->getEligibleCandidates($fresh);
        $candidateIds = $candidates->pluck('id')->all();
        $this->assertContains($this->agent->id, $candidateIds, 'Agent A (previous agent with capacity) must be in the candidate pool');
        $this->assertContains($agentB->id, $candidateIds, 'Agent B (eligible agent with capacity) must be in the candidate pool');
        $this->assertContains($fresh->assigned_user_id, $candidateIds);
    }

    public function test_reopen_does_not_assign_to_offline_or_full_capacity_agents(): void
    {
        AgentAvailabilityService::resetFake();
        $agentRole = Role::where('name', 'agent')->first();
        $agentRole->update(['max_concurrent_conversations' => 3]);

        // Agent A (previous agent) is online, but SATURATED at 3/3 active conversations
        $this->agent->update(['last_seen_at' => now()]);
        for ($i = 0; $i < 3; $i++) {
            Conversation::create([
                'channel_id' => $this->whatsappChannel->id,
                'contact_id' => $this->contact->id,
                'status' => ConversationStatus::ASSIGNED,
                'assigned_user_id' => $this->agent->id,
                'started_at' => now()->subHours(1),
            ]);
        }

        // Agent B is offline
        $agentB = User::create([
            'name' => 'Agent Offline',
            'email' => 'agent_offline@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
            'last_seen_at' => now()->subHours(2),
        ]);
        $agentB->roles()->attach($agentRole);

        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::CLOSED,
            'assigned_user_id' => null,
            'started_at' => now()->subHours(3),
            'closed_at' => now()->subMinutes(20),
            'metadata' => [
                'last_assigned_user_id' => $this->agent->id,
            ],
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $this->contact->id,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Hola',
            'sent_at' => now()->subMinutes(10),
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.HBgLNoEligibleAgentsReopened']]], 200),
        ]);

        Sanctum::actingAs($this->agent);
        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Respondiendo mientras saturado',
            'visibility' => 'public',
        ]);
        $response->assertStatus(201);

        $fresh = $conversation->fresh();
        // Saturated Agent A (at 3/3 capacity) and offline Agent B must NOT receive assignment
        // The conversation must remain WAITING_AGENT with null assigned_user_id!
        $this->assertEquals(ConversationStatus::WAITING_AGENT, $fresh->status);
        $this->assertNull($fresh->assigned_user_id);
        $this->assertNull($fresh->closed_at);
    }

    public function test_inbound_whatsapp_reopens_closed_conversation_and_idempotency_prevents_duplicate_execution(): void
    {
        $flow = Flow::create([
            'channel_id' => $this->whatsappChannel->id,
            'name' => 'Inbound Entry Flow',
            'status' => 'published',
            'is_active' => true,
        ]);

        $flowVersion = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => 'published',
            'definition' => [],
        ]);

        $startNode = FlowNode::create([
            'flow_version_id' => $flowVersion->id,
            'name' => 'Start',
            'node_key' => 'start_inbound',
            'type' => FlowNodeType::START,
            'configuration' => [],
        ]);

        $textNode = FlowNode::create([
            'flow_version_id' => $flowVersion->id,
            'name' => 'Greeting',
            'node_key' => 'text_greeting',
            'type' => FlowNodeType::MESSAGE,
            'configuration' => [
                'text' => 'Hola, bienvenido de vuelta a soporte WhatsApp.',
            ],
        ]);

        \App\Models\FlowEdge::create([
            'flow_version_id' => $flowVersion->id,
            'source_node_key' => 'start_inbound',
            'target_node_key' => 'text_greeting',
            'condition_handle' => 'default',
        ]);

        $flow->update(['active_version_id' => $flowVersion->id]);

        $this->whatsappChannel->update([
            'entry_flow_id' => $flow->id,
            'configuration' => array_merge($this->whatsappChannel->configuration, [
                'entry_flow_id' => $flow->id,
            ]),
        ]);

        // Previous conversation for contact on this channel is CLOSED
        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::CLOSED,
            'assigned_user_id' => null,
            'started_at' => now()->subDays(2),
            'closed_at' => now()->subDay(),
            'metadata' => [
                'closing_assignment_captured' => true,
                'last_assigned_user_id' => $this->agent->id,
            ],
        ]);

        $ingress = app(\App\Services\Runtime\ConversationIngressService::class);

        // First inbound delivery with unique WAMID
        $wamid = 'wamid.HBgLInboundReopen12345';
        $result1 = $ingress->handleInboundMessage(
            channel: $this->whatsappChannel,
            contactPayload: [
                'external_identifier' => $this->contact->external_identifier,
                'phone' => $this->contact->phone,
                'display_name' => $this->contact->display_name,
            ],
            content: 'Hola, necesito ayuda nuevamente',
            externalMessageId: $wamid
        );

        $this->assertFalse($result1['is_duplicate']);
        $reopenedConv = $result1['conversation']->fresh();

        // 1. Same conversation reused across reopen
        $this->assertEquals($conversation->id, $reopenedConv->id);

        // 2. Reopened state: status is not closed, closed_at is null, assigned_user_id is null initially
        $this->assertNotEquals(ConversationStatus::CLOSED, $reopenedConv->status);
        $this->assertNull($reopenedConv->closed_at);
        $this->assertNull($reopenedConv->assigned_user_id);

        // 3. Stale closing owner removed
        $this->assertArrayNotHasKey('last_assigned_user_id', $reopenedConv->metadata ?? []);
        $this->assertArrayNotHasKey('closing_assignment_captured', $reopenedConv->metadata ?? []);
        $this->assertNull($reopenedConv->closing_assigned_user_id);

        // 4. Entry flow was executed
        $this->assertNotNull($result1['execution']);
        $this->assertEquals(1, \App\Models\FlowExecution::where('conversation_id', $conversation->id)->count());

        // 5. Duplicate delivery of same WAMID from Meta re-attempt
        $result2 = $ingress->handleInboundMessage(
            channel: $this->whatsappChannel,
            contactPayload: [
                'external_identifier' => $this->contact->external_identifier,
                'phone' => $this->contact->phone,
                'display_name' => $this->contact->display_name,
            ],
            content: 'Hola, necesito ayuda nuevamente',
            externalMessageId: $wamid
        );

        // Idempotency prevents duplicate flow execution
        $this->assertTrue($result2['is_duplicate']);
        $this->assertEquals(1, \App\Models\FlowExecution::where('conversation_id', $conversation->id)->count());
    }

    public function test_duplicate_wamid_retry_after_conversation_closed_again_does_not_reopen(): void
    {
        $flow = Flow::create([
            'channel_id' => $this->whatsappChannel->id,
            'name' => 'Inbound Flow For Retry',
            'status' => 'published',
            'is_active' => true,
        ]);

        $flowVersion = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => 'published',
            'definition' => [],
        ]);

        $startNode = FlowNode::create([
            'flow_version_id' => $flowVersion->id,
            'name' => 'Start',
            'node_key' => 'start_retry',
            'type' => FlowNodeType::START,
            'configuration' => [],
        ]);

        $flow->update(['active_version_id' => $flowVersion->id]);
        $this->whatsappChannel->update(['entry_flow_id' => $flow->id]);

        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::CLOSED,
            'assigned_user_id' => null,
            'started_at' => now()->subDays(2),
            'closed_at' => now()->subDay(),
        ]);

        $ingress = app(\App\Services\Runtime\ConversationIngressService::class);
        $wamid = 'wamid.HBgLProcessThenCloseThenRetry';

        // 1. Process message once: reopens conversation
        $res1 = $ingress->handleInboundMessage(
            channel: $this->whatsappChannel,
            contactPayload: [
                'external_identifier' => $this->contact->external_identifier,
                'phone' => $this->contact->phone,
                'display_name' => $this->contact->display_name,
            ],
            content: 'Primer mensaje',
            externalMessageId: $wamid
        );

        $this->assertFalse($res1['is_duplicate']);
        $this->assertEquals(1, \App\Models\FlowExecution::where('conversation_id', $conversation->id)->count());

        // 2. The conversation is closed again
        $closedAt = now();
        $reopenedConv = $res1['conversation']->fresh();
        $reopenedConv->update([
            'status' => ConversationStatus::CLOSED,
            'closed_at' => $closedAt,
            'assigned_user_id' => null,
            'metadata' => [
                'closing_assignment_captured' => true,
                'last_assigned_user_id' => null,
            ],
        ]);

        // 3. Meta retries the same WAMID
        $res2 = $ingress->handleInboundMessage(
            channel: $this->whatsappChannel,
            contactPayload: [
                'external_identifier' => $this->contact->external_identifier,
                'phone' => $this->contact->phone,
                'display_name' => $this->contact->display_name,
            ],
            content: 'Primer mensaje (retry)',
            externalMessageId: $wamid
        );

        // Must be detected as duplicate without reopening or mutating
        $this->assertTrue($res2['is_duplicate']);
        $fresh = $conversation->fresh();
        $this->assertEquals(ConversationStatus::CLOSED, $fresh->status);
        $this->assertNotNull($fresh->closed_at);
        $this->assertNull($fresh->assigned_user_id);
        // Still only 1 execution created from initial arrival
        $this->assertEquals(1, \App\Models\FlowExecution::where('conversation_id', $conversation->id)->count());
    }

    public function test_closing_assignment_captured_regression_when_reopened_and_closed_again_without_agent(): void
    {
        // 1. Cycle 1: Conversation is assigned to Agent A
        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agent->id,
            'started_at' => now()->subHours(5),
        ]);

        ConversationAssignment::create([
            'conversation_id' => $conversation->id,
            'assigned_user_id' => $this->agent->id,
            'assigned_by_user_id' => $this->agent->id,
            'assigned_at' => now()->subHours(5),
            'reason' => 'Cycle 1 assignment',
        ]);

        // Close Cycle 1
        $lifecycle = app(ConversationLifecycleService::class);
        $lifecycle->close($conversation, $this->agent);

        $fresh1 = $conversation->fresh();
        $this->assertEquals(ConversationStatus::CLOSED, $fresh1->status);
        $this->assertEquals($this->agent->id, $fresh1->closing_assigned_user_id);
        $this->assertTrue($fresh1->metadata['closing_assignment_captured']);
        $this->assertEquals($this->agent->id, $fresh1->metadata['last_assigned_user_id']);

        // Agent A has access to reply in cycle 1
        Sanctum::actingAs($this->agent);
        $this->getJson("/api/v1/conversations/{$conversation->id}")->assertStatus(200);

        // 2. Cycle 2: Reopen conversation (e.g. restart flow or webchat/inbound)
        $this->postJson("/api/v1/conversations/{$conversation->id}/restart-flow")->assertStatus(200);

        $fresh2 = $conversation->fresh();
        $this->assertNotEquals(ConversationStatus::CLOSED, $fresh2->status);
        $this->assertNull($fresh2->assigned_user_id);
        // Metadata cleared
        $this->assertArrayNotHasKey('closing_assignment_captured', $fresh2->metadata ?? []);
        $this->assertArrayNotHasKey('last_assigned_user_id', $fresh2->metadata ?? []);

        // 3. Cycle 2 closes WITHOUT any agent assigned (e.g. bot finishes or supervisor closes WAITING_AGENT)
        $conversation->update(['status' => ConversationStatus::WAITING_AGENT]);
        $lifecycle->close($conversation, $this->supervisor);

        $fresh3 = $conversation->fresh();
        $this->assertEquals(ConversationStatus::CLOSED, $fresh3->status);
        // CRITICAL: closing_assignment_captured is true and last_assigned_user_id is null!
        $this->assertTrue($fresh3->metadata['closing_assignment_captured'] ?? false);
        $this->assertNull($fresh3->metadata['last_assigned_user_id'] ?? null);

        // Accessor MUST NOT fall back to historical Cycle 1 assignment:
        $this->assertNull($fresh3->closing_assigned_user_id);

        // Agent A MUST NOT recover access to this conversation!
        Sanctum::actingAs($this->agent);
        $showRes = $this->getJson("/api/v1/conversations/{$conversation->id}");
        $showRes->assertStatus(403);

        $sendRes = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Intento de agente anterior en ciclo sin asignación',
            'visibility' => 'public',
        ]);
        $sendRes->assertStatus(403);

        // Index filter for closed conversations must not list it for Agent A
        $indexRes = $this->getJson('/api/v1/conversations?status=closed');
        $indexRes->assertStatus(200);
        $returnedIds = collect($indexRes->json('data'))->pluck('id')->all();
        $this->assertNotContains($conversation->id, $returnedIds);
    }

    public function test_supervisor_closing_waiting_conversation_does_not_assign_to_supervisor(): void
    {
        // Waiting conversation 1
        $conv1 = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'started_at' => now()->subHours(1),
        ]);

        // Waiting conversation 2
        $contact2 = Contact::create([
            'first_name' => 'Contacto',
            'last_name' => 'Dos',
            'phone' => '+5215599887766',
            'external_identifier' => 'contact_wa_test_2',
        ]);
        $conv2 = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $contact2->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'started_at' => now()->subMinutes(30),
        ]);

        $lifecycle = app(ConversationLifecycleService::class);
        // Supervisor closes conv1
        $lifecycle->close($conv1, $this->supervisor);

        // Neither conv1 nor conv2 must have assigned_user_id set to supervisor!
        $this->assertNull($conv1->fresh()->assigned_user_id);
        $this->assertNull($conv2->fresh()->assigned_user_id);
    }
}
