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
use App\Jobs\ProcessWhatsAppMediaAttachmentJob;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $agentUser;

    protected Channel $webchatChannel;

    protected Channel $whatsappChannel;

    protected WebchatChannelSettings $webchatSettings;

    protected string $appSecret = 'meta_test_secret_for_media_test';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);

        $this->agentUser = User::factory()->create();
        $this->agentUser->roles()->attach(Role::where('name', 'super_admin')->first());

        // 1. Flow & Webchat Channel
        $flowVersion = $this->createStandardFlow();
        $this->webchatChannel = Channel::create([
            'name' => 'Webchat Multimedia Test',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
            'entry_flow_id' => $flowVersion->flow_id,
        ]);

        $this->webchatSettings = WebchatChannelSettings::create([
            'channel_id' => $this->webchatChannel->id,
            'public_key' => 'pk_live_'.Str::random(24),
            'allowed_origins' => ['http://localhost:3000'],
            'display_name' => 'Soporte Multimedia',
            'accent_color' => '#2563EB',
            'launcher_position' => 'bottom-right',
        ]);

        // 2. WhatsApp Channel
        $this->whatsappChannel = Channel::create([
            'name' => 'WhatsApp Multimedia Test',
            'type' => ChannelType::WHATSAPP,
            'is_active' => true,
            'configuration' => [
                'phone_number_id' => '10987654321',
                'business_account_id' => '9988776655',
                'access_token' => 'EAAG_MEDIA_TEST_ACCESS_TOKEN',
                'verify_token' => 'media_verify_token_123',
                'app_secret' => $this->appSecret,
                'api_version' => 'v21.0',
            ],
        ]);
    }

    protected function createStandardFlow(): FlowVersion
    {
        $flow = Flow::create([
            'name' => 'Flujo Multimedia Test',
            'status' => FlowStatus::DRAFT,
            'channel_applicability' => ['webchat', 'whatsapp'],
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

        $msgNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_1',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Mensaje 1',
            'configuration' => [
                'text' => 'Bienvenido al canal.',
            ],
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start_1',
            'target_node_key' => 'msg_1',
        ]);

        $version->update(['status' => FlowVersionStatus::PUBLISHED]);
        $flow->update([
            'status' => FlowStatus::PUBLISHED,
            'active_version_id' => $version->id,
        ]);

        return $version->fresh(['nodes', 'edges']);
    }

    protected function createWebchatSession(): array
    {
        $contact = Contact::create([
            'display_name' => 'Visitante Multimedia',
            'external_identifier' => 'wcv_'.Str::uuid(),
        ]);

        $plainToken = 'wvt_'.Str::random(40);
        $tokenHash = WebchatSession::hashToken($plainToken);

        $session = WebchatSession::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contact->id,
            'origin' => 'http://localhost:3000',
            'token_hash' => $tokenHash,
            'expires_at' => now()->addDays(7),
            'last_seen_at' => now(),
        ]);

        $conversation = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        $session->update(['active_conversation_id' => $conversation->id]);

        return [$session, $plainToken, $conversation];
    }

    protected function makeWhatsAppSignature(string $payload): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, $this->appSecret);
    }

    public function test_webchat_visitor_can_send_image_attachment(): void
    {
        [$session, $token, $conversation] = $this->createWebchatSession();

        $imageFile = UploadedFile::fake()->create('comprobante.jpg', 200, 'image/jpeg');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->post('/api/v1/public/webchat/messages', [
                'content' => 'Comprobante de pago adjunto',
                'attachment' => $imageFile,
                'client_message_id' => (string) Str::uuid(),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.message_type', 'image');
        $response->assertJsonPath('data.content', 'Comprobante de pago adjunto');

        $message = Message::where('conversation_id', $conversation->id)
            ->where('sender_type', MessageSenderType::CONTACT->value)
            ->latest('sent_at')
            ->first();

        $this->assertNotNull($message);
        $this->assertEquals(MessageType::IMAGE, $message->message_type);
        $this->assertEquals('Comprobante de pago adjunto', $message->content);
        $this->assertNotNull($message->payload);
        $this->assertEquals('comprobante.jpg', $message->payload['attachment']['file_name']);
        $this->assertEquals('image/jpeg', $message->payload['attachment']['mime_type']);
        $this->assertNotEmpty($message->payload['attachment']['url']);

        Storage::disk('public')->assertExists($message->payload['attachment']['disk_path']);
    }

    public function test_webchat_visitor_can_send_pdf_document_attachment(): void
    {
        [$session, $token, $conversation] = $this->createWebchatSession();

        $pdfFile = UploadedFile::fake()->create('manual.pdf', 300, 'application/pdf');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->post('/api/v1/public/webchat/messages', [
                'content' => 'Manual en PDF adjunto',
                'attachment' => $pdfFile,
                'client_message_id' => (string) Str::uuid(),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.message_type', 'file');

        $message = Message::where('conversation_id', $conversation->id)
            ->where('sender_type', MessageSenderType::CONTACT->value)
            ->latest('sent_at')
            ->first();

        $this->assertNotNull($message);
        $this->assertEquals(MessageType::FILE, $message->message_type);
        $this->assertEquals('manual.pdf', $message->payload['attachment']['file_name']);
        $this->assertEquals('application/pdf', $message->payload['attachment']['mime_type']);

        Storage::disk('public')->assertExists($message->payload['attachment']['disk_path']);
    }

    public function test_agent_in_inbox_can_send_image_attachment(): void
    {
        $contact = Contact::create(['display_name' => 'Cliente Inbox', 'external_identifier' => 'c_123']);
        $conversation = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contact->id,
            'assigned_user_id' => $this->agentUser->id,
            'status' => ConversationStatus::ASSIGNED,
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        $imageFile = UploadedFile::fake()->create('grafico.png', 200, 'image/png');

        $response = $this->actingAs($this->agentUser)
            ->post("/api/v1/conversations/{$conversation->id}/messages", [
                'content' => 'Aquí tienes el gráfico solicitado',
                'attachment' => $imageFile,
                'visibility' => 'public',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.message_type', 'image');
        $response->assertJsonPath('data.content', 'Aquí tienes el gráfico solicitado');

        $message = Message::where('conversation_id', $conversation->id)
            ->where('sender_type', MessageSenderType::AGENT->value)
            ->latest('sent_at')
            ->first();

        $this->assertNotNull($message);
        $this->assertEquals(MessageType::IMAGE, $message->message_type);
        $this->assertEquals('grafico.png', $message->payload['attachment']['file_name']);
        $this->assertEquals('image/png', $message->payload['attachment']['mime_type']);

        Storage::disk('public')->assertExists($message->payload['attachment']['disk_path']);
    }

    public function test_agent_in_inbox_can_send_document_attachment(): void
    {
        $contact = Contact::create(['display_name' => 'Cliente Factura', 'external_identifier' => 'c_456']);
        $conversation = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contact->id,
            'assigned_user_id' => $this->agentUser->id,
            'status' => ConversationStatus::ASSIGNED,
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        $xlsxFile = UploadedFile::fake()->create('reporte.xlsx', 250, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $response = $this->actingAs($this->agentUser)
            ->post("/api/v1/conversations/{$conversation->id}/messages", [
                'attachment' => $xlsxFile,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.message_type', 'file');

        $message = Message::where('conversation_id', $conversation->id)
            ->where('sender_type', MessageSenderType::AGENT->value)
            ->latest('sent_at')
            ->first();

        $this->assertNotNull($message);
        $this->assertEquals(MessageType::FILE, $message->message_type);
        $this->assertEquals('reporte.xlsx', $message->payload['attachment']['file_name']);
        $this->assertEquals('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $message->payload['attachment']['mime_type']);

        Storage::disk('public')->assertExists($message->payload['attachment']['disk_path']);
    }

    public function test_outbound_whatsapp_image_and_document_dispatch_to_meta_graph_api(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '5215512345678', 'wa_id' => '5215512345678']],
                'messages' => [['id' => 'wamid.HBgLMTIzNDU2Nzg5']],
            ], 200),
        ]);

        $contact = Contact::create([
            'display_name' => 'WhatsApp Lead',
            'phone' => '5215512345678',
            'external_identifier' => '5215512345678',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $contact->id,
            'assigned_user_id' => $this->agentUser->id,
            'status' => ConversationStatus::ASSIGNED,
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $contact->id,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Hola, quiero una infografía',
            'sent_at' => now(),
        ]);

        // 1. Send outbound image
        $imageFile = UploadedFile::fake()->create('infografia.jpg', 200, 'image/jpeg');
        $this->actingAs($this->agentUser)
            ->post("/api/v1/conversations/{$conversation->id}/messages", [
                'content' => 'Información sobre el servicio',
                'attachment' => $imageFile,
            ])
            ->assertStatus(201);

        Http::assertSent(function (ClientRequest $request) {
            $data = $request->data();

            return $data['messaging_product'] === 'whatsapp'
                && $data['type'] === 'image'
                && isset($data['image']['link'])
                && $data['image']['caption'] === 'Información sobre el servicio';
        });

        // Mark first message delivered so outbox queue permits second message dispatch
        $firstMsg = Message::where('conversation_id', $conversation->id)->where('message_type', MessageType::IMAGE)->first();
        if ($firstMsg) {
            $firstMsg->update([
                'status' => \App\Enums\MessageStatus::DELIVERED,
                'metadata' => array_merge($firstMsg->metadata ?? [], ['delivery_status' => 'delivered']),
            ]);
        }

        // 2. Send outbound document
        $pdfFile = UploadedFile::fake()->create('propuesta.pdf', 300, 'application/pdf');
        $this->actingAs($this->agentUser)
            ->post("/api/v1/conversations/{$conversation->id}/messages", [
                'content' => 'Propuesta comercial',
                'attachment' => $pdfFile,
            ])
            ->assertStatus(201);

        Http::assertSent(function (ClientRequest $request) {
            $data = $request->data();

            return $data['messaging_product'] === 'whatsapp'
                && $data['type'] === 'document'
                && isset($data['document']['link'])
                && $data['document']['filename'] === 'propuesta.pdf'
                && $data['document']['caption'] === 'Propuesta comercial';
        });
    }

    public function test_whatsapp_inbound_image_webhook_normalized_properly(): void
    {
        Queue::fake();
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => '9988776655',
                    'changes' => [
                        [
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '15550234567',
                                    'phone_number_id' => '10987654321',
                                ],
                                'contacts' => [
                                    [
                                        'profile' => ['name' => 'Carlos Cliente'],
                                        'wa_id' => '5219988776655',
                                    ],
                                ],
                                'messages' => [
                                    [
                                        'from' => '5219988776655',
                                        'id' => 'wamid.INBOUND_IMAGE_001',
                                        'timestamp' => (string) time(),
                                        'type' => 'image',
                                        'image' => [
                                            'id' => 'meta_media_id_image_999',
                                            'mime_type' => 'image/jpeg',
                                            'sha256' => 'abcdef123456',
                                            'caption' => 'Te comparto la foto del producto',
                                        ],
                                    ],
                                ],
                            ],
                            'field' => 'messages',
                        ],
                    ],
                ],
            ],
        ];

        $rawJson = json_encode($payload);
        $signature = $this->makeWhatsAppSignature($rawJson);

        $response = $this->call(
            'POST',
            "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}",
            [],
            [],
            [],
            [
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $rawJson
        );

        $response->assertStatus(200);
        Queue::assertPushed(ProcessWhatsAppMediaAttachmentJob::class, 1);

        $message = Message::where('external_message_id', 'wamid.INBOUND_IMAGE_001')->first();
        $this->assertNotNull($message);
        $this->assertEquals(MessageType::IMAGE, $message->message_type);
        $this->assertEquals('Te comparto la foto del producto', $message->content);
        $this->assertEquals('meta_media_id_image_999', $message->payload['attachment']['media_id']);
        $this->assertEquals('image/jpeg', $message->payload['attachment']['mime_type']);
    }

    public function test_whatsapp_inbound_document_webhook_normalized_properly(): void
    {
        Queue::fake();
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => '9988776655',
                    'changes' => [
                        [
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '15550234567',
                                    'phone_number_id' => '10987654321',
                                ],
                                'contacts' => [
                                    [
                                        'profile' => ['name' => 'Laura Cliente'],
                                        'wa_id' => '5219988776656',
                                    ],
                                ],
                                'messages' => [
                                    [
                                        'from' => '5219988776656',
                                        'id' => 'wamid.INBOUND_DOC_002',
                                        'timestamp' => (string) time(),
                                        'type' => 'document',
                                        'document' => [
                                            'id' => 'meta_media_id_doc_888',
                                            'filename' => 'especificaciones.pdf',
                                            'mime_type' => 'application/pdf',
                                            'sha256' => 'fedcba654321',
                                            'caption' => 'Revisa las especificaciones',
                                        ],
                                    ],
                                ],
                            ],
                            'field' => 'messages',
                        ],
                    ],
                ],
            ],
        ];

        $rawJson = json_encode($payload);
        $signature = $this->makeWhatsAppSignature($rawJson);

        $response = $this->call(
            'POST',
            "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}",
            [],
            [],
            [],
            [
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $rawJson
        );

        $response->assertStatus(200);
        Queue::assertPushed(ProcessWhatsAppMediaAttachmentJob::class, 1);

        $message = Message::where('external_message_id', 'wamid.INBOUND_DOC_002')->first();
        $this->assertNotNull($message);
        $this->assertEquals(MessageType::FILE, $message->message_type);
        $this->assertEquals('Revisa las especificaciones', $message->content);
        $this->assertEquals('meta_media_id_doc_888', $message->payload['attachment']['media_id']);
        $this->assertEquals('especificaciones.pdf', $message->payload['attachment']['file_name']);
        $this->assertEquals('application/pdf', $message->payload['attachment']['mime_type']);
    }

    public function test_whatsapp_inbound_image_webhook_downloads_and_stores_media_from_meta(): void
    {
        Storage::fake('public');

        // Minimal valid 1x1 JPEG binary
        $fakeImageBinary = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
        $mediaId = 'meta_media_id_image_download_123';
        $metaDownloadUrl = 'https://lookaside.fbsbx.com/whatsapp_business/attachments/temp_image_123';

        Http::fake([
            "https://graph.facebook.com/v21.0/{$mediaId}" => Http::response([
                'url' => $metaDownloadUrl,
                'mime_type' => 'image/jpeg',
                'file_size' => strlen($fakeImageBinary),
                'id' => $mediaId,
            ], 200),
            $metaDownloadUrl => Http::response($fakeImageBinary, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => '9988776655',
                    'changes' => [
                        [
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '15550234567',
                                    'phone_number_id' => '10987654321',
                                ],
                                'contacts' => [
                                    [
                                        'profile' => ['name' => 'Carlos Cliente'],
                                        'wa_id' => '5219988776655',
                                    ],
                                ],
                                'messages' => [
                                    [
                                        'from' => '5219988776655',
                                        'id' => 'wamid.INBOUND_IMAGE_DOWNLOAD_001',
                                        'timestamp' => (string) time(),
                                        'type' => 'image',
                                        'image' => [
                                            'id' => $mediaId,
                                            'mime_type' => 'image/jpeg',
                                            'sha256' => 'abcdef123456',
                                            'caption' => 'Foto con descarga automática',
                                        ],
                                    ],
                                ],
                            ],
                            'field' => 'messages',
                        ],
                    ],
                ],
            ],
        ];

        $rawJson = json_encode($payload);
        $signature = $this->makeWhatsAppSignature($rawJson);

        $response = $this->call(
            'POST',
            "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}",
            [],
            [],
            [],
            [
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $rawJson
        );

        $response->assertStatus(200);

        $message = Message::where('external_message_id', 'wamid.INBOUND_IMAGE_DOWNLOAD_001')->first();
        $this->assertNotNull($message);
        $this->assertEquals(MessageType::IMAGE, $message->message_type);
        $this->assertEquals('Foto con descarga automática', $message->content);

        $attachment = $message->payload['attachment'];
        $this->assertEquals($mediaId, $attachment['media_id']);
        $this->assertEquals('image/jpeg', $attachment['mime_type']);
        $this->assertNotEmpty($attachment['url']);
        $this->assertStringContainsString('attachments/whatsapp/', $attachment['url']);
        $this->assertGreaterThan(0, $attachment['size_bytes']);

        // Check storage disk
        $diskPath = $attachment['disk_path'] ?? null;
        $this->assertNotNull($diskPath);
        Storage::disk('public')->assertExists($diskPath);
    }

    public function test_whatsapp_inbound_document_webhook_downloads_and_stores_media_from_meta(): void
    {
        Storage::fake('public');

        $fakePdfBinary = "%PDF-1.4\n%Fake PDF content for test\n%%EOF";
        $mediaId = 'meta_media_id_doc_download_456';
        $metaDownloadUrl = 'https://lookaside.fbsbx.com/whatsapp_business/attachments/temp_doc_456';

        Http::fake([
            "https://graph.facebook.com/v21.0/{$mediaId}" => Http::response([
                'url' => $metaDownloadUrl,
                'mime_type' => 'application/pdf',
                'file_size' => strlen($fakePdfBinary),
                'id' => $mediaId,
            ], 200),
            $metaDownloadUrl => Http::response($fakePdfBinary, 200, ['Content-Type' => 'application/pdf']),
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => '9988776655',
                    'changes' => [
                        [
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '15550234567',
                                    'phone_number_id' => '10987654321',
                                ],
                                'contacts' => [
                                    [
                                        'profile' => ['name' => 'Laura Cliente'],
                                        'wa_id' => '5219988776656',
                                    ],
                                ],
                                'messages' => [
                                    [
                                        'from' => '5219988776656',
                                        'id' => 'wamid.INBOUND_DOC_DOWNLOAD_002',
                                        'timestamp' => (string) time(),
                                        'type' => 'document',
                                        'document' => [
                                            'id' => $mediaId,
                                            'filename' => 'contrato_firmado.pdf',
                                            'mime_type' => 'application/pdf',
                                            'sha256' => 'fedcba654321',
                                            'caption' => 'Documento adjunto descargado',
                                        ],
                                    ],
                                ],
                            ],
                            'field' => 'messages',
                        ],
                    ],
                ],
            ],
        ];

        $rawJson = json_encode($payload);
        $signature = $this->makeWhatsAppSignature($rawJson);

        $response = $this->call(
            'POST',
            "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}",
            [],
            [],
            [],
            [
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $rawJson
        );

        $response->assertStatus(200);

        $message = Message::where('external_message_id', 'wamid.INBOUND_DOC_DOWNLOAD_002')->first();
        $this->assertNotNull($message);
        $this->assertEquals(MessageType::FILE, $message->message_type);

        $attachment = $message->payload['attachment'];
        $this->assertEquals($mediaId, $attachment['media_id']);
        $this->assertEquals('contrato_firmado.pdf', $attachment['file_name']);
        $this->assertEquals('application/pdf', $attachment['mime_type']);
        $this->assertNotEmpty($attachment['url']);
        $this->assertStringContainsString('attachments/whatsapp/', $attachment['url']);
        $this->assertGreaterThan(0, $attachment['size_bytes']);

        $diskPath = $attachment['disk_path'] ?? null;
        $this->assertNotNull($diskPath);
        Storage::disk('public')->assertExists($diskPath);
    }

    public function test_dangerous_files_are_rejected_with_422(): void
    {
        [$session, $token, $conversation] = $this->createWebchatSession();

        // 1. PHP script
        $phpFile = UploadedFile::fake()->create('malicious.php', 10, 'application/x-httpd-php');
        $resPhp = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/public/webchat/messages', [
                'attachment' => $phpFile,
                'client_message_id' => (string) Str::uuid(),
            ]);
        $resPhp->assertStatus(422);
        $resPhp->assertJsonValidationErrors(['attachment']);

        // 2. SVG file (Stored XSS vector)
        $svgFile = UploadedFile::fake()->create('vector.svg', 10, 'image/svg+xml');
        $resSvg = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/public/webchat/messages', [
                'attachment' => $svgFile,
                'client_message_id' => (string) Str::uuid(),
            ]);
        $resSvg->assertStatus(422);
        $resSvg->assertJsonValidationErrors(['attachment']);

        // 3. HTML file
        $htmlFile = UploadedFile::fake()->create('phishing.html', 10, 'text/html');
        $resHtml = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/public/webchat/messages', [
                'attachment' => $htmlFile,
                'client_message_id' => (string) Str::uuid(),
            ]);
        $resHtml->assertStatus(422);
        $resHtml->assertJsonValidationErrors(['attachment']);
    }

    public function test_oversized_files_are_rejected_with_422(): void
    {
        [$session, $token, $conversation] = $this->createWebchatSession();

        // 1. Image > 5MB (e.g. 6 MB = 6144 KB)
        $bigImage = UploadedFile::fake()->create('big_photo.jpg', 6144, 'image/jpeg');
        $resImg = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/public/webchat/messages', [
                'attachment' => $bigImage,
                'client_message_id' => (string) Str::uuid(),
            ]);
        $resImg->assertStatus(422);
        $resImg->assertJsonValidationErrors(['attachment']);

        // 2. Document > 10MB (e.g. 11 MB = 11264 KB)
        $bigDoc = UploadedFile::fake()->create('big_archive.pdf', 11264, 'application/pdf');
        $resDoc = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/public/webchat/messages', [
                'attachment' => $bigDoc,
                'client_message_id' => (string) Str::uuid(),
            ]);
        $resDoc->assertStatus(422);
        $resDoc->assertJsonValidationErrors(['attachment']);

        // 3. Audio > 16MB (e.g. 17 MB = 17408 KB)
        $bigAudio = UploadedFile::fake()->create('big_podcast.mp3', 17408, 'audio/mpeg');
        $resAudio = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/public/webchat/messages', [
                'attachment' => $bigAudio,
                'client_message_id' => (string) Str::uuid(),
            ]);
        $resAudio->assertStatus(422);
        $resAudio->assertJsonValidationErrors(['attachment']);
    }

    public function test_webchat_visitor_can_send_audio_attachment(): void
    {
        [$session, $token, $conversation] = $this->createWebchatSession();

        $audioFile = UploadedFile::fake()->create('nota_voz.ogg', 150, 'audio/ogg');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->post('/api/v1/public/webchat/messages', [
                'content' => 'Audio explicativo',
                'attachment' => $audioFile,
                'client_message_id' => (string) Str::uuid(),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.message_type', 'audio');

        $message = Message::where('conversation_id', $conversation->id)
            ->where('sender_type', MessageSenderType::CONTACT->value)
            ->latest('sent_at')
            ->first();

        $this->assertNotNull($message);
        $this->assertEquals(MessageType::AUDIO, $message->message_type);
        $this->assertEquals('Audio explicativo', $message->content);
        $this->assertNotNull($message->payload);
        $this->assertStringContainsString('audio', $message->payload['attachment']['mime_type']);
    }

    public function test_agent_can_send_audio_attachment_and_it_dispatches_to_whatsapp(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '50688889999', 'wa_id' => '50688889999']],
                'messages' => [['id' => 'wamid.OUTBOUND_AUDIO_MSG_123']],
            ], 200),
        ]);

        $contact = Contact::create([
            'whatsapp_phone' => '50688889999',
            'first_name' => 'Cliente Audio',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $contact->id,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Hola, me envías el audio?',
            'sent_at' => now(),
        ]);

        $audioFile = UploadedFile::fake()->create('nota_agente.ogg', 250, 'audio/ogg');
        $this->actingAs($this->agentUser)
            ->post("/api/v1/conversations/{$conversation->id}/messages", [
                'content' => '',
                'attachment' => $audioFile,
            ])
            ->assertStatus(201);

        Http::assertSent(function (ClientRequest $request) {
            $data = $request->data();

            return $data['messaging_product'] === 'whatsapp'
                && $data['type'] === 'audio'
                && isset($data['audio']['link']);
        });
    }

    public function test_whatsapp_inbound_audio_voice_note_is_downloaded_and_stored(): void
    {
        Storage::fake('public');

        $fakeOggBinary = "OggS\x00\x02".str_repeat("\x00", 20).'OpusHead'.str_repeat("\x00", 100);
        $mediaId = 'meta_media_id_audio_voice_789';
        $metaDownloadUrl = 'https://lookaside.fbsbx.com/whatsapp_business/attachments/temp_audio_789';

        Http::fake([
            "https://graph.facebook.com/v21.0/{$mediaId}" => Http::response([
                'url' => $metaDownloadUrl,
                'mime_type' => 'audio/ogg',
                'file_size' => strlen($fakeOggBinary),
                'id' => $mediaId,
            ], 200),
            $metaDownloadUrl => Http::response($fakeOggBinary, 200, ['Content-Type' => 'audio/ogg']),
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => '9988776655',
                    'changes' => [
                        [
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '15550234567',
                                    'phone_number_id' => '10987654321',
                                ],
                                'contacts' => [
                                    [
                                        'profile' => ['name' => 'Carlos Audio'],
                                        'wa_id' => '5219988776657',
                                    ],
                                ],
                                'messages' => [
                                    [
                                        'from' => '5219988776657',
                                        'id' => 'wamid.INBOUND_AUDIO_VOICE_003',
                                        'timestamp' => (string) time(),
                                        'type' => 'audio',
                                        'audio' => [
                                            'id' => $mediaId,
                                            'mime_type' => 'audio/ogg; codecs=opus',
                                            'sha256' => '123456abcdef',
                                            'voice' => true,
                                        ],
                                    ],
                                ],
                            ],
                            'field' => 'messages',
                        ],
                    ],
                ],
            ],
        ];

        $rawJson = json_encode($payload);
        $signature = $this->makeWhatsAppSignature($rawJson);

        $response = $this->call(
            'POST',
            "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}",
            [],
            [],
            [],
            [
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $rawJson
        );

        $response->assertStatus(200);

        $message = Message::where('external_message_id', 'wamid.INBOUND_AUDIO_VOICE_003')->first();
        $this->assertNotNull($message);
        $this->assertEquals(MessageType::AUDIO, $message->message_type);
        $this->assertEquals('🎵 Nota de voz', $message->content);

        $attachment = $message->payload['attachment'];
        $this->assertEquals($mediaId, $attachment['media_id']);
        $this->assertTrue($attachment['is_voice_note'] ?? false);
        $this->assertStringContainsString('audio', $attachment['mime_type']);
        $this->assertNotEmpty($attachment['url']);
        $this->assertStringContainsString('attachments/whatsapp/', $attachment['url']);

        $diskPath = $attachment['disk_path'] ?? null;
        $this->assertNotNull($diskPath);
        Storage::disk('public')->assertExists($diskPath);
    }
}
