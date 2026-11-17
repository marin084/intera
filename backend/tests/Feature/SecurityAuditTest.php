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
use App\Events\MessageCreated;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Models\WebchatChannelSettings;
use App\Models\WebchatSession;
use App\Services\Media\WhatsAppMediaService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $agentUser;

    protected User $supervisorUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::create([
            'name' => 'Admin Sec',
            'email' => 'admin.sec@test.com',
            'password' => Hash::make('SecretPass123!'),
            'is_active' => true,
        ]);
        $this->adminUser->roles()->attach(Role::where('name', 'super_admin')->first());

        $this->supervisorUser = User::create([
            'name' => 'Supervisor Sec',
            'email' => 'supervisor.sec@test.com',
            'password' => Hash::make('SecretPass123!'),
            'is_active' => true,
        ]);
        $this->supervisorUser->roles()->attach(Role::where('name', 'supervisor')->first());

        $this->agentUser = User::create([
            'name' => 'Agent Sec',
            'email' => 'agent.sec@test.com',
            'password' => Hash::make('SecretPass123!'),
            'is_active' => true,
        ]);
        $this->agentUser->roles()->attach(Role::where('name', 'agent')->first());
    }

    public function test_whatsapp_media_service_blocks_ssrf_attacks(): void
    {
        // 1. Loopback addresses
        $this->assertFalse(WhatsAppMediaService::isValidMetaDownloadUrl('http://127.0.0.1:8000/private-data'));
        $this->assertFalse(WhatsAppMediaService::isValidMetaDownloadUrl('https://127.0.0.1/secrets'));
        $this->assertFalse(WhatsAppMediaService::isValidMetaDownloadUrl('http://localhost:3000'));

        // 2. Cloud metadata endpoint
        $this->assertFalse(WhatsAppMediaService::isValidMetaDownloadUrl('http://169.254.169.254/latest/meta-data'));

        // 3. Private CIDR blocks
        $this->assertFalse(WhatsAppMediaService::isValidMetaDownloadUrl('https://10.0.0.1/media'));
        $this->assertFalse(WhatsAppMediaService::isValidMetaDownloadUrl('https://172.16.0.1/media'));
        $this->assertFalse(WhatsAppMediaService::isValidMetaDownloadUrl('https://192.168.1.1/media'));

        // 4. Unauthorized external hosts
        $this->assertFalse(WhatsAppMediaService::isValidMetaDownloadUrl('https://evil-attacker.com/payload.jpg'));
        $this->assertFalse(WhatsAppMediaService::isValidMetaDownloadUrl('https://attacker-fbsbx.com/fake'));

        // 5. Non-HTTPS scheme
        $this->assertFalse(WhatsAppMediaService::isValidMetaDownloadUrl('ftp://lookaside.fbsbx.com/test'));
        $this->assertFalse(WhatsAppMediaService::isValidMetaDownloadUrl('http://lookaside.fbsbx.com/test'));

        // 6. Legitimate Meta / WhatsApp CDNs
        $this->assertTrue(WhatsAppMediaService::isValidMetaDownloadUrl('https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=123'));
        $this->assertTrue(WhatsAppMediaService::isValidMetaDownloadUrl('https://mmg.whatsapp.net/v/t62.7118-24/test.enc'));
        $this->assertTrue(WhatsAppMediaService::isValidMetaDownloadUrl('https://graph.facebook.com/v21.0/media/123456'));

        // 7. downloadAndStoreMedia rejects SSRF URLs returned by Meta API and safely returns null
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'url' => 'http://127.0.0.1:8080/internal-leak',
                'mime_type' => 'image/jpeg',
            ], 200),
            'http://127.0.0.1*' => Http::response('SHOULD NEVER BE REACHED', 500),
        ]);

        $channel = Channel::create([
            'name' => 'WA SSRF Test',
            'type' => ChannelType::WHATSAPP,
            'is_active' => true,
            'credentials' => [
                'access_token' => 'valid-mock-token',
                'phone_number_id' => '1234567890',
            ],
        ]);

        $service = new WhatsAppMediaService();
        $result = $service->downloadAndStoreMedia($channel, 'media_ssrf_test');
        $this->assertNull($result, 'downloadAndStoreMedia must return null and abort download when Meta returns an SSRF URL');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
    }

    public function test_internal_and_transfer_notes_are_strictly_isolated_from_webchat_public_api(): void
    {
        // 1. Create Webchat channel with flow
        $flow = Flow::create([
            'name' => 'Sec Audit Webchat Flow',
            'status' => FlowStatus::PUBLISHED,
            'channel_applicability' => ['webchat'],
        ]);
        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::PUBLISHED,
        ]);
        $startNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_sec',
            'type' => FlowNodeType::START,
            'name' => 'Start Node',
            'configuration' => [],
            'position' => ['x' => 100, 'y' => 100],
        ]);
        $flow->update(['active_version_id' => $version->id]);

        $channel = Channel::create([
            'name' => 'Webchat Audit Channel',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
            'entry_flow_id' => $flow->id,
            'configuration' => [],
        ]);

        $publicKey = 'wb_sec_' . Str::random(24);
        WebchatChannelSettings::create([
            'channel_id' => $channel->id,
            'public_key' => $publicKey,
            'allowed_origins' => ['https://mywebsite.com'],
            'display_name' => 'Webchat Audit',
            'accent_color' => '#6366f1',
        ]);

        $contact = Contact::create([
            'channel_id' => $channel->id,
            'first_seen_channel' => ChannelType::WEBCHAT,
            'display_name' => 'Visitor Alice',
            'identity_key' => 'visitor_sec_' . Str::random(12),
        ]);

        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        $plainVisitorToken = 'vtok_' . Str::random(40);
        $webchatSession = WebchatSession::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'active_conversation_id' => $conversation->id,
            'token_hash' => WebchatSession::hashToken($plainVisitorToken),
            'origin' => 'https://mywebsite.com',
            'last_seen_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        // 2. Populate conversation with 4 types of messages:
        // A. Inbound visitor message (public)
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $contact->id,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'visibility' => 'public',
            'content' => 'Hola, tengo una consulta pública.',
            'sent_at' => now()->subMinutes(10),
        ]);

        // B. Outbound agent response (external)
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'sender_id' => (string) $this->agentUser->id,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'visibility' => 'external',
            'content' => '¡Hola! Te puedo ayudar con gusto.',
            'sent_at' => now()->subMinutes(9),
        ]);

        // C. Internal agent note (CONFIDENTIAL)
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'sender_id' => (string) $this->agentUser->id,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'visibility' => 'internal',
            'content' => 'NOTA INTERNA CONFIDENCIAL: Cliente evaluando paquete empresarial VIP.',
            'sent_at' => now()->subMinutes(8),
        ]);

        // D. Internal transfer note (CONFIDENTIAL)
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'sender_id' => (string) $this->agentUser->id,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'visibility' => 'internal',
            'content' => '🔄 Transferido a Supervisor Sec. Motivo: Negociación de descuento.',
            'sent_at' => now()->subMinutes(7),
        ]);

        // 3. Query the public Webchat messages endpoint as the visitor
        $response = $this->withHeader('X-Webchat-Session-Token', $plainVisitorToken)
            ->getJson('/api/v1/public/webchat/messages');

        $response->assertStatus(200);

        $messages = $response->json('data');
        $this->assertCount(2, $messages, 'Only the 2 public/external messages should be returned to the visitor.');

        $rawJson = $response->getContent();
        $this->assertStringNotContainsString('NOTA INTERNA CONFIDENCIAL', $rawJson);
        $this->assertStringNotContainsString('Transferido a Supervisor Sec', $rawJson);

        $contents = array_column($messages, 'content');
        $this->assertContains('Hola, tengo una consulta pública.', $contents);
        $this->assertContains('¡Hola! Te puedo ayudar con gusto.', $contents);
        $this->assertNotContains('NOTA INTERNA CONFIDENCIAL: Cliente evaluando paquete empresarial VIP.', $contents);
        $this->assertNotContains('🔄 Transferido a Supervisor Sec. Motivo: Negociación de descuento.', $contents);
    }

    public function test_internal_messages_are_not_broadcast_to_visitor_websocket_channel(): void
    {
        $conversationId = (string) Str::uuid();

        // 1. Internal message
        $internalMsg = new Message([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversationId,
            'visibility' => 'internal',
            'content' => 'Nota privada de supervisión',
        ]);

        $eventInternal = new MessageCreated($internalMsg);
        $channelsInternal = $eventInternal->broadcastOn();

        $channelNamesInternal = array_map(fn ($ch) => $ch->name, $channelsInternal);
        $this->assertContains("private-conversation.{$conversationId}", $channelNamesInternal);
        $this->assertContains('private-inbox', $channelNamesInternal);
        $this->assertNotContains("private-webchat.conversation.{$conversationId}", $channelNamesInternal, 'Internal notes must NEVER broadcast to visitor webchat channel');

        // 2. Public message
        $publicMsg = new Message([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversationId,
            'visibility' => 'public',
            'content' => 'Mensaje público para el cliente',
        ]);

        $eventPublic = new MessageCreated($publicMsg);
        $channelsPublic = $eventPublic->broadcastOn();

        $channelNamesPublic = array_map(fn ($ch) => $ch->name, $channelsPublic);
        $this->assertContains("private-webchat.conversation.{$conversationId}", $channelNamesPublic, 'Public messages must broadcast to visitor webchat channel');
    }

    public function test_malformed_uuids_do_not_cause_postgresql_500_errors(): void
    {
        // 1. Filtering conversations with malformed channel_id and tag_id
        $resFilter = $this->actingAs($this->agentUser)
            ->getJson('/api/v1/conversations?channel_id=invalid-uuid-1234&tag_id=not-a-uuid');

        $this->assertNotEquals(500, $resFilter->status(), 'Malformed UUID filter parameters must not throw PostgreSQL 22P02 500 error');
        $resFilter->assertStatus(200);

        // 2. Searching with malformed or SQL-like non-UUID string
        $resSearch = $this->actingAs($this->agentUser)
            ->getJson('/api/v1/conversations?search=malformed_non_uuid_text');

        $this->assertNotEquals(500, $resSearch->status(), 'Search with non-UUID text must not crash PostgreSQL with 22P02');
        $resSearch->assertStatus(200);

        // 3. Routing: Attempting to access conversation detail with non-UUID string in URL
        $resConvDetail = $this->actingAs($this->agentUser)
            ->getJson('/api/v1/conversations/not-a-valid-uuid');

        $this->assertNotEquals(500, $resConvDetail->status());
        $resConvDetail->assertStatus(404);

        // 4. Saved funnels with non-UUID id
        $resFunnelUpdate = $this->actingAs($this->supervisorUser)
            ->putJson('/api/v1/funnels/saved/not-a-valid-uuid', [
                'name' => 'Updated Funnel',
                'description' => 'Test',
                'flow_id' => (string) Str::uuid(),
                'version_id' => (string) Str::uuid(),
                'steps' => ['node_1'],
            ]);

        $this->assertNotEquals(500, $resFunnelUpdate->status());
        $resFunnelUpdate->assertStatus(404);

        $resFunnelDelete = $this->actingAs($this->supervisorUser)
            ->deleteJson('/api/v1/funnels/saved/not-a-valid-uuid');

        $this->assertNotEquals(500, $resFunnelDelete->status());
        $resFunnelDelete->assertStatus(404);
    }

    public function test_deactivated_users_are_immediately_revoked_and_blocked(): void
    {
        // 1. Create Sanctum token for active agent user
        $token = $this->agentUser->createToken('test-sec-token')->plainTextToken;

        // 2. Valid request with token succeeds
        $resActive = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');

        $resActive->assertStatus(200);
        $resActive->assertJsonPath('user.email', $this->agentUser->email);

        // 3. Deactivate user via update
        $resDeactivate = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/users/{$this->agentUser->id}", [
                'name' => 'Agent Sec Deactivated',
                'email' => 'agent.sec@test.com',
                'is_active' => false,
            ]);

        $resDeactivate->assertStatus(200);
        $this->assertFalse($this->agentUser->fresh()->is_active);
        $this->assertEquals(0, $this->agentUser->tokens()->count(), 'All Sanctum tokens must be deleted immediately upon deactivation');

        // Clear actingAs test session guards so the next request tests token authentication
        $this->app['auth']->forgetGuards();

        // 4. Any subsequent request with previous token must be rejected
        $resBlocked = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');

        $this->assertEquals(401, $resBlocked->status(), 'Revoked token must be rejected with 401 Unauthorized');

        // 5. If an inactive user attempts to hit the API with a token, EnsureUserIsActive middleware rejects with 403
        $inactiveUser = User::create([
            'name' => 'Inactive Guy',
            'email' => 'inactive.guy@test.com',
            'password' => Hash::make('SecretPass123!'),
            'is_active' => false,
        ]);
        $inactiveToken = $inactiveUser->createToken('inactive-token')->plainTextToken;

        $this->app['auth']->forgetGuards();
        $resInactive = $this->withHeader('Authorization', "Bearer {$inactiveToken}")
            ->getJson('/api/v1/auth/me');

        $resInactive->assertStatus(403);
        $resInactive->assertJsonPath('message', 'User account is inactive.');
    }

    public function test_rbac_restricts_agent_from_administrative_mutations_and_analytics(): void
    {
        // 1. Agent cannot create channels
        $resChannel = $this->actingAs($this->agentUser)
            ->postJson('/api/v1/channels', [
                'name' => 'Unauthorized Channel',
                'type' => 'whatsapp',
            ]);
        $resChannel->assertStatus(403);

        // 2. Agent cannot create flows
        $resFlow = $this->actingAs($this->agentUser)
            ->postJson('/api/v1/flows', [
                'name' => 'Unauthorized Flow',
            ]);
        $resFlow->assertStatus(403);

        // 3. Agent cannot create users
        $resUser = $this->actingAs($this->agentUser)
            ->postJson('/api/v1/users', [
                'name' => 'Unauthorized New User',
                'email' => 'evil@test.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);
        $resUser->assertStatus(403);

        // 4. Agent cannot create global tags
        $resTagAgent = $this->actingAs($this->agentUser)
            ->postJson('/api/v1/tags', [
                'name' => 'Unauthorized Tag',
                'color' => '#ef4444',
            ]);
        $resTagAgent->assertStatus(403);

        // 5. Supervisor CAN create tags
        $resTagSupervisor = $this->actingAs($this->supervisorUser)
            ->postJson('/api/v1/tags', [
                'name' => 'Supervisor Authorized Tag',
                'color' => '#10b981',
            ]);
        $resTagSupervisor->assertStatus(201);

        // 6. Agent cannot access high-level analytics
        $resAnalyticsRealtime = $this->actingAs($this->agentUser)
            ->getJson('/api/v1/analytics/realtime');
        $resAnalyticsRealtime->assertStatus(403);

        $resAnalyticsFunnels = $this->actingAs($this->agentUser)
            ->getJson('/api/v1/analytics/funnels');
        $resAnalyticsFunnels->assertStatus(403);

        $resAnalyticsSessions = $this->actingAs($this->agentUser)
            ->getJson('/api/v1/analytics/users-and-sessions');
        $resAnalyticsSessions->assertStatus(403);

        // 7. Supervisor CAN access analytics
        $resSupervisorAnalytics = $this->actingAs($this->supervisorUser)
            ->getJson('/api/v1/analytics/realtime');
        $resSupervisorAnalytics->assertStatus(200);
    }

    public function test_channel_credentials_are_never_leaked_in_plain_text(): void
    {
        $channel = Channel::create([
            'name' => 'WhatsApp Prod Channel',
            'type' => ChannelType::WHATSAPP,
            'is_active' => true,
            'credentials' => [
                'access_token' => 'EAAGm0PX4ZCpsBASecretMetaAccessToken1234567890',
                'app_secret' => 'super_secret_meta_app_secret_value',
                'phone_number_id' => '10987654321',
            ],
            'configuration' => [
                'business_account_id' => '9876543210',
            ],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/channels');

        $response->assertStatus(200);

        $rawResponse = $response->getContent();
        // Sensitive credentials must not be present in raw response
        $this->assertStringNotContainsString('EAAGm0PX4ZCpsBASecretMetaAccessToken1234567890', $rawResponse);
        $this->assertStringNotContainsString('super_secret_meta_app_secret_value', $rawResponse);
    }

    public function test_channel_update_preserves_real_whatsapp_secrets_when_passing_masked_or_empty_tokens(): void
    {
        $realToken = 'EAAGm0PX4ZCpsBASecretRealToken987654321';
        $realAppSecret = 'super_secret_meta_app_secret_value_real';
        $realVerifyToken = 'real_verify_token_alpha_numeric';

        $channel = Channel::create([
            'name' => 'Original WhatsApp Name',
            'type' => ChannelType::WHATSAPP,
            'is_active' => true,
            'configuration' => [
                'phone_number_id' => '1029384756',
                'business_account_id' => '5647382910',
                'access_token' => $realToken,
                'app_secret' => $realAppSecret,
                'verify_token' => $realVerifyToken,
                'api_version' => 'v21.0',
            ],
        ]);

        // Admin updates channel name sending masked asterisks, dots or empty string
        $updateRes = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/channels/{$channel->id}", [
                'name' => 'Updated WhatsApp Name',
                'configuration' => [
                    'phone_number_id' => '1029384756',
                    'business_account_id' => '5647382910',
                    'access_token' => '************',
                    'app_secret' => '',
                    'verify_token' => '••••••••••••',
                    'api_version' => 'v21.0',
                ],
            ]);

        $updateRes->assertStatus(200);

        $fresh = $channel->fresh();
        $this->assertEquals('Updated WhatsApp Name', $fresh->name);
        $this->assertEquals($realToken, $fresh->configuration['access_token'], 'Real access token in database must be preserved and not overwritten with asterisks');
        $this->assertEquals($realAppSecret, $fresh->configuration['app_secret'], 'Real app secret in database must be preserved and not overwritten with empty string');
        $this->assertEquals($realVerifyToken, $fresh->configuration['verify_token'], 'Real verify token in database must be preserved and not overwritten with dots');
    }
}
