<?php

namespace Tests\Feature;

use App\Casts\EncryptedChannelConfiguration;
use App\Enums\ChannelType;
use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\MessageCreated;
use App\Jobs\ProcessWhatsAppMediaAttachmentJob;
use App\Listeners\DeliverOutboundChannelMessage;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Services\Runtime\Channels\Adapters\WhatsAppChannelAdapter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductionRemediationAuditTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $agentUser;
    protected Channel $waChannel;
    protected Contact $contact;
    protected Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::create([
            'name' => 'Admin Remediation',
            'email' => 'admin_dr@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
        ]);
        $this->adminUser->roles()->attach(Role::where('name', 'super_admin')->first());

        $this->agentUser = User::create([
            'name' => 'Agent Remediation',
            'email' => 'agent_dr@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
        ]);
        $this->agentUser->roles()->attach(Role::where('name', 'agent')->first());

        $this->waChannel = Channel::create([
            'name' => 'WhatsApp Remediation Channel',
            'type' => ChannelType::WHATSAPP,
            'is_active' => true,
            'configuration' => [
                'phone_number_id' => '10987654321',
                'business_account_id' => '9988776655',
                'access_token' => 'SECRET_META_TOKEN_12345',
                'verify_token' => 'VERIFY_TOKEN_XYZ',
                'app_secret' => 'APP_SECRET_ABC',
                'api_version' => 'v21.0',
            ],
        ]);

        $this->contact = Contact::create([
            'channel_id' => $this->waChannel->id,
            'identifier' => '5215599887766',
            'name' => 'Cliente Auditoría',
            'first_name' => 'Cliente',
            'last_name' => 'Auditoría',
            'phone' => '+5215599887766',
        ]);

        $this->conversation = Conversation::create([
            'channel_id' => $this->waChannel->id,
            'contact_id' => $this->contact->id,
            'assigned_user_id' => $this->agentUser->id,
            'status' => 'assigned',
            'bot_enabled' => false,
            'started_at' => now()->subHour(),
            'last_message_at' => now()->subMinutes(5),
        ]);

        // Inbound message to open 24h window
        Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'direction' => MessageDirection::INBOUND,
            'content' => 'Hola soporte',
            'type' => MessageType::TEXT,
            'status' => MessageStatus::DELIVERED,
            'sent_at' => now()->subMinutes(5),
        ]);
    }

    /**
     * Finding 1: Ensure a single dispatch route for manual agent responses to WhatsApp.
     * Prevents double-send between ConversationController and DeliverOutboundChannelMessage.
     */
    public function test_manual_agent_reply_dispatches_exactly_one_outbound_whatsapp_call(): void
    {
        Sanctum::actingAs($this->agentUser);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '5215599887766', 'wa_id' => '5215599887766']],
                'messages' => [['id' => 'wamid.HBgLMTIzNDU2Nzg5MA==']],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/conversations/{$this->conversation->id}/messages", [
            'content' => 'Hola, con gusto te ayudo con tu consulta.',
        ]);

        $response->assertStatus(201);
        $messageData = $response->json('data');

        // Exactly one HTTP request to Meta Graph API must be sent
        Http::assertSentCount(1);
        Http::assertSent(function (ClientRequest $request) {
            return str_contains($request->url(), '10987654321/messages') &&
                   $request['to'] === '5215599887766' &&
                   $request['text']['body'] === 'Hola, con gusto te ayudo con tu consulta.';
        });

        // The stored message must have the wamid recorded in metadata
        $savedMessage = Message::find($messageData['id']);
        $this->assertNotNull($savedMessage);
        $this->assertEquals('wamid.HBgLMTIzNDU2Nzg5MA==', $savedMessage->metadata['wamid'] ?? null);

        // Calling the event listener directly on the saved message must be idempotent and not send a second HTTP call
        $listener = app(DeliverOutboundChannelMessage::class);
        $listener->handle(new MessageCreated($savedMessage));

        // Still exactly one HTTP call was made
        Http::assertSentCount(1);
    }

    /**
     * Finding 2: Channel applicability on flow creation, update, and channel assignment.
     */
    public function test_channel_flow_compatibility_validation_and_rejection(): void
    {
        Sanctum::actingAs($this->adminUser);

        // Create a flow applicable only to webchat
        $webchatOnlyFlow = Flow::create([
            'name' => 'Flujo Webchat Exclusivo',
            'status' => FlowStatus::PUBLISHED,
            'channel_applicability' => ['webchat'],
        ]);
        FlowVersion::create([
            'flow_id' => $webchatOnlyFlow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::PUBLISHED,
            'published_at' => now(),
        ]);
        $webchatOnlyFlow->update(['active_version_number' => 1]);

        // Attempting to assign webchat-only flow as entry_flow_id of WhatsApp channel must fail with 422
        $resp = $this->putJson("/api/v1/channels/{$this->waChannel->id}", [
            'name' => 'WhatsApp Remediation Channel',
            'is_active' => true,
            'entry_flow_id' => $webchatOnlyFlow->id,
        ]);
        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors(['entry_flow_id']);

        // Create a multichannel flow (webchat + whatsapp)
        $multiFlow = Flow::create([
            'name' => 'Flujo Multicanal Válido',
            'status' => FlowStatus::PUBLISHED,
            'channel_applicability' => ['webchat', 'whatsapp'],
        ]);
        FlowVersion::create([
            'flow_id' => $multiFlow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::PUBLISHED,
            'published_at' => now(),
        ]);
        $multiFlow->update(['active_version_number' => 1]);

        // Assigning multichannel flow to WhatsApp channel must succeed
        $resp2 = $this->putJson("/api/v1/channels/{$this->waChannel->id}", [
            'name' => 'WhatsApp Remediation Channel',
            'is_active' => true,
            'entry_flow_id' => $multiFlow->id,
        ]);
        $resp2->assertStatus(200);
        $this->assertEquals($multiFlow->id, $this->waChannel->fresh()->entry_flow_id);

        // Attempting to remove 'whatsapp' from the flow while actively assigned must fail with 422
        $resp3 = $this->putJson("/api/v1/flows/{$multiFlow->id}", [
            'name' => 'Flujo Multicanal Válido',
            'channel_applicability' => ['webchat'],
        ]);
        $resp3->assertStatus(422);
        $resp3->assertJsonValidationErrors(['channel_applicability']);
    }

    /**
     * Finding 3: Webhook responds fast without downloading media synchronously;
     * ProcessWhatsAppMediaAttachmentJob processes media in background idempotently.
     */
    public function test_webhook_receives_media_without_sync_download_and_processes_in_job(): void
    {
        Storage::fake('public');
        Queue::fake([ProcessWhatsAppMediaAttachmentJob::class]);

        $mediaPayload = [
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
                                    'display_phone_number' => '10987654321',
                                    'phone_number_id' => '10987654321',
                                ],
                                'contacts' => [
                                    [
                                        'profile' => ['name' => 'Cliente Auditoría'],
                                        'wa_id' => '5215599887766',
                                    ],
                                ],
                                'messages' => [
                                    [
                                        'from' => '5215599887766',
                                        'id' => 'wamid.MEDIA_TEST_MSG_001',
                                        'timestamp' => (string) now()->timestamp,
                                        'type' => 'image',
                                        'image' => [
                                            'id' => 'meta_media_id_99999',
                                            'mime_type' => 'image/jpeg',
                                            'sha256' => 'sample_sha256',
                                            'caption' => 'Foto de comprobante',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $rawBody = (string) json_encode($mediaPayload);
        $signature = 'sha256=' . hash_hmac('sha256', $rawBody, 'APP_SECRET_ABC');

        $response = $this->call('POST', "/api/v1/public/webhooks/whatsapp/{$this->waChannel->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $rawBody);

        $response->assertStatus(200);

        // Verify message was created with type=image
        $savedMessage = Message::where('metadata->whatsapp_message_id', 'wamid.MEDIA_TEST_MSG_001')->first();
        $this->assertNotNull($savedMessage);
        $this->assertEquals(MessageType::IMAGE, $savedMessage->message_type);

        // Verify attachment was created in pending status
        $attachment = $savedMessage->payload['attachment'] ?? $savedMessage->metadata['attachment'] ?? null;
        $this->assertNotNull($attachment);
        $this->assertEquals('pending_download', $attachment['status'] ?? null);
        $this->assertEquals('meta_media_id_99999', $attachment['media_id'] ?? null);

        // Verify background job was pushed to queue
        Queue::assertPushed(ProcessWhatsAppMediaAttachmentJob::class, function ($job) use ($savedMessage) {
            return $job->messageId === $savedMessage->id;
        });

        // Now test the background job execution directly
        Http::fake([
            'https://graph.facebook.com/v21.0/meta_media_id_99999*' => Http::response([
                'url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=99999',
                'mime_type' => 'image/jpeg',
                'sha256' => 'sample_sha256',
                'file_size' => 1024,
                'id' => 'meta_media_id_99999',
            ], 200),
            'https://lookaside.fbsbx.com/*' => Http::response('FAKE_IMAGE_BINARY_CONTENT', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $job = new ProcessWhatsAppMediaAttachmentJob($savedMessage->id, $this->waChannel->id);
        $job->handle(app(\App\Services\Media\WhatsAppMediaService::class));

        $savedMessage->refresh();
        $updatedAtt = $savedMessage->payload['attachment'] ?? $savedMessage->metadata['attachment'] ?? [];
        $this->assertEquals('ready', $updatedAtt['status'] ?? null);
        $this->assertNotEmpty($updatedAtt['disk_path'] ?? null);
        $this->assertNotEmpty($updatedAtt['url'] ?? null);
        Storage::disk('public')->assertExists($updatedAtt['disk_path']);

        // Re-executing the job should be an idempotent no-op without re-downloading
        $diskPathBefore = $updatedAtt['disk_path'];
        $job->handle(app(\App\Services\Media\WhatsAppMediaService::class));
        $savedMessage->refresh();
        $retestedAtt = $savedMessage->payload['attachment'] ?? $savedMessage->metadata['attachment'] ?? [];
        $this->assertEquals($diskPathBefore, $retestedAtt['disk_path']);
    }

    /**
     * Finding 5: WhatsApp 24-hour service window enforcement and approved template replies.
     */
    public function test_whatsapp_24h_window_blocks_freeform_and_permits_approved_templates(): void
    {
        Sanctum::actingAs($this->agentUser);

        // Expire the 24h window by moving the last inbound message to 25 hours ago
        Message::where('conversation_id', $this->conversation->id)
            ->where('direction', MessageDirection::INBOUND)
            ->update(['sent_at' => now()->subHours(25)]);

        // 1. Sending normal text message must be blocked with 422
        $failResp = $this->postJson("/api/v1/conversations/{$this->conversation->id}/messages", [
            'content' => 'Intento de mensaje fuera de ventana',
        ]);
        $failResp->assertStatus(422);
        $failResp->assertJsonPath('error_code', 'WHATSAPP_24H_WINDOW_EXPIRED');

        // 2. Fetch approved templates list from Meta
        Http::fake([
            'https://graph.facebook.com/v21.0/9988776655/message_templates*' => Http::response([
                'data' => [
                    [
                        'name' => 'seguimiento_consulta_soporte',
                        'status' => 'APPROVED',
                        'category' => 'UTILITY',
                        'language' => 'es',
                        'components' => [
                            ['type' => 'BODY', 'text' => 'Hola {{1}}, consulta soporte.'],
                        ],
                    ],
                ],
            ], 200),
            'https://graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '5215599887766', 'wa_id' => '5215599887766']],
                'messages' => [['id' => 'wamid.TEMPLATE_DISPATCH_SUCCESS']],
            ], 200),
        ]);

        $templatesResp = $this->getJson("/api/v1/channels/{$this->waChannel->id}/whatsapp-templates");
        $templatesResp->assertStatus(200);
        $templatesResp->assertJsonStructure(['data' => [['name', 'category', 'language', 'status']]]);
        $this->assertEquals('seguimiento_consulta_soporte', $templatesResp->json('data.0.name'));
        $this->assertEquals('APPROVED', $templatesResp->json('data.0.status'));

        // 3. Sending approved template must succeed even when 24h window is expired

        $successResp = $this->postJson("/api/v1/conversations/{$this->conversation->id}/messages", [
            'message_type' => 'template',
            'template' => [
                'name' => 'seguimiento_consulta_soporte',
                'language' => ['code' => 'es'],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => 'Cliente Auditoría'],
                        ],
                    ],
                ],
            ],
        ]);

        $successResp->assertStatus(201);
        Http::assertSent(function (ClientRequest $request) {
            return str_contains($request->url(), '10987654321/messages') &&
                   $request['type'] === 'template' &&
                   $request['template']['name'] === 'seguimiento_consulta_soporte' &&
                   $request['template']['language']['code'] === 'es' &&
                   $request['template']['components'][0]['type'] === 'body';
        });
    }

    /**
     * Finding 6: Encryption of secrets at rest and redaction in toArray/logs.
     */
    public function test_channel_secrets_are_encrypted_at_rest_and_redacted_in_array(): void
    {
        $rawConfig = DB::table('channels')->where('id', $this->waChannel->id)->value('configuration');
        $this->assertIsString($rawConfig);

        // Raw database string must NOT contain the plaintext access token, app secret, or verify token
        $this->assertStringNotContainsString('SECRET_META_TOKEN_12345', $rawConfig);
        $this->assertStringNotContainsString('VERIFY_TOKEN_XYZ', $rawConfig);
        $this->assertStringNotContainsString('APP_SECRET_ABC', $rawConfig);

        // Re-reading via Eloquent model must transparently decrypt them
        $freshChannel = Channel::find($this->waChannel->id);
        $this->assertEquals('SECRET_META_TOKEN_12345', $freshChannel->configuration['access_token']);
        $this->assertEquals('VERIFY_TOKEN_XYZ', $freshChannel->configuration['verify_token']);
        $this->assertEquals('APP_SECRET_ABC', $freshChannel->configuration['app_secret']);

        // toArray() must mask sensitive secrets
        $arrayData = $freshChannel->toArray();
        $this->assertEquals('***REDACTED***', $arrayData['configuration']['access_token']);
        $this->assertEquals('***REDACTED***', $arrayData['configuration']['verify_token']);
        $this->assertEquals('***REDACTED***', $arrayData['configuration']['app_secret']);
        $this->assertTrue($arrayData['configuration']['has_access_token']);
    }

    /**
     * Finding 8: APP_URL outgoing media public accessibility check in health.
     */
    public function test_health_check_validates_media_url_configuration(): void
    {
        // In local/testing environment, media_url returns a valid string (e.g. 'local_dev' or 'ok')
        $resp = $this->getJson('/health/ready');
        $resp->assertStatus(200);
        $this->assertNotNull($resp->json('checks.media_url'));

        // Test adapter's validateOutboundMediaUrl: must warn without leaking local host, port, or path
        $loggedWarnings = [];
        Log::shouldReceive('warning')->andReturnUsing(function ($msg) use (&$loggedWarnings) {
            $loggedWarnings[] = (string) $msg;
        });

        $adapter = app(WhatsAppChannelAdapter::class);
        $localResult = $adapter->validateOutboundMediaUrl('http://localhost:8000/storage/media.png');
        $this->assertTrue($localResult['valid']);
        $this->assertFalse($localResult['is_public_https']);

        $this->assertNotEmpty($loggedWarnings);
        foreach ($loggedWarnings as $warning) {
            $this->assertStringNotContainsString('localhost', $warning);
            $this->assertStringNotContainsString('8000', $warning);
            $this->assertStringNotContainsString('/storage/media.png', $warning);
            $this->assertStringContainsString('[REDACTED_MEDIA_URL]', $warning);
        }

        $httpsResult = $adapter->validateOutboundMediaUrl('https://api.conexa.ai/storage/media.png');
        $this->assertTrue($httpsResult['valid']);
        $this->assertTrue($httpsResult['is_public_https']);
    }

    /**
     * Server-side critical defense: message_type=template requires exact approved name and language match.
     */
    public function test_server_side_template_validation_strictly_enforces_approved_match_and_rejects_unapproved_or_graph_failure(): void
    {
        Sanctum::actingAs($this->agentUser);

        $graphFails = false;
        Http::fake(function (ClientRequest $request) use (&$graphFails) {
            if (str_contains($request->url(), 'message_templates')) {
                if ($graphFails) {
                    return Http::response(['error' => 'Graph API down'], 500);
                }

                return Http::response([
                    'data' => [
                        [
                            'name' => 'aviso_pago',
                            'status' => 'APPROVED',
                            'category' => 'UTILITY',
                            'language' => 'es',
                            'components' => [
                                ['type' => 'BODY', 'text' => 'Su pago vence pronto.'],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.TEMPLATE_OK_123']],
            ], 200);
        });

        $initialMessageCount = Message::count();

        // 1a. Sending unapproved template name -> must return 422 and NOT create message
        $respUnknown = $this->postJson("/api/v1/conversations/{$this->conversation->id}/messages", [
            'message_type' => 'template',
            'template' => [
                'name' => 'plantilla_fantasma_no_aprobada',
                'language' => ['code' => 'es'],
            ],
        ]);
        $respUnknown->assertStatus(422);
        $respUnknown->assertJsonPath('error_code', 'TEMPLATE_NOT_APPROVED');
        $this->assertEquals($initialMessageCount, Message::count(), 'No message should be created in DB on unapproved template');

        // 1b. Sending approved template name but mismatched language ('en' instead of 'es') -> must return 422 and NOT create message
        $respWrongLang = $this->postJson("/api/v1/conversations/{$this->conversation->id}/messages", [
            'message_type' => 'template',
            'template' => [
                'name' => 'aviso_pago',
                'language' => ['code' => 'en'],
            ],
        ]);
        $respWrongLang->assertStatus(422);
        $respWrongLang->assertJsonPath('error_code', 'TEMPLATE_NOT_APPROVED');
        $this->assertEquals($initialMessageCount, Message::count(), 'No message should be created in DB on mismatched language');

        // 1c. Case when Graph API fails or returns HTTP 500 -> must return 422 and NOT create message
        $graphFails = true;
        $respGraphDown = $this->postJson("/api/v1/conversations/{$this->conversation->id}/messages", [
            'message_type' => 'template',
            'template' => [
                'name' => 'aviso_pago',
                'language' => ['code' => 'es'],
            ],
        ]);
        $respGraphDown->assertStatus(422);
        $respGraphDown->assertJsonPath('error_code', 'TEMPLATE_NOT_APPROVED');
        $this->assertEquals($initialMessageCount, Message::count(), 'No message should be created in DB when Graph fails');

        // 1d. Matching template name and language with Graph 200 -> must succeed with 201 and create message
        $graphFails = false;
        $respOk = $this->postJson("/api/v1/conversations/{$this->conversation->id}/messages", [
            'message_type' => 'template',
            'template' => [
                'name' => 'aviso_pago',
                'language' => ['code' => 'es'],
            ],
        ]);
        $respOk->assertStatus(201);
        $this->assertEquals($initialMessageCount + 1, Message::count(), 'Message should be created in DB only on exact approved match');
    }

    /**
     * Privacy & Security: Worker/job sanitizes exceptions and never logs sensitive URLs, hosts, paths, phones, signatures or tokens.
     */
    public function test_worker_job_sanitizes_exceptions_and_never_logs_sensitive_urls_or_tokens(): void
    {
        $sensitiveHost = 'lookaside.fbsbx.com';
        $sensitivePath = '/whatsapp_business/attachments/';
        $sensitivePhone = '+5215599887766';
        $sensitiveSignature = 'TOP_SECRET_SIG_777';
        $sensitiveToken = 'TOP_SECRET_TOKEN_888';
        $sensitiveMetaToken = 'EAAG123456789_SUPER_SECRET';

        // 1. Verify static sanitizer completely strips full URL (including host/path), phone, and sensitive tokens
        $rawErrorText = "Connection error for https://{$sensitiveHost}{$sensitivePath}?mid=99999&sig={$sensitiveSignature}&oh={$sensitiveToken}&access_token={$sensitiveMetaToken} with phone {$sensitivePhone}";
        $sanitized = WhatsAppChannelAdapter::sanitizeErrorMessage($rawErrorText);
        $this->assertStringNotContainsString($sensitiveHost, $sanitized);
        $this->assertStringNotContainsString($sensitivePath, $sanitized);
        $this->assertStringNotContainsString($sensitivePhone, $sanitized);
        $this->assertStringNotContainsString('5215599887766', $sanitized);
        $this->assertStringNotContainsString($sensitiveSignature, $sanitized);
        $this->assertStringNotContainsString($sensitiveToken, $sanitized);
        $this->assertStringNotContainsString($sensitiveMetaToken, $sanitized);
        $this->assertStringNotContainsString('mid=99999', $sanitized);
        $this->assertStringContainsString('[REDACTED_URL]', $sanitized);
        $this->assertStringContainsString('[REDACTED_PHONE]', $sanitized);

        // 2. Verify worker job execution with Log::spy()
        Log::spy();

        $mediaServiceMock = $this->createMock(\App\Services\Media\WhatsAppMediaService::class);
        $mediaServiceMock->method('downloadAndStoreMedia')
            ->willThrowException(new \RuntimeException($rawErrorText));

        $msg = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $this->contact->id,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::IMAGE,
            'visibility' => 'public',
            'content' => 'Test sensitive exception redaction',
            'payload' => [
                'attachment' => [
                    'media_id' => 'sensitive_media_99999',
                    'file_name' => 'image_99999.jpg',
                    'status' => 'pending_download',
                ],
            ],
            'sent_at' => now(),
        ]);

        $job = new ProcessWhatsAppMediaAttachmentJob($msg->id, $this->waChannel->id);

        $caughtException = null;
        try {
            $job->handle($mediaServiceMock);
        } catch (\Throwable $e) {
            $caughtException = $e;
        }

        $this->assertNotNull($caughtException, 'Job should throw an exception to signal retry to queue worker');

        // Verify the thrown exception (written to worker logs and failed_jobs) does NOT leak host, path, phone, tokens, or signatures
        $this->assertStringNotContainsString($sensitiveHost, $caughtException->getMessage());
        $this->assertStringNotContainsString($sensitivePath, $caughtException->getMessage());
        $this->assertStringNotContainsString($sensitivePhone, $caughtException->getMessage());
        $this->assertStringNotContainsString('5215599887766', $caughtException->getMessage());
        $this->assertStringNotContainsString($sensitiveSignature, $caughtException->getMessage());
        $this->assertStringNotContainsString($sensitiveToken, $caughtException->getMessage());
        $this->assertStringNotContainsString($sensitiveMetaToken, $caughtException->getMessage());
        $this->assertStringContainsString('[REDACTED_URL]', $caughtException->getMessage());

        // Verify that Log recorded the sanitized warning without leaking host, path, phone, tokens
        Log::shouldHaveReceived('warning')
            ->atLeast()->once()
            ->withArgs(function ($message) use ($sensitiveHost, $sensitivePath, $sensitivePhone, $sensitiveSignature, $sensitiveToken, $sensitiveMetaToken) {
                return ! str_contains($message, $sensitiveHost)
                    && ! str_contains($message, $sensitivePath)
                    && ! str_contains($message, $sensitivePhone)
                    && ! str_contains($message, '5215599887766')
                    && ! str_contains($message, $sensitiveSignature)
                    && ! str_contains($message, $sensitiveToken)
                    && ! str_contains($message, $sensitiveMetaToken)
                    && str_contains($message, '[REDACTED_URL]');
            });
    }

    /**
     * Privacy & Security: DeliverOutboundChannelMessage sanitizes delivery_error persisted in metadata.
     */
    public function test_deliver_outbound_channel_message_sanitizes_delivery_error_persisted_in_metadata(): void
    {
        $sensitiveHost = 'graph.facebook.com';
        $sensitivePath = '/v21.0/messages';
        $sensitivePhone = '+5215599887766';
        $sensitiveSig = 'TOP_SECRET_OUTBOUND_SIG_999';
        $sensitiveTok = 'TOP_SECRET_OUTBOUND_TOK_111';
        $sensitiveMeta = 'EAAGOUTBOUND_SECRET_TOKEN_222';

        $outboundMsg = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'sender_id' => (string) $this->agentUser->id,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'visibility' => 'public',
            'status' => MessageStatus::PENDING,
            'content' => 'Prueba error saliente',
            'sent_at' => now(),
        ]);

        // Case A: Adapter returns error structure with sensitive message
        $adapterMock = $this->createMock(WhatsAppChannelAdapter::class);
        $adapterMock->method('sendOutbound')->willReturn([
            'error' => [
                'message' => "OAuthException: Failed for https://{$sensitiveHost}{$sensitivePath}?sig={$sensitiveSig}&token={$sensitiveTok}&access_token={$sensitiveMeta} recipient {$sensitivePhone}",
            ],
        ]);

        $registryMock = $this->createMock(\App\Services\Runtime\Channels\ChannelAdapterRegistry::class);
        $registryMock->method('forChannel')->willReturn($adapterMock);

        $listener = new DeliverOutboundChannelMessage($registryMock);
        $listener->handle(new MessageCreated($outboundMsg));

        $freshMsg = $outboundMsg->fresh();
        $this->assertEquals('failed', $freshMsg->metadata['delivery_status']);
        $deliveryError = $freshMsg->metadata['delivery_error'];
        $this->assertNotEmpty($deliveryError);
        $this->assertStringNotContainsString($sensitiveHost, $deliveryError);
        $this->assertStringNotContainsString($sensitivePath, $deliveryError);
        $this->assertStringNotContainsString($sensitivePhone, $deliveryError);
        $this->assertStringNotContainsString('5215599887766', $deliveryError);
        $this->assertStringNotContainsString($sensitiveSig, $deliveryError);
        $this->assertStringNotContainsString($sensitiveTok, $deliveryError);
        $this->assertStringNotContainsString($sensitiveMeta, $deliveryError);
        $this->assertStringContainsString('[REDACTED_URL]', $deliveryError);
        $this->assertStringContainsString('[REDACTED_PHONE]', $deliveryError);

        // Case B: Exception thrown during dispatch
        $meta = $freshMsg->metadata;
        unset($meta['delivery_status'], $meta['delivery_error']);
        $outboundMsg->update(['metadata' => $meta]);

        $adapterMockThrow = $this->createMock(WhatsAppChannelAdapter::class);
        $adapterMockThrow->method('sendOutbound')->willThrowException(
            new \RuntimeException("Guzzle HTTP failure at https://lookaside.fbsbx.com/test?mid=333&sig={$sensitiveSig}&token={$sensitiveTok}&access_token={$sensitiveMeta} for {$sensitivePhone}")
        );
        $registryMockThrow = $this->createMock(\App\Services\Runtime\Channels\ChannelAdapterRegistry::class);
        $registryMockThrow->method('forChannel')->willReturn($adapterMockThrow);

        $listenerThrow = new DeliverOutboundChannelMessage($registryMockThrow);
        $listenerThrow->handle(new MessageCreated($outboundMsg));

        $freshMsg2 = $outboundMsg->fresh();
        $this->assertEquals('failed', $freshMsg2->metadata['delivery_status']);
        $deliveryError2 = $freshMsg2->metadata['delivery_error'];
        $this->assertNotEmpty($deliveryError2);
        $this->assertStringNotContainsString('lookaside.fbsbx.com', $deliveryError2);
        $this->assertStringNotContainsString('/test', $deliveryError2);
        $this->assertStringNotContainsString($sensitivePhone, $deliveryError2);
        $this->assertStringNotContainsString('5215599887766', $deliveryError2);
        $this->assertStringNotContainsString($sensitiveSig, $deliveryError2);
        $this->assertStringNotContainsString($sensitiveTok, $deliveryError2);
        $this->assertStringNotContainsString($sensitiveMeta, $deliveryError2);
        $this->assertStringContainsString('[REDACTED_URL]', $deliveryError2);
        $this->assertStringContainsString('[REDACTED_PHONE]', $deliveryError2);
    }
}
