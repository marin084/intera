<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\MessageStatusUpdated;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Runtime\Channels\Adapters\WhatsAppChannelAdapter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppMessageStatusTest extends TestCase
{
    use RefreshDatabase;

    protected Channel $whatsappChannel;
    protected string $appSecret = 'meta_test_secret_key_123';
    protected Conversation $conversation;
    protected Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->whatsappChannel = Channel::create([
            'name' => 'WhatsApp Oficial',
            'type' => ChannelType::WHATSAPP,
            'is_active' => true,
            'configuration' => [
                'phone_number_id' => '10987654321',
                'business_account_id' => '9988776655',
                'access_token' => 'EAAG_TEST_ACCESS_TOKEN_XYZ',
                'verify_token' => 'test_token',
                'app_secret' => $this->appSecret,
                'api_version' => 'v21.0',
            ],
        ]);

        $this->contact = Contact::create([
            'first_name' => 'María',
            'last_name' => 'Pérez',
            'phone' => '+50671112233',
        ]);

        $this->conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => 'bot',
        ]);
    }

    protected function computeSignature(string $rawContent): string
    {
        return 'sha256=' . hash_hmac('sha256', $rawContent, $this->appSecret);
    }

    public function test_webhook_updates_message_from_sent_to_delivered(): void
    {
        Event::fake([MessageStatusUpdated::class]);

        $wamid = 'wamid.HBgLMzQ2NzYzODcyNzAVAgASGBQzQTkyREI4NzExNDdF';
        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'status' => MessageStatus::SENT,
            'external_message_id' => $wamid,
            'content' => 'Hola María, ¿en qué podemos ayudarte?',
            'sent_at' => now(),
        ]);

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
                                    'display_phone_number' => '50688888888',
                                    'phone_number_id' => '10987654321',
                                ],
                                'statuses' => [
                                    [
                                        'id' => $wamid,
                                        'status' => 'delivered',
                                        'timestamp' => '1730000000',
                                        'recipient_id' => '50671112233',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $rawBody = json_encode($payload);
        $signature = $this->computeSignature($rawBody);

        $response = $this->call(
            'POST',
            "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}",
            [],
            [],
            [],
            [
                'HTTP_X_Hub_Signature_256' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $rawBody
        );

        $response->assertStatus(200);

        $message->refresh();
        $this->assertEquals(MessageStatus::DELIVERED, $message->status);
        $this->assertTrue($message->isDelivered());
        $this->assertEquals('delivered', $message->metadata['delivery_status']);
        $this->assertNotNull($message->metadata['delivered_at'] ?? null);

        // Verification: status update NEVER creates new messages or triggers bot
        $this->assertEquals(1, Message::where('conversation_id', $this->conversation->id)->count());

        Event::assertDispatched(MessageStatusUpdated::class, function ($event) use ($message) {
            return $event->message->id === $message->id && $event->message->status === MessageStatus::DELIVERED;
        });
    }

    public function test_webhook_updates_message_from_delivered_to_read(): void
    {
        Event::fake([MessageStatusUpdated::class]);

        $wamid = 'wamid.HBgLMzQ2NzYzODcyNzAVAgASGBQzQTkyREI4NzExNDdF';
        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'status' => MessageStatus::DELIVERED,
            'external_message_id' => $wamid,
            'content' => 'Mensaje entregado previo',
            'sent_at' => now(),
        ]);

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
                                        'id' => $wamid,
                                        'status' => 'read',
                                        'timestamp' => '1730000100',
                                        'recipient_id' => '50671112233',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $rawBody = json_encode($payload);
        $signature = $this->computeSignature($rawBody);

        $response = $this->call(
            'POST',
            "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}",
            [],
            [],
            [],
            [
                'HTTP_X_Hub_Signature_256' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $rawBody
        );

        $response->assertStatus(200);

        $message->refresh();
        $this->assertEquals(MessageStatus::READ, $message->status);
        $this->assertTrue($message->isRead());
        $this->assertTrue($message->isDelivered());
        $this->assertEquals('read', $message->metadata['delivery_status']);
        $this->assertEquals(1, Message::where('conversation_id', $this->conversation->id)->count());

        Event::assertDispatched(MessageStatusUpdated::class);
    }

    public function test_webhook_does_not_downgrade_read_message_when_delivered_arrives_late(): void
    {
        Event::fake([MessageStatusUpdated::class]);

        $wamid = 'wamid.HBgLMzQ2NzYzODcyNzAVAgASGBQzQTkyREI4NzExNDdF';
        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'status' => MessageStatus::READ,
            'external_message_id' => $wamid,
            'content' => 'Mensaje ya leído',
            'sent_at' => now(),
        ]);

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
                                        'id' => $wamid,
                                        'status' => 'delivered', // Evento retrasado
                                        'timestamp' => '1730000050',
                                        'recipient_id' => '50671112233',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $rawBody = json_encode($payload);
        $signature = $this->computeSignature($rawBody);

        $response = $this->call(
            'POST',
            "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}",
            [],
            [],
            [],
            [
                'HTTP_X_Hub_Signature_256' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $rawBody
        );

        $response->assertStatus(200);

        $message->refresh();
        // El estado debe permanecer en READ, nunca degradar a DELIVERED
        $this->assertEquals(MessageStatus::READ, $message->status);
        Event::assertNotDispatched(MessageStatusUpdated::class);
    }

    public function test_webhook_handles_delivery_failure(): void
    {
        Event::fake([MessageStatusUpdated::class]);

        $wamid = 'wamid.HBgLMzQ2NzYzODcyNzAVAgASGBQzQTkyREI4NzExNDdF';
        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'status' => MessageStatus::SENT,
            'external_message_id' => $wamid,
            'content' => 'Mensaje que fallará',
            'sent_at' => now(),
        ]);

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
                                        'id' => $wamid,
                                        'status' => 'failed',
                                        'timestamp' => '1730000000',
                                        'recipient_id' => '50671112233',
                                        'errors' => [
                                            [
                                                'code' => 131047,
                                                'title' => 'Re-engagement message',
                                                'message' => 'Message failed to send outside 24 hour window',
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

        $rawBody = json_encode($payload);
        $signature = $this->computeSignature($rawBody);

        $response = $this->call(
            'POST',
            "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}",
            [],
            [],
            [],
            [
                'HTTP_X_Hub_Signature_256' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $rawBody
        );

        $response->assertStatus(200);

        $message->refresh();
        $this->assertEquals(MessageStatus::FAILED, $message->status);
        $this->assertTrue($message->isFailed());
        $this->assertEquals('failed', $message->metadata['delivery_status']);
        $this->assertEquals(131047, $message->metadata['delivery_error']['code']);
        $this->assertStringContainsString('outside 24 hour window', $message->metadata['delivery_error']['message']);
        $this->assertEquals(1, Message::where('conversation_id', $this->conversation->id)->count());

        Event::assertDispatched(MessageStatusUpdated::class);
    }

    public function test_webhook_handles_media_upload_error_prioritizing_error_data_details_and_persisting_fbtrace_id(): void
    {
        Event::fake([MessageStatusUpdated::class]);

        $wamid = 'wamid.HBgLMzQ2NzYzODcyNzAVAgASGBQzQTkyREI4NzExNDdF_MEDIA';
        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::BOT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::FILE,
            'status' => MessageStatus::SENT,
            'external_message_id' => $wamid,
            'content' => 'Documento PDF',
            'sent_at' => now(),
            'metadata' => [
                'attachment' => [
                    'url' => 'https://conexa.example.com/storage/attachments/test.pdf',
                    'file_name' => 'test.pdf',
                    'mime_type' => 'application/pdf',
                ],
            ],
        ]);

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
                                        'id' => $wamid,
                                        'status' => 'failed',
                                        'timestamp' => '1731162535',
                                        'recipient_id' => '50671112233',
                                        'errors' => [
                                            [
                                                'code' => 131053,
                                                'title' => 'Media upload error',
                                                'message' => 'Media upload error',
                                                'error_data' => [
                                                    'details' => 'Failed to download media from URL: upstream connection reset by peer',
                                                ],
                                                'fbtrace_id' => 'AbCdEf123456Trace',
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

        $rawBody = json_encode($payload);
        $signature = $this->computeSignature($rawBody);

        $response = $this->call(
            'POST',
            "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}",
            [],
            [],
            [],
            [
                'HTTP_X_Hub_Signature_256' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $rawBody
        );

        $response->assertStatus(200);

        $message->refresh();
        $this->assertEquals(MessageStatus::FAILED, $message->status);
        $this->assertTrue($message->isFailed());
        $this->assertEquals('failed', $message->metadata['delivery_status']);
        $this->assertEquals(131053, $message->metadata['delivery_error']['code']);
        $this->assertEquals('Media upload error', $message->metadata['delivery_error']['title']);

        // Must prioritize error_data.details over generic "Media upload error"
        $this->assertEquals(
            'Failed to download media from URL: upstream connection reset by peer',
            $message->metadata['delivery_error']['message']
        );
        $this->assertEquals(
            'Failed to download media from URL: upstream connection reset by peer',
            $message->metadata['delivery_error']['details']
        );
        $this->assertEquals('Media upload error', $message->metadata['delivery_error']['generic_message']);

        // Must persist fbtrace_id both inside delivery_error and at metadata root
        $this->assertEquals('AbCdEf123456Trace', $message->metadata['delivery_error']['fbtrace_id']);
        $this->assertEquals('AbCdEf123456Trace', $message->metadata['fbtrace_id']);
        $this->assertEquals('131053', $message->metadata['delivery_error_code']);

        Event::assertDispatched(MessageStatusUpdated::class);
    }

    public function test_format_outbound_document_ensures_valid_extension_and_structure(): void
    {
        $adapter = app(WhatsAppChannelAdapter::class);

        // Case A: File without extension in file_name, but mime_type is application/pdf
        $msgWithoutExt = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::BOT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::FILE,
            'status' => MessageStatus::PENDING,
            'content' => 'Comprobante de pago',
            'sent_at' => now(),
            'payload' => [
                'attachment' => [
                    'url' => 'https://conexa.example.com/storage/attachments/2026/11/uuid123',
                    'file_name' => 'comprobante_fiscal',
                    'mime_type' => 'application/pdf',
                ],
            ],
        ]);

        $formatted = $adapter->formatOutbound($msgWithoutExt);
        $this->assertEquals('whatsapp', $formatted['messaging_product']);
        $this->assertEquals('document', $formatted['type']);
        $this->assertEquals('comprobante_fiscal.pdf', $formatted['document']['filename']);
        $this->assertEquals('https://conexa.example.com/storage/attachments/2026/11/uuid123', $formatted['document']['link']);
        $this->assertEquals('Comprobante de pago', $formatted['document']['caption']);
    }

    public function test_send_outbound_sets_status_sent_and_wamid_on_success(): void
    {
        Event::fake([MessageStatusUpdated::class]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '50671112233', 'wa_id' => '50671112233']],
                'messages' => [['id' => 'wamid.SUCCESS_OUTBOUND_123']],
            ], 200),
        ]);

        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'status' => MessageStatus::PENDING,
            'content' => 'Mensaje para enviar por Graph API',
            'sent_at' => now(),
        ]);

        $adapter = app(WhatsAppChannelAdapter::class);
        $result = $adapter->sendOutbound($message);

        $this->assertNotNull($result);
        $message->refresh();
        $this->assertEquals(MessageStatus::SENT, $message->status);
        $this->assertEquals('wamid.SUCCESS_OUTBOUND_123', $message->external_message_id);
        $this->assertEquals('wamid.SUCCESS_OUTBOUND_123', $message->metadata['meta_message_id']);
        $this->assertEquals('wamid.SUCCESS_OUTBOUND_123', $message->metadata['wamid']);
        $this->assertEquals('sent', $message->metadata['delivery_status']);
        $this->assertNotNull($message->metadata['sent_at']);

        Event::assertDispatched(MessageStatusUpdated::class);
    }

    public function test_send_outbound_sets_status_failed_on_graph_api_error(): void
    {
        Event::fake([MessageStatusUpdated::class]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Invalid OAuth access token.',
                    'type' => 'OAuthException',
                    'code' => 190,
                ],
            ], 401),
        ]);

        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'status' => MessageStatus::PENDING,
            'content' => 'Mensaje con token inválido',
            'sent_at' => now(),
        ]);

        $adapter = app(WhatsAppChannelAdapter::class);
        $result = $adapter->sendOutbound($message);

        $this->assertNotNull($result);
        $message->refresh();
        $this->assertEquals(MessageStatus::FAILED, $message->status);
        $this->assertEquals('failed', $message->metadata['delivery_status']);
        $this->assertEquals(190, $message->metadata['delivery_error']['code']);

        Event::assertDispatched(MessageStatusUpdated::class);
    }

    public function test_deliver_outbound_channel_message_listener_captures_wamid_and_sent_status(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '50671112233', 'wa_id' => '50671112233']],
                'messages' => [['id' => 'wamid.LISTENER_OUTBOUND_999']],
            ], 200),
        ]);

        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'status' => MessageStatus::PENDING,
            'content' => 'Mensaje enviado a través de DeliverOutboundChannelMessage',
            'sent_at' => now(),
        ]);

        $listener = app(\App\Listeners\DeliverOutboundChannelMessage::class);
        $listener->handle(new \App\Events\MessageCreated($message));

        $message->refresh();
        $this->assertEquals('wamid.LISTENER_OUTBOUND_999', $message->external_message_id);
        $this->assertEquals('wamid.LISTENER_OUTBOUND_999', $message->metadata['wamid']);
        $this->assertEquals('sent', $message->metadata['delivery_status']);
        $this->assertNotNull($message->metadata['sent_at']);
    }
}
