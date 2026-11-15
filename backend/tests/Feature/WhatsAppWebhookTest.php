<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\FlowNodeType;
use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
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
use App\Services\AuditLogger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected Channel $whatsappChannel;

    protected string $appSecret = 'meta_super_secret_test_key_123';

    protected string $verifyToken = 'my_custom_whatsapp_verify_token_xyz';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::factory()->create();
        $this->adminUser->roles()->attach(Role::where('name', 'super_admin')->first());

        $this->whatsappChannel = Channel::create([
            'name' => 'WhatsApp Oficial Conexa',
            'type' => ChannelType::WHATSAPP,
            'is_active' => true,
            'configuration' => [
                'phone_number_id' => '10987654321',
                'business_account_id' => '9988776655',
                'access_token' => 'EAAG_TEST_ACCESS_TOKEN_XYZ',
                'verify_token' => $this->verifyToken,
                'app_secret' => $this->appSecret,
                'api_version' => 'v21.0',
            ],
        ]);
    }

    /**
     * Helper to compute X-Hub-Signature-256 header.
     */
    protected function computeSignature(string $rawContent, ?string $secret = null): string
    {
        $sec = $secret ?? $this->appSecret;

        return 'sha256='.hash_hmac('sha256', $rawContent, $sec);
    }

    /**
     * Helper to create a published flow with a Start node, a Menu node, and two response paths.
     */
    protected function createStandardMenuFlow(): FlowVersion
    {
        $flow = Flow::create([
            'name' => 'Flujo WhatsApp Test',
            'status' => FlowStatus::PUBLISHED,
            'channel_applicability' => ['whatsapp'],
        ]);

        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::PUBLISHED,
            'published_at' => now(),
        ]);

        $flow->update(['active_version_id' => $version->id]);

        $startNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
            'configuration' => [],
            'position_x' => 100,
            'position_y' => 100,
        ]);

        $menuNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'menu_1',
            'type' => FlowNodeType::MENU,
            'name' => 'Menú Principal',
            'configuration' => [
                'text' => 'Bienvenido a Conexa. Por favor elige:',
                'variable' => 'opcion_elegida',
                'options' => [
                    ['id' => 'opt_ventas', 'text' => 'Ventas', 'value' => 'ventas'],
                    ['id' => 'opt_soporte', 'text' => 'Soporte', 'value' => 'soporte'],
                ],
            ],
            'position_x' => 100,
            'position_y' => 200,
        ]);

        $salesMsgNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_sales',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Mensaje Ventas',
            'configuration' => [
                'text' => 'Has seleccionado el departamento de Ventas. Un ejecutivo te contactará.',
            ],
            'position_x' => 50,
            'position_y' => 350,
        ]);

        $endNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'end_1',
            'type' => FlowNodeType::END,
            'name' => 'Fin',
            'configuration' => [],
            'position_x' => 100,
            'position_y' => 500,
        ]);

        // Edges
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start_1',
            'target_node_key' => 'menu_1',
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'menu_1',
            'target_node_key' => 'msg_sales',
            'condition_handle' => 'opt_opt_ventas',
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'msg_sales',
            'target_node_key' => 'end_1',
        ]);

        $this->whatsappChannel->update(['entry_flow_id' => $flow->id]);

        return $version;
    }

    public function test_get_webhook_verification_success(): void
    {
        $challenge = '1158201444';

        $response = $this->get("/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}?hub.mode=subscribe&hub.verify_token={$this->verifyToken}&hub.challenge={$challenge}");

        $response->assertStatus(200);
        $this->assertEquals($challenge, $response->getContent());
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
    }

    public function test_get_webhook_verification_fails_with_invalid_token(): void
    {
        $response = $this->get("/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}?hub.mode=subscribe&hub.verify_token=wrong_token&hub.challenge=123");

        $response->assertStatus(403);
    }

    public function test_post_webhook_hmac_signature_validation(): void
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [],
        ];
        $rawContent = (string) json_encode($payload);

        // 1. Missing signature -> 403
        $this->call('POST', "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $rawContent)->assertStatus(403);

        // 2. Tampered / wrong signature -> 403
        $this->call('POST', "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid_hash_value_123',
        ], $rawContent)->assertStatus(403);

        // 3. Valid HMAC signature -> 200
        $validSignature = $this->computeSignature($rawContent);
        $this->call('POST', "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $validSignature,
        ], $rawContent)->assertStatus(200);
    }

    public function test_post_webhook_ignores_status_notifications_safely(): void
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => '9988776655',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'statuses' => [
                                    [
                                        'id' => 'wamid.HBgLMTIzNDU2Nw==',
                                        'status' => 'delivered',
                                        'timestamp' => '1603059201',
                                        'recipient_id' => '5215512345678',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $raw = (string) json_encode($payload);
        $sig = $this->computeSignature($raw);

        $initialMessages = Message::count();
        $initialConversations = Conversation::count();

        $res = $this->call('POST', "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $sig,
        ], $raw);

        $res->assertStatus(200);
        $this->assertDatabaseCount('messages', $initialMessages);
        $this->assertDatabaseCount('conversations', $initialConversations);
    }

    public function test_post_webhook_inbound_text_starts_conversation_and_sends_bot_outbound_menu(): void
    {
        Log::spy();
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '5215512345678', 'wa_id' => '5215512345678']],
                'messages' => [['id' => 'wamid.OUTBOUND_BOT_MSG_1']],
            ], 200),
        ]);

        $this->createStandardMenuFlow();

        $wamid = 'wamid.HBgLMTIzNDU2Nzg5MA==';
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => '9988776655',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '15550254444',
                                    'phone_number_id' => '10987654321',
                                ],
                                'contacts' => [
                                    [
                                        'profile' => ['name' => 'Carlos Slim'],
                                        'wa_id' => '5215512345678',
                                    ],
                                ],
                                'messages' => [
                                    [
                                        'from' => '5215512345678',
                                        'id' => $wamid,
                                        'timestamp' => '1730000000',
                                        'type' => 'text',
                                        'text' => ['body' => 'Hola, quiero información'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $raw = (string) json_encode($payload);
        $sig = $this->computeSignature($raw);

        $res = $this->call('POST', "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $sig,
        ], $raw);

        $res->assertStatus(200);

        Log::shouldHaveReceived('info')->withArgs(function (...$arguments) use ($wamid): bool {
            [$event, $context] = array_pad($arguments, 2, []);

            if (! is_array($context)) {
                return false;
            }

            return $event === 'whatsapp.inbound.processed'
                && $context['external_message_id_hash'] === substr(hash('sha256', $wamid), 0, 16)
                && $context['message_type'] === 'text'
                && $context['is_duplicate'] === false
                && isset($context['correlation_id'], $context['processing_ms'])
                && ! array_key_exists('content', $context)
                && ! array_key_exists('phone', $context);
        })->once();

        // Check Contact created
        $contact = Contact::where('phone', '5215512345678')->first();
        $this->assertNotNull($contact);
        $this->assertEquals('Carlos Slim', $contact->display_name);

        // Check Conversation created
        $conversation = Conversation::where('contact_id', $contact->id)->where('channel_id', $this->whatsappChannel->id)->first();
        $this->assertNotNull($conversation);

        // Check Inbound Message persisted
        $inboundMsg = Message::where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::INBOUND)
            ->where('external_message_id', $wamid)
            ->first();
        $this->assertNotNull($inboundMsg);
        $this->assertEquals('Hola, quiero información', $inboundMsg->content);

        // Check Bot Outbound Message persisted (the menu with 2 options)
        $botMsg = Message::where('conversation_id', $conversation->id)
            ->where('sender_type', MessageSenderType::BOT)
            ->first();
        $this->assertNotNull($botMsg);
        $this->assertStringContainsString('Por favor elige', $botMsg->content);

        // Verify outgoing call to Meta Graph API intercepted by Http::fake
        Http::assertSent(function (ClientRequest $request) {
            $data = $request->data();

            return str_contains($request->url(), '10987654321/messages')
                && $data['messaging_product'] === 'whatsapp'
                && $data['to'] === '5215512345678'
                && $data['type'] === 'interactive'
                && $data['interactive']['type'] === 'button'
                && count($data['interactive']['action']['buttons']) === 2;
        });
    }

    public function test_post_webhook_idempotency_deduplicates_repeated_wamid(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.123']]], 200),
        ]);

        $this->createStandardMenuFlow();

        $wamid = 'wamid.DUPLICATE_TEST_ID_1122';
        $payload = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'contacts' => [['profile' => ['name' => 'María'], 'wa_id' => '5215599887766']],
                                'messages' => [
                                    [
                                        'from' => '5215599887766',
                                        'id' => $wamid,
                                        'type' => 'text',
                                        'text' => ['body' => 'Mensaje repetido'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $raw = (string) json_encode($payload);
        $sig = $this->computeSignature($raw);

        // First delivery
        $res1 = $this->call('POST', "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $sig,
        ], $raw);
        $res1->assertStatus(200);

        $initialInboundCount = Message::where('external_message_id', $wamid)->count();
        $this->assertEquals(1, $initialInboundCount);

        // Second delivery (simulating Meta webhook retry)
        $res2 = $this->call('POST', "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $sig,
        ], $raw);
        $res2->assertStatus(200);

        // Must still be exactly 1
        $this->assertEquals(1, Message::where('external_message_id', $wamid)->count());
    }

    public function test_post_webhook_interactive_button_reply_advances_menu_node(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.BOT_REPLY']]], 200),
        ]);

        $this->createStandardMenuFlow();

        // 1. Initial message to trigger menu
        $initWamid = 'wamid.INIT_MENU_TRIGGER';
        $initPayload = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'contacts' => [['profile' => ['name' => 'Laura'], 'wa_id' => '5215544332211']],
                                'messages' => [
                                    ['from' => '5215544332211', 'id' => $initWamid, 'type' => 'text', 'text' => ['body' => 'Hola']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $rawInit = (string) json_encode($initPayload);
        $this->call('POST', "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $this->computeSignature($rawInit),
        ], $rawInit)->assertStatus(200);

        // 2. Button Reply selection: 'opt_ventas' / 'Ventas'
        $btnWamid = 'wamid.BTN_REPLY_VENTAS';
        $buttonPayload = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'contacts' => [['profile' => ['name' => 'Laura'], 'wa_id' => '5215544332211']],
                                'messages' => [
                                    [
                                        'from' => '5215544332211',
                                        'id' => $btnWamid,
                                        'type' => 'interactive',
                                        'interactive' => [
                                            'type' => 'button_reply',
                                            'button_reply' => [
                                                'id' => 'opt_ventas',
                                                'title' => 'Ventas',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $rawBtn = (string) json_encode($buttonPayload);
        $resBtn = $this->call('POST', "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $this->computeSignature($rawBtn),
        ], $rawBtn);
        $resBtn->assertStatus(200);

        // Verify conversation advanced to Sales message node
        $salesBotMessage = Message::where('content', 'like', '%Has seleccionado el departamento de Ventas%')->first();
        $this->assertNotNull($salesBotMessage);
    }

    public function test_human_agent_reply_dispatches_to_whatsapp_graph_api_and_internal_notes_are_excluded(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.AGENT_REPLY_1']]], 200),
        ]);

        $contact = Contact::create([
            'display_name' => 'Roberto Gómez',
            'phone' => '5215588776655',
            'external_identifier' => '5215588776655',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $contact->id,
            'status' => 'assigned',
            'assigned_user_id' => $this->adminUser->id,
            'started_at' => now(),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $contact->id,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Hola, necesito cotización',
            'sent_at' => now(),
        ]);

        // 1. Send internal note (should NOT trigger Graph API call)
        $internalRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'content' => 'Nota interna: El cliente necesita cotización urgente',
                'visibility' => 'internal',
            ]);
        $internalRes->assertStatus(201);

        Http::assertNothingSent();

        // 2. Send public message from agent to WhatsApp contact
        $publicRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'content' => 'Hola Roberto, con gusto te apoyo con la cotización.',
                'visibility' => 'public',
            ]);
        $publicRes->assertStatus(201);

        // Verify that Graph API was called exactly once for the public message
        Http::assertSent(function (ClientRequest $request) {
            $data = $request->data();

            return str_contains($request->url(), '10987654321/messages')
                && $data['messaging_product'] === 'whatsapp'
                && $data['to'] === '5215588776655'
                && $data['type'] === 'text'
                && $data['text']['body'] === 'Hola Roberto, con gusto te apoyo con la cotización.';
        });
    }

    public function test_can_create_and_manage_whatsapp_channel_with_masked_secrets(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/channels', [
                'name' => 'Canal Ventas WhatsApp',
                'type' => 'whatsapp',
                'is_active' => true,
                'configuration' => [
                    'phone_number_id' => '123456789',
                    'business_account_id' => '987654321',
                    'access_token' => 'REAL_SECRET_TOKEN_XYZ',
                    'verify_token' => 'SECRET_VERIFY_TOKEN_123',
                    'app_secret' => 'REAL_APP_SECRET_789',
                    'api_version' => 'v21.0',
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Canal Ventas WhatsApp')
            ->assertJsonPath('data.type', 'whatsapp')
            ->assertJsonPath('data.configuration.access_token', '***REDACTED***')
            ->assertJsonPath('data.configuration.has_access_token', true)
            ->assertJsonPath('data.configuration.verify_token', '***REDACTED***')
            ->assertJsonPath('data.configuration.has_verify_token', true)
            ->assertJsonPath('data.configuration.app_secret', '***REDACTED***')
            ->assertJsonPath('data.configuration.has_app_secret', true)
            ->assertJsonPath('data.configuration.phone_number_id', '123456789');

        $channelId = $response->json('data.id');
        $channel = Channel::findOrFail($channelId);

        // Real secrets preserved in DB
        $this->assertEquals('REAL_SECRET_TOKEN_XYZ', $channel->configuration['access_token']);
        $this->assertEquals('REAL_APP_SECRET_789', $channel->configuration['app_secret']);
        $this->assertEquals('SECRET_VERIFY_TOKEN_123', $channel->configuration['verify_token']);

        // Show channel endpoint also masks secrets
        $showRes = $this->actingAs($this->adminUser, 'sanctum')->getJson("/api/v1/channels/{$channelId}");
        $showRes->assertStatus(200)
            ->assertJsonPath('data.configuration.access_token', '***REDACTED***')
            ->assertJsonPath('data.configuration.app_secret', '***REDACTED***')
            ->assertJsonPath('data.configuration.verify_token', '***REDACTED***');
    }

    public function test_channel_update_preserves_secrets_when_masked_or_empty(): void
    {
        $channel = Channel::create([
            'name' => 'Original WA Channel',
            'type' => ChannelType::WHATSAPP,
            'is_active' => true,
            'configuration' => [
                'phone_number_id' => '111111',
                'access_token' => 'INITIAL_SECRET_ACCESS_TOKEN',
                'verify_token' => 'INITIAL_VERIFY_TOKEN',
                'app_secret' => 'INITIAL_APP_SECRET',
                'api_version' => 'v21.0',
            ],
        ]);

        // Update name and phone_number_id, passing masked tokens back (as frontend does if untouched)
        $updateRes = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson("/api/v1/channels/{$channel->id}", [
                'name' => 'Updated WA Channel',
                'configuration' => [
                    'phone_number_id' => '222222',
                    'access_token' => '***REDACTED***',
                    'verify_token' => '',
                    'app_secret' => '[REDACTED]',
                    'api_version' => 'v21.0',
                ],
            ]);

        $updateRes->assertStatus(200);

        $channel->refresh();
        $this->assertEquals('Updated WA Channel', $channel->name);
        $this->assertEquals('222222', $channel->configuration['phone_number_id']);
        // Original secrets preserved
        $this->assertEquals('INITIAL_SECRET_ACCESS_TOKEN', $channel->configuration['access_token']);
        $this->assertEquals('INITIAL_VERIFY_TOKEN', $channel->configuration['verify_token']);
        $this->assertEquals('INITIAL_APP_SECRET', $channel->configuration['app_secret']);
    }

    public function test_audit_logger_redacts_whatsapp_secrets(): void
    {
        $raw = [
            'name' => 'WhatsApp',
            'access_token' => 'secret_access_token',
            'verify_token' => 'secret_verify_token',
            'app_secret' => 'secret_app_secret',
            'phone_number_id' => '123456',
        ];

        $sanitized = AuditLogger::sanitize($raw);

        $this->assertEquals('[REDACTED]', $sanitized['access_token']);
        $this->assertEquals('[REDACTED]', $sanitized['verify_token']);
        $this->assertEquals('[REDACTED]', $sanitized['app_secret']);
        $this->assertEquals('123456', $sanitized['phone_number_id']);
    }

    public function test_post_webhook_inbound_image_downloads_and_persists_attachment_url(): void
    {
        Storage::fake('public');

        $fakeImageBinary = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
        $mediaId = 'meta_img_wh_test_777';
        $metaDownloadUrl = 'https://lookaside.fbsbx.com/whatsapp_business/attachments/wh_img_777';

        Http::fake([
            "https://graph.facebook.com/v21.0/{$mediaId}" => Http::response([
                'url' => $metaDownloadUrl,
                'mime_type' => 'image/jpeg',
                'file_size' => strlen($fakeImageBinary),
                'id' => $mediaId,
            ], 200),
            $metaDownloadUrl => Http::response($fakeImageBinary, 200, ['Content-Type' => 'image/jpeg']),
            'https://graph.facebook.com/v21.0/10987654321/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.BOT_REPLY_FOR_IMAGE']],
            ], 200),
        ]);

        $this->createStandardMenuFlow();

        $wamid = 'wamid.INBOUND_IMAGE_TEST_777';
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => '9988776655',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '15550254444',
                                    'phone_number_id' => '10987654321',
                                ],
                                'contacts' => [
                                    ['profile' => ['name' => 'Ana Compradora'], 'wa_id' => '50688889999'],
                                ],
                                'messages' => [
                                    [
                                        'from' => '50688889999',
                                        'id' => $wamid,
                                        'timestamp' => (string) time(),
                                        'type' => 'image',
                                        'image' => [
                                            'id' => $mediaId,
                                            'mime_type' => 'image/jpeg',
                                            'caption' => 'Mira esta foto',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $rawBody = (string) json_encode($payload);
        $signature = $this->computeSignature($rawBody);

        $res = $this->call('POST', "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $rawBody);

        $res->assertStatus(200);

        $msg = Message::where('external_message_id', $wamid)->first();
        $this->assertNotNull($msg);
        $this->assertEquals(MessageType::IMAGE, $msg->message_type);
        $this->assertNotNull($msg->payload['attachment'] ?? null);
        $this->assertEquals($mediaId, $msg->payload['attachment']['media_id']);
        $this->assertNotEmpty($msg->payload['attachment']['url']);
        $this->assertStringContainsString('attachments/whatsapp/', $msg->payload['attachment']['url']);
        $this->assertGreaterThan(0, $msg->payload['attachment']['size_bytes']);

        $diskPath = $msg->payload['attachment']['disk_path'] ?? null;
        $this->assertNotNull($diskPath);
        Storage::disk('public')->assertExists($diskPath);
    }

    public function test_post_webhook_statuses_updates_delivery_status_without_triggering_bot_flow(): void
    {
        $this->createStandardMenuFlow();

        $contact = Contact::create([
            'first_name' => 'Carlos',
            'phone' => '50688889999',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $contact->id,
            'status' => 'bot',
        ]);

        $wamid = 'wamid.OUTBOUND_TO_TRACK_STATUS_888';
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'status' => MessageStatus::SENT,
            'external_message_id' => $wamid,
            'content' => 'Mensaje para probar status sin trigger bot',
            'sent_at' => now(),
        ]);

        // 1. Send status 'delivered'
        $payloadDelivered = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => '9988776655',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'statuses' => [
                                    [
                                        'id' => $wamid,
                                        'status' => 'delivered',
                                        'timestamp' => (string) time(),
                                        'recipient_id' => '50688889999',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $rawDelivered = (string) json_encode($payloadDelivered);
        $sigDelivered = $this->computeSignature($rawDelivered);

        $res1 = $this->call('POST', "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $sigDelivered,
        ], $rawDelivered);

        $res1->assertStatus(200);

        $message->refresh();
        $this->assertEquals(MessageStatus::DELIVERED, $message->status);
        $this->assertEquals('delivered', $message->metadata['delivery_status']);
        // Verify no bot message created
        $this->assertEquals(1, Message::where('conversation_id', $conversation->id)->count());

        // 2. Send status 'read'
        $payloadRead = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => '9988776655',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'statuses' => [
                                    [
                                        'id' => $wamid,
                                        'status' => 'read',
                                        'timestamp' => (string) (time() + 10),
                                        'recipient_id' => '50688889999',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $rawRead = (string) json_encode($payloadRead);
        $sigRead = $this->computeSignature($rawRead);

        $res2 = $this->call('POST', "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $sigRead,
        ], $rawRead);

        $res2->assertStatus(200);

        $message->refresh();
        $this->assertEquals(MessageStatus::READ, $message->status);
        $this->assertEquals('read', $message->metadata['delivery_status']);
        $this->assertEquals(1, Message::where('conversation_id', $conversation->id)->count());

        // 3. Late delivered should not overwrite read
        $res3 = $this->call('POST', "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $sigDelivered,
        ], $rawDelivered);

        $res3->assertStatus(200);
        $message->refresh();
        $this->assertEquals(MessageStatus::READ, $message->status);
        $this->assertEquals('read', $message->metadata['delivery_status']);
        $this->assertEquals(1, Message::where('conversation_id', $conversation->id)->count());
    }

    public function test_failed_delivery_logs_privacy_safe_metadata(): void
    {
        Log::spy();

        $contact = Contact::create([
            'first_name' => 'Carlos',
            'phone' => '50688889999',
        ]);
        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $contact->id,
            'status' => 'bot',
        ]);
        $wamid = 'wamid.TEST_PRIVACY_1';
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'status' => MessageStatus::SENT,
            'external_message_id' => $wamid,
            'content' => 'Contenido privado',
            'sent_at' => now(),
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '9988776655',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'statuses' => [[
                            'id' => $wamid,
                            'status' => 'failed',
                            'timestamp' => (string) time(),
                            'recipient_id' => '50688889999',
                            'errors' => [[
                                'code' => 131047,
                                'title' => 'Delivery failed for 50688889999',
                                'message' => 'Delivery failed',
                                'error_data' => ['details' => 'The message could not be delivered'],
                            ]],
                        ]],
                    ],
                ]],
            ]],
        ];
        $raw = (string) json_encode($payload);
        $response = $this->call('POST', "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $this->computeSignature($raw),
        ], $raw);

        $response->assertStatus(200);
        $message->refresh();
        $this->assertSame('Delivery failed for [REDACTED_PHONE]', $message->metadata['delivery_error_title']);

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $event, array $context) use ($message, $wamid): bool {
            return $event === 'whatsapp.delivery.failed'
                && $context['message_id'] === $message->id
                && $context['external_message_id_hash'] === substr(hash('sha256', $wamid), 0, 16)
                && $context['error_title'] === 'Delivery failed for [REDACTED_PHONE]'
                && ! array_key_exists('content', $context)
                && ! array_key_exists('phone', $context)
                && ! in_array($wamid, $context, true);
        });
    }

    public function test_post_webhook_bypasses_csrf_verification_for_stateful_origin(): void
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [],
        ];

        $rawBody = (string) json_encode($payload);
        $signature = $this->computeSignature($rawBody);

        // Send request simulating a stateful frontend proxy with Referer and Origin set
        $response = $this->call('POST', "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'HTTP_REFERER' => 'http://localhost:3000/',
            'HTTP_ORIGIN' => 'http://localhost:3000',
        ], $rawBody);

        // Must not return 419 (CSRF Token Mismatch)
        $this->assertNotEquals(419, $response->getStatusCode());
        $response->assertStatus(200);
    }
}
