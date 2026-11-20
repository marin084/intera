<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\FlowNodeType;
use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageType;
use App\Events\ConversationAssigned;
use App\Events\MessageCreated;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Models\WebchatChannelSettings;
use App\Models\WebchatSession;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebchatApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function createStandardPublishedFlow(): FlowVersion
    {
        $flow = Flow::create([
            'name' => 'Flujo Webchat Test',
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
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
            'configuration' => [],
        ]);

        $welcomeMsgNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_welcome',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Bienvenida',
            'configuration' => [
                'text' => 'Hola, ¿en qué podemos ayudarte?',
            ],
        ]);

        $askNameNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'q_name',
            'type' => FlowNodeType::QUESTION,
            'name' => 'Preguntar Nombre',
            'configuration' => [
                'text' => '¿Cuál es tu nombre?',
                'variable' => 'visitor_name',
                'input_type' => 'text',
            ],
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start_1',
            'target_node_key' => 'msg_welcome',
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'msg_welcome',
            'target_node_key' => 'q_name',
        ]);

        $version->update(['status' => FlowVersionStatus::PUBLISHED]);
        $flow->update([
            'status' => FlowStatus::PUBLISHED,
            'active_version_id' => $version->id,
        ]);

        return $version->fresh(['nodes', 'edges']);
    }

    protected function createWebchatChannel(?FlowVersion $version = null, bool $isActive = true, array $allowedOrigins = ['http://localhost:3000']): Channel
    {
        $channel = Channel::create([
            'name' => 'Webchat Soporte',
            'type' => ChannelType::WEBCHAT,
            'is_active' => $isActive,
            'entry_flow_id' => $version?->flow_id,
        ]);

        WebchatChannelSettings::create([
            'channel_id' => $channel->id,
            'public_key' => WebchatChannelSettings::generatePublicKey(),
            'display_name' => 'Soporte Web',
            'allowed_origins' => $allowedOrigins,
            'accent_color' => '#10B981',
            'launcher_position' => 'bottom-right',
        ]);

        return $channel->fresh(['webchatSettings', 'entryFlow.activeVersion']);
    }

    // ==========================================
    // Requirement 60: Tests backend — Configuración
    // ==========================================

    public function test_can_create_channel_with_webchat_settings(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('Secret123456789!'),
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::where('name', 'super_admin')->first());

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/channels', [
            'name' => 'Canal Webchat Nuevo',
            'type' => 'webchat',
            'is_active' => true,
            'webchat_settings' => [
                'display_name' => 'Atención al Cliente',
                'allowed_origins' => ['https://example.com', 'http://localhost:3000'],
                'accent_color' => '#3B82F6',
                'launcher_position' => 'bottom-left',
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.webchat_settings.display_name', 'Atención al Cliente');
        $response->assertJsonPath('data.webchat_settings.launcher_position', 'bottom-left');
        $this->assertNotNull($response->json('data.webchat_settings.public_key'));

        $this->assertDatabaseHas('webchat_channel_settings', [
            'display_name' => 'Atención al Cliente',
            'launcher_position' => 'bottom-left',
        ]);
    }

    public function test_embed_handshake_with_allowed_origin_succeeds(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = $this->createWebchatChannel($version, true, ['https://mywebsite.com']);
        $publicKey = $channel->webchatSettings->public_key;

        $response = $this->postJson("/api/v1/public/webchat/{$publicKey}/embed", [], [
            'Origin' => 'https://mywebsite.com',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'embed_token',
                'channel_id',
                'settings' => ['display_name', 'accent_color', 'launcher_position'],
            ],
        ]);
    }

    public function test_embed_handshake_with_unauthorized_origin_returns_403(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = $this->createWebchatChannel($version, true, ['https://allowed.com']);
        $publicKey = $channel->webchatSettings->public_key;

        $response = $this->postJson("/api/v1/public/webchat/{$publicKey}/embed", [], [
            'Origin' => 'https://malicious-site.com',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Origen no permitido.');
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }

    public function test_cors_preflight_options_for_authorized_origin_returns_exact_acao_and_vary(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = $this->createWebchatChannel($version, true, ['https://mywebsite.com']);
        $publicKey = $channel->webchatSettings->public_key;

        $response = $this->call('OPTIONS', "/api/v1/public/webchat/{$publicKey}/embed", [], [], [], [
            'HTTP_ORIGIN' => 'https://mywebsite.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type, Accept',
        ]);

        $response->assertStatus(204);
        $response->assertHeader('Access-Control-Allow-Origin', 'https://mywebsite.com');
        $response->assertHeader('Vary', 'Origin');
        $this->assertNotEquals('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_cors_actual_post_for_authorized_origin_returns_exact_acao_and_vary(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = $this->createWebchatChannel($version, true, ['https://mywebsite.com']);
        $publicKey = $channel->webchatSettings->public_key;

        $response = $this->postJson("/api/v1/public/webchat/{$publicKey}/embed", [], [
            'Origin' => 'https://mywebsite.com',
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Access-Control-Allow-Origin', 'https://mywebsite.com');
        $response->assertHeader('Vary', 'Origin');
        $this->assertNotEquals('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_cors_preflight_options_for_unauthorized_origin_is_rejected(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = $this->createWebchatChannel($version, true, ['https://allowed.com']);
        $publicKey = $channel->webchatSettings->public_key;

        $response = $this->call('OPTIONS', "/api/v1/public/webchat/{$publicKey}/embed", [], [], [], [
            'HTTP_ORIGIN' => 'https://unauthorized.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $response->assertStatus(403);
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }

    public function test_cors_origin_authorized_in_channel_a_is_denied_in_channel_b(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channelA = $this->createWebchatChannel($version, true, ['https://client-a.com']);
        $channelB = $this->createWebchatChannel($version, true, ['https://client-b.com']);

        $keyB = $channelB->webchatSettings->public_key;

        // Try using client-a origin against Channel B
        $response = $this->call('OPTIONS', "/api/v1/public/webchat/{$keyB}/embed", [], [], [], [
            'HTTP_ORIGIN' => 'https://client-a.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $response->assertStatus(403);
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));

        $postResponse = $this->postJson("/api/v1/public/webchat/{$keyB}/embed", [], [
            'Origin' => 'https://client-a.com',
        ]);

        $postResponse->assertStatus(403);
        $this->assertFalse($postResponse->headers->has('Access-Control-Allow-Origin'));
    }

    public function test_embed_handshake_with_inactive_channel_returns_422(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = $this->createWebchatChannel($version, false, ['https://mywebsite.com']);
        $publicKey = $channel->webchatSettings->public_key;

        $response = $this->postJson("/api/v1/public/webchat/{$publicKey}/embed", [], [
            'Origin' => 'https://mywebsite.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'channel_inactive');
    }

    public function test_embed_handshake_without_entry_flow_returns_controlled_error(): void
    {
        $channel = $this->createWebchatChannel(null, true, ['https://mywebsite.com']);
        $publicKey = $channel->webchatSettings->public_key;

        $response = $this->postJson("/api/v1/public/webchat/{$publicKey}/embed", [], [
            'Origin' => 'https://mywebsite.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'entry_flow_missing');
    }

    // ==========================================
    // Requirement 61: Tests backend — Sesiones
    // ==========================================

    public function test_create_webchat_session_stores_hash_and_returns_raw_token(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = $this->createWebchatChannel($version, true, ['https://mywebsite.com']);
        $publicKey = $channel->webchatSettings->public_key;

        $embedRes = $this->postJson("/api/v1/public/webchat/{$publicKey}/embed", [], ['Origin' => 'https://mywebsite.com']);
        $embedToken = $embedRes->json('data.embed_token');

        $sessionRes = $this->postJson("/api/v1/public/webchat/{$publicKey}/sessions", [
            'embed_token' => $embedToken,
        ], ['Origin' => 'https://mywebsite.com']);

        $sessionRes->assertStatus(201);
        $rawToken = $sessionRes->json('data.visitor_token');
        $sessionId = $sessionRes->json('data.session_id');

        $this->assertNotEmpty($rawToken);
        $this->assertStringStartsWith('wcs_', $rawToken);

        // Verify DB stores SHA-256 hash, NEVER the raw token
        $sessionInDb = WebchatSession::find($sessionId);
        $this->assertNotNull($sessionInDb);
        $this->assertNotEquals($rawToken, $sessionInDb->token_hash);
        $this->assertEquals(hash('sha256', $rawToken), $sessionInDb->token_hash);
    }

    public function test_session_can_be_resumed_with_valid_visitor_token(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = $this->createWebchatChannel($version, true, ['https://mywebsite.com']);
        $publicKey = $channel->webchatSettings->public_key;

        $embedRes = $this->postJson("/api/v1/public/webchat/{$publicKey}/embed", [], ['Origin' => 'https://mywebsite.com']);
        $embedToken = $embedRes->json('data.embed_token');

        $session1 = $this->postJson("/api/v1/public/webchat/{$publicKey}/sessions", [
            'embed_token' => $embedToken,
        ], ['Origin' => 'https://mywebsite.com']);
        $rawToken = $session1->json('data.visitor_token');
        $sessionId = $session1->json('data.session_id');

        // Resume using same visitor token
        $session2 = $this->postJson("/api/v1/public/webchat/{$publicKey}/sessions", [
            'embed_token' => $embedToken,
            'visitor_token' => $rawToken,
        ], ['Origin' => 'https://mywebsite.com']);

        $session2->assertStatus(200);
        $this->assertEquals($sessionId, $session2->json('data.session_id'));
    }

    public function test_invalid_visitor_token_rejected_on_protected_endpoints(): void
    {
        $response = $this->getJson('/api/v1/public/webchat/session', [
            'Authorization' => 'Bearer wcs_invalidtoken1234567890',
            'Origin' => 'https://mywebsite.com',
        ]);

        $response->assertStatus(401);
    }

    public function test_expired_session_returns_401(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = $this->createWebchatChannel($version, true, ['https://mywebsite.com']);

        $tokenPair = WebchatSession::createTokenPair();
        $contact = Contact::create(['display_name' => 'Expired User', 'external_identifier' => 'exp_1']);
        $session = WebchatSession::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'token_hash' => $tokenPair['token_hash'],
            'origin' => 'https://mywebsite.com',
            'last_seen_at' => now()->subDays(40),
            'expires_at' => now()->subDay(), // Expired
        ]);

        $response = $this->getJson('/api/v1/public/webchat/session', [
            'Authorization' => 'Bearer '.$tokenPair['raw_token'],
            'Origin' => 'https://mywebsite.com',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('code', 'session_expired');
    }

    public function test_revoked_session_returns_401(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = $this->createWebchatChannel($version, true, ['https://mywebsite.com']);

        $tokenPair = WebchatSession::createTokenPair();
        $contact = Contact::create(['display_name' => 'Revoked User', 'external_identifier' => 'rev_1']);
        $session = WebchatSession::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'token_hash' => $tokenPair['token_hash'],
            'origin' => 'https://mywebsite.com',
            'last_seen_at' => now(),
            'expires_at' => now()->addDays(30),
            'revoked_at' => now(), // Revoked
        ]);

        $response = $this->getJson('/api/v1/public/webchat/session', [
            'Authorization' => 'Bearer '.$tokenPair['raw_token'],
            'Origin' => 'https://mywebsite.com',
        ]);

        $response->assertStatus(401);
    }

    // ==========================================
    // Requirement 62: Tests backend — Conversación
    // ==========================================

    public function test_starting_conversation_executes_entry_flow_and_returns_initial_bot_messages(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = $this->createWebchatChannel($version, true, ['https://mywebsite.com']);
        $publicKey = $channel->webchatSettings->public_key;

        $embedToken = $this->postJson("/api/v1/public/webchat/{$publicKey}/embed", [], ['Origin' => 'https://mywebsite.com'])->json('data.embed_token');
        $visitorToken = $this->postJson("/api/v1/public/webchat/{$publicKey}/sessions", ['embed_token' => $embedToken], ['Origin' => 'https://mywebsite.com'])->json('data.visitor_token');

        $headers = [
            'Authorization' => 'Bearer '.$visitorToken,
            'Origin' => 'https://mywebsite.com',
        ];

        // 1. Start conversation
        $convRes = $this->postJson('/api/v1/public/webchat/conversations/start', [], $headers);
        $convRes->assertStatus(201);
        $convId = $convRes->json('data.conversation.id');
        $this->assertNotNull($convId);
        $this->assertEquals('bot', $convRes->json('data.conversation.status'));

        // Verify initial bot message and question are present
        $messages = $convRes->json('data.messages');
        $this->assertCount(2, $messages);
        $this->assertEquals('Hola, ¿en qué podemos ayudarte?', $messages[0]['content']);
        $this->assertEquals('¿Cuál es tu nombre?', $messages[1]['content']);

        // 2. Calling start again recovers same conversation without creating a new one
        $convRes2 = $this->postJson('/api/v1/public/webchat/conversations/start', [], $headers);
        $convRes2->assertStatus(200);
        $this->assertEquals($convId, $convRes2->json('data.conversation.id'));
        $this->assertCount(1, Conversation::where('channel_id', $channel->id)->get());
    }

    public function test_closed_conversation_does_not_silently_reopen_and_allows_new_conversation(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = $this->createWebchatChannel($version, true, ['https://mywebsite.com']);
        $publicKey = $channel->webchatSettings->public_key;

        $embedToken = $this->postJson("/api/v1/public/webchat/{$publicKey}/embed", [], ['Origin' => 'https://mywebsite.com'])->json('data.embed_token');
        $visitorToken = $this->postJson("/api/v1/public/webchat/{$publicKey}/sessions", ['embed_token' => $embedToken], ['Origin' => 'https://mywebsite.com'])->json('data.visitor_token');

        $headers = [
            'Authorization' => 'Bearer '.$visitorToken,
            'Origin' => 'https://mywebsite.com',
        ];

        $startRes = $this->postJson('/api/v1/public/webchat/conversations/start', [], $headers);
        $firstConvId = $startRes->json('data.conversation.id');

        // Close conversation
        $firstConv = Conversation::find($firstConvId);
        $firstConv->update(['status' => ConversationStatus::CLOSED, 'closed_at' => now()]);

        // Sending message on closed conversation reopens the SAME conversation via ingress
        $clientMsgId = (string) Str::uuid();
        $msgRes = $this->postJson('/api/v1/public/webchat/messages', [
            'content' => 'Hola de nuevo',
            'client_message_id' => $clientMsgId,
        ], $headers);
        $msgRes->assertStatus(201);
        $this->assertEquals($firstConvId, $msgRes->json('conversation.id'));
        $this->assertEquals('bot', $msgRes->json('conversation.status'));
        $this->assertFalse($msgRes->json('is_duplicate'));

        // Idempotency: re-sending the same client_message_id returns 200 with is_duplicate = true
        $msgDup = $this->postJson('/api/v1/public/webchat/messages', [
            'content' => 'Hola de nuevo',
            'client_message_id' => $clientMsgId,
        ], $headers);
        $msgDup->assertStatus(200);
        $this->assertTrue($msgDup->json('is_duplicate'));

        // Re-close conversation to verify startConversation reopening path
        $firstConv->fresh()->update(['status' => ConversationStatus::CLOSED, 'closed_at' => now()]);

        // Start new conversation: reuses conversation with session divider (persistent threading)
        $startRes2 = $this->postJson('/api/v1/public/webchat/conversations/start', [], $headers);
        $startRes2->assertStatus(201);
        $secondConvId = $startRes2->json('data.conversation.id');
        $this->assertEquals($firstConvId, $secondConvId);
        $this->assertEquals(1, Conversation::where('channel_id', $channel->id)->count());

        // Verify session divider exists in database
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $firstConvId,
            'sender_type' => 'system',
            'visibility' => 'internal',
            'content' => '── Sesión finalizada ──',
        ]);

        $messages2 = $startRes2->json('data.messages');
        $contents = array_column($messages2, 'content');
        $this->assertNotContains('── Sesión finalizada ──', $contents, 'Internal session dividers must not be exposed to visitors.');
    }

    // ==========================================
    // Requirement 63: Tests backend — Mensajes
    // ==========================================

    public function test_visitor_sends_message_and_idempotency_prevents_duplicate_processing(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = $this->createWebchatChannel($version, true, ['https://mywebsite.com']);
        $publicKey = $channel->webchatSettings->public_key;

        $embedToken = $this->postJson("/api/v1/public/webchat/{$publicKey}/embed", [], ['Origin' => 'https://mywebsite.com'])->json('data.embed_token');
        $visitorToken = $this->postJson("/api/v1/public/webchat/{$publicKey}/sessions", ['embed_token' => $embedToken], ['Origin' => 'https://mywebsite.com'])->json('data.visitor_token');

        $headers = [
            'Authorization' => 'Bearer '.$visitorToken,
            'Origin' => 'https://mywebsite.com',
        ];

        $this->postJson('/api/v1/public/webchat/conversations/start', [], $headers);

        $clientMessageId = (string) Str::uuid();

        // 1. Send first time
        $msgRes1 = $this->postJson('/api/v1/public/webchat/messages', [
            'content' => 'Carlos',
            'client_message_id' => $clientMessageId,
        ], $headers);

        $msgRes1->assertStatus(201);
        $msgId1 = $msgRes1->json('data.id');
        $this->assertFalse($msgRes1->json('is_duplicate'));

        // 2. Send same message again with identical client_message_id (e.g. network retry)
        $msgRes2 = $this->postJson('/api/v1/public/webchat/messages', [
            'content' => 'Carlos',
            'client_message_id' => $clientMessageId,
        ], $headers);

        $msgRes2->assertStatus(200);
        $this->assertTrue($msgRes2->json('is_duplicate'));
        $this->assertEquals($msgId1, $msgRes2->json('data.id'));

        // Verify only 1 contact message was persisted
        $count = Message::where('external_message_id', $clientMessageId)->count();
        $this->assertEquals(1, $count);
    }

    public function test_visitor_message_broadcast_reaches_agent_inbox_and_webchat_conversation(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = $this->createWebchatChannel($version, true, ['https://mywebsite.com']);
        $publicKey = $channel->webchatSettings->public_key;

        $embedToken = $this->postJson("/api/v1/public/webchat/{$publicKey}/embed", [], ['Origin' => 'https://mywebsite.com'])->json('data.embed_token');
        $visitorToken = $this->postJson("/api/v1/public/webchat/{$publicKey}/sessions", ['embed_token' => $embedToken], ['Origin' => 'https://mywebsite.com'])->json('data.visitor_token');
        $headers = [
            'Authorization' => 'Bearer '.$visitorToken,
            'Origin' => 'https://mywebsite.com',
        ];

        $start = $this->postJson('/api/v1/public/webchat/conversations/start', [], $headers)->assertStatus(201);
        $conversationId = $start->json('data.conversation.id');
        Event::fake([MessageCreated::class]);

        $response = $this->postJson('/api/v1/public/webchat/messages', [
            'content' => 'Mensaje del visitante para el agente',
            'client_message_id' => (string) Str::uuid(),
        ], $headers);

        $response->assertStatus(201);
        Event::assertDispatched(MessageCreated::class, function (MessageCreated $event) use ($conversationId): bool {
            if ($event->message->content !== 'Mensaje del visitante para el agente'
                || $event->message->conversation_id !== $conversationId
                || $event->message->sender_type->value !== 'contact') {
                return false;
            }

            $channels = collect($event->broadcastOn())->pluck('name')->all();

            $workspaceId = $event->message->conversation->workspace_id;

            return in_array("private-workspace.{$workspaceId}.inbox", $channels, true)
                && in_array("private-workspace.{$workspaceId}.conversation.{$conversationId}", $channels, true)
                && in_array("private-webchat.conversation.{$conversationId}", $channels, true);
        });
    }

    public function test_message_exceeding_max_length_is_rejected(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = $this->createWebchatChannel($version, true, ['https://mywebsite.com']);
        $publicKey = $channel->webchatSettings->public_key;

        $embedToken = $this->postJson("/api/v1/public/webchat/{$publicKey}/embed", [], ['Origin' => 'https://mywebsite.com'])->json('data.embed_token');
        $visitorToken = $this->postJson("/api/v1/public/webchat/{$publicKey}/sessions", ['embed_token' => $embedToken], ['Origin' => 'https://mywebsite.com'])->json('data.visitor_token');

        $headers = [
            'Authorization' => 'Bearer '.$visitorToken,
            'Origin' => 'https://mywebsite.com',
        ];

        $this->postJson('/api/v1/public/webchat/conversations/start', [], $headers);

        $giantPayload = str_repeat('A', 4001);
        $response = $this->postJson('/api/v1/public/webchat/messages', [
            'content' => $giantPayload,
            'client_message_id' => (string) Str::uuid(),
        ], $headers);

        $response->assertStatus(422);
    }

    public function test_internal_messages_are_excluded_from_public_api(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = $this->createWebchatChannel($version, true, ['https://mywebsite.com']);
        $publicKey = $channel->webchatSettings->public_key;

        $embedToken = $this->postJson("/api/v1/public/webchat/{$publicKey}/embed", [], ['Origin' => 'https://mywebsite.com'])->json('data.embed_token');
        $visitorToken = $this->postJson("/api/v1/public/webchat/{$publicKey}/sessions", ['embed_token' => $embedToken], ['Origin' => 'https://mywebsite.com'])->json('data.visitor_token');

        $headers = [
            'Authorization' => 'Bearer '.$visitorToken,
            'Origin' => 'https://mywebsite.com',
        ];

        $convRes = $this->postJson('/api/v1/public/webchat/conversations/start', [], $headers);
        $convId = $convRes->json('data.conversation.id');

        // Create an internal technical message
        Message::create([
            'conversation_id' => $convId,
            'sender_type' => MessageSenderType::SYSTEM,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::SYSTEM,
            'visibility' => 'internal',
            'content' => 'Error técnico interno: timeout de conector externo',
            'sent_at' => now(),
        ]);

        $res = $this->getJson('/api/v1/public/webchat/messages', $headers);
        $res->assertStatus(200);

        $contents = collect($res->json('data'))->pluck('content')->all();
        $this->assertNotContains('Error técnico interno: timeout de conector externo', $contents);
    }

    // ==========================================
    // Requirement 64: Tests backend — Autorización Realtime
    // ==========================================

    public function test_session_owner_can_authorize_its_conversation_realtime_channel(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = $this->createWebchatChannel($version, true, ['https://mywebsite.com']);
        $publicKey = $channel->webchatSettings->public_key;

        $embedToken = $this->postJson("/api/v1/public/webchat/{$publicKey}/embed", [], ['Origin' => 'https://mywebsite.com'])->json('data.embed_token');
        $visitorToken = $this->postJson("/api/v1/public/webchat/{$publicKey}/sessions", ['embed_token' => $embedToken], ['Origin' => 'https://mywebsite.com'])->json('data.visitor_token');

        $headers = [
            'Authorization' => 'Bearer '.$visitorToken,
            'Origin' => 'https://mywebsite.com',
        ];

        $convRes = $this->postJson('/api/v1/public/webchat/conversations/start', [], $headers);
        $convId = $convRes->json('data.conversation.id');

        // Authorize broadcast
        $authRes = $this->postJson('/api/v1/public/webchat/broadcast/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-webchat.conversation.{$convId}",
        ], $headers);

        $authRes->assertStatus(200);
        $this->assertNotNull($authRes->json('auth'));
    }

    public function test_another_session_cannot_authorize_foreign_conversation_channel(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = $this->createWebchatChannel($version, true, ['https://mywebsite.com']);
        $publicKey = $channel->webchatSettings->public_key;

        // Session 1
        $embedToken1 = $this->postJson("/api/v1/public/webchat/{$publicKey}/embed", [], ['Origin' => 'https://mywebsite.com'])->json('data.embed_token');
        $visitorToken1 = $this->postJson("/api/v1/public/webchat/{$publicKey}/sessions", ['embed_token' => $embedToken1], ['Origin' => 'https://mywebsite.com'])->json('data.visitor_token');
        $convRes1 = $this->postJson('/api/v1/public/webchat/conversations/start', [], [
            'Authorization' => 'Bearer '.$visitorToken1,
            'Origin' => 'https://mywebsite.com',
        ]);
        $convId1 = $convRes1->json('data.conversation.id');

        // Session 2
        $embedToken2 = $this->postJson("/api/v1/public/webchat/{$publicKey}/embed", [], ['Origin' => 'https://mywebsite.com'])->json('data.embed_token');
        $visitorToken2 = $this->postJson("/api/v1/public/webchat/{$publicKey}/sessions", ['embed_token' => $embedToken2], ['Origin' => 'https://mywebsite.com'])->json('data.visitor_token');

        // Session 2 attempts to authorize Session 1's conversation
        $authRes = $this->postJson('/api/v1/public/webchat/broadcast/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-webchat.conversation.{$convId1}",
        ], [
            'Authorization' => 'Bearer '.$visitorToken2,
            'Origin' => 'https://mywebsite.com',
        ]);

        $authRes->assertStatus(403);
    }

    // ==========================================
    // Requirement 65: Tests backend — Broadcast
    // ==========================================

    public function test_bot_message_dispatches_message_created_broadcast(): void
    {
        Event::fake([MessageCreated::class]);

        $version = $this->createStandardPublishedFlow();
        $channel = $this->createWebchatChannel($version, true, ['https://mywebsite.com']);
        $publicKey = $channel->webchatSettings->public_key;

        $embedToken = $this->postJson("/api/v1/public/webchat/{$publicKey}/embed", [], ['Origin' => 'https://mywebsite.com'])->json('data.embed_token');
        $visitorToken = $this->postJson("/api/v1/public/webchat/{$publicKey}/sessions", ['embed_token' => $embedToken], ['Origin' => 'https://mywebsite.com'])->json('data.visitor_token');

        $headers = [
            'Authorization' => 'Bearer '.$visitorToken,
            'Origin' => 'https://mywebsite.com',
        ];

        $this->postJson('/api/v1/public/webchat/conversations/start', [], $headers);

        Event::assertDispatched(MessageCreated::class);
    }

    public function test_agent_message_dispatches_message_created_broadcast(): void
    {
        Event::fake([MessageCreated::class]);

        $agent = User::create([
            'name' => 'Agent Human',
            'email' => 'agent@example.com',
            'password' => Hash::make('Secret123456789!'),
            'is_active' => true,
        ]);
        $agent->roles()->attach(Role::where('name', 'agent')->first());

        $channel = $this->createWebchatChannel();
        $agent->channels()->attach($channel->id);
        $contact = Contact::create(['display_name' => 'Cliente Test', 'external_identifier' => 'c_1']);
        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $agent->id,
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        $response = $this->actingAs($agent, 'sanctum')->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Hola, soy tu agente de soporte.',
        ]);

        $response->assertStatus(201);
        Event::assertDispatched(MessageCreated::class, function (MessageCreated $event) use ($conversation): bool {
            if ($event->message->content !== 'Hola, soy tu agente de soporte.'
                || $event->message->conversation_id !== $conversation->id) {
                return false;
            }

            $channels = collect($event->broadcastOn())->pluck('name')->all();

            $workspaceId = $event->message->conversation->workspace_id;

            return in_array("private-workspace.{$workspaceId}.inbox", $channels, true)
                && in_array("private-workspace.{$workspaceId}.conversation.{$conversation->id}", $channels, true)
                && in_array("private-webchat.conversation.{$conversation->id}", $channels, true);
        });
    }

    public function test_supervisor_assign_dispatches_conversation_assigned_broadcast(): void
    {
        Event::fake([ConversationAssigned::class]);

        $supervisor = User::create([
            'name' => 'Supervisor User',
            'email' => 'supervisor@example.com',
            'password' => Hash::make('Secret123456789!'),
            'is_active' => true,
        ]);
        $supervisor->roles()->attach(Role::where('name', 'supervisor')->first());

        $agent = User::create([
            'name' => 'Agent User',
            'email' => 'agent2@example.com',
            'password' => Hash::make('Secret123456789!'),
            'is_active' => true,
        ]);
        $agent->roles()->attach(Role::where('name', 'agent')->first());

        $channel = $this->createWebchatChannel();
        $this->grantChannelAccess($channel, $supervisor, $agent);
        $this->addToDefaultTeam($agent);
        $contact = Contact::create(['display_name' => 'Cliente Test', 'external_identifier' => 'c_2']);
        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        $response = $this->actingAs($supervisor, 'sanctum')->postJson("/api/v1/conversations/{$conversation->id}/assign", [
            'assigned_user_id' => $agent->id,
            'reason' => 'Atención especializada requerida',
        ]);

        $response->assertStatus(200);
        Event::assertDispatched(ConversationAssigned::class, function ($e) use ($agent) {
            return $e->assignedUser?->id === $agent->id && $e->reason === 'Atención especializada requerida';
        });
    }

    public function test_strict_chronological_order_visitor_inbound_before_bot_outbound_messages(): void
    {
        $version = $this->createStandardPublishedFlow();

        // Add greeting node after q_name
        $greetingNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_greeting',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Saludo',
            'configuration' => [
                'text' => 'Mucho gusto, {{visitor_name}}.',
            ],
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'q_name',
            'target_node_key' => 'msg_greeting',
        ]);

        $channel = $this->createWebchatChannel($version, true, ['https://mywebsite.com']);
        $publicKey = $channel->webchatSettings->public_key;

        $embedToken = $this->postJson("/api/v1/public/webchat/{$publicKey}/embed", [], ['Origin' => 'https://mywebsite.com'])->json('data.embed_token');
        $visitorToken = $this->postJson("/api/v1/public/webchat/{$publicKey}/sessions", ['embed_token' => $embedToken], ['Origin' => 'https://mywebsite.com'])->json('data.visitor_token');

        $headers = [
            'Authorization' => 'Bearer '.$visitorToken,
            'Origin' => 'https://mywebsite.com',
        ];

        // Start conversation
        $this->postJson('/api/v1/public/webchat/conversations/start', [], $headers)->assertStatus(201);

        // Visitor answers name question with "Luis"
        $clientMsgId = (string) Str::uuid();
        $msgRes = $this->postJson('/api/v1/public/webchat/messages', [
            'content' => 'Luis',
            'client_message_id' => $clientMsgId,
        ], $headers);

        $msgRes->assertStatus(201);
        $resJson = $msgRes->json();

        $this->assertEquals($clientMsgId, $resJson['data']['client_message_id']);
        $this->assertEquals('contact', $resJson['data']['sender_type']);

        $messages = $resJson['messages'];
        $this->assertNotEmpty($messages);

        // Locate the visitor message in the returned list
        $visitorIdx = null;
        $botGreetingIdx = null;
        foreach ($messages as $idx => $m) {
            if ($m['content'] === 'Luis' && $m['sender_type'] === 'contact') {
                $visitorIdx = $idx;
            }
            if ($m['content'] === 'Mucho gusto, Luis.' && $m['sender_type'] === 'bot') {
                $botGreetingIdx = $idx;
            }
        }

        $this->assertNotNull($visitorIdx, 'El mensaje del visitante debe estar presente en la lista.');
        $this->assertNotNull($botGreetingIdx, 'El mensaje del bot debe estar presente en la lista.');
        // STRICT REQUIREMENT: Inbound visitor message MUST be strictly before bot response
        $this->assertLessThan($botGreetingIdx, $visitorIdx, 'El mensaje del visitante debe aparecer ANTES que la respuesta del bot.');

        // Also query GET /messages and verify same strict order
        $getRes = $this->getJson('/api/v1/public/webchat/messages', $headers);
        $getRes->assertStatus(200);
        $getMessages = $getRes->json('data');

        $visitorIdxGet = null;
        $botGreetingIdxGet = null;
        foreach ($getMessages as $idx => $m) {
            if ($m['content'] === 'Luis' && $m['sender_type'] === 'contact') {
                $visitorIdxGet = $idx;
            }
            if ($m['content'] === 'Mucho gusto, Luis.' && $m['sender_type'] === 'bot') {
                $botGreetingIdxGet = $idx;
            }
        }

        $this->assertNotNull($visitorIdxGet);
        $this->assertNotNull($botGreetingIdxGet);
        $this->assertLessThan($botGreetingIdxGet, $visitorIdxGet);
    }

    public function test_webchat_question_invalid_choice_returns_interactive_options_in_payload(): void
    {
        $flow = Flow::create(['name' => 'Webchat Choice Question Flow']);
        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::PUBLISHED,
            'published_at' => now(),
        ]);
        $flow->update(['status' => FlowStatus::PUBLISHED, 'active_version_id' => $version->id]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
            'configuration' => [],
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'q_talk',
            'type' => FlowNodeType::QUESTION,
            'name' => 'Pregunta Agente',
            'configuration' => [
                'text' => '¿Quieres conversar con un agente?',
                'variable' => 'talk_to_agent',
                'input_type' => 'choice',
                'choices' => ['Sí', 'No'],
                'invalid_message' => 'Por favor, elige una de las opciones válidas: Sí, No.',
            ],
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start_1',
            'target_node_key' => 'q_talk',
        ]);

        $channel = $this->createWebchatChannel($version, true, ['https://mywebsite.com']);
        $publicKey = $channel->webchatSettings->public_key;

        $embedToken = $this->postJson("/api/v1/public/webchat/{$publicKey}/embed", [], ['Origin' => 'https://mywebsite.com'])->json('data.embed_token');
        $visitorToken = $this->postJson("/api/v1/public/webchat/{$publicKey}/sessions", ['embed_token' => $embedToken], ['Origin' => 'https://mywebsite.com'])->json('data.visitor_token');

        $headers = [
            'Authorization' => 'Bearer '.$visitorToken,
            'Origin' => 'https://mywebsite.com',
        ];

        // Start conversation: bot asks "¿Quieres conversar con un agente?"
        $startRes = $this->postJson('/api/v1/public/webchat/conversations/start', [], $headers)->assertStatus(201);
        $startMessages = $startRes->json('data.messages');
        $questionMsg = collect($startMessages)->firstWhere('sender_type', 'bot');
        $this->assertEquals('interactive', $questionMsg['message_type']);
        $this->assertCount(2, $questionMsg['payload']['options']);

        // Visitor types invalid input: "Hola"
        $msgRes = $this->postJson('/api/v1/public/webchat/messages', [
            'content' => 'Hola',
            'client_message_id' => (string) Str::uuid(),
        ], $headers);

        $msgRes->assertStatus(201);
        $returnedMessages = $msgRes->json('messages');
        $retryBotMsg = collect($returnedMessages)->where('sender_type', 'bot')->last();

        $this->assertNotNull($retryBotMsg);
        $this->assertEquals('Por favor, elige una de las opciones válidas: Sí, No.', $retryBotMsg['content']);
        $this->assertEquals('interactive', $retryBotMsg['message_type']);
        $this->assertNotNull($retryBotMsg['payload']);
        $this->assertTrue($retryBotMsg['payload']['validation_error']);
        $this->assertCount(2, $retryBotMsg['payload']['options']);
        $this->assertEquals('Sí', $retryBotMsg['payload']['options'][0]['title']);
        $this->assertEquals('No', $retryBotMsg['payload']['options'][1]['title']);
    }
}
