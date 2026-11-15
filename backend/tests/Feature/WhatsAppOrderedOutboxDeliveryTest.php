<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\MessageCreated;
use App\Jobs\ProcessWhatsAppOutboxJob;
use App\Listeners\DeliverOutboundChannelMessage;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Runtime\Channels\Adapters\WhatsAppChannelAdapter;
use App\Services\Runtime\Channels\ChannelAdapterRegistry;
use App\Services\Runtime\Channels\WhatsAppOutboxService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class WhatsAppOrderedOutboxDeliveryTest extends TestCase
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
            'name' => 'WhatsApp Oficial Outbox',
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
            'first_name' => 'Cliente',
            'last_name' => 'Prueba',
            'phone' => '+50671112233',
        ]);

        $this->conversation = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => 'bot',
            'started_at' => now(),
            'last_message_at' => now(),
        ]);
    }

    protected function sendStatusWebhook(string $wamid, string $status, array $errors = []): \Illuminate\Testing\TestResponse
    {
        $statusItem = [
            'id' => $wamid,
            'status' => $status,
            'timestamp' => (string) now()->timestamp,
            'recipient_id' => '50671112233',
        ];

        if (! empty($errors)) {
            $statusItem['errors'] = $errors;
        }

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
                                    'display_phone_number' => '10987654321',
                                    'phone_number_id' => '10987654321',
                                ],
                                'statuses' => [$statusItem],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $json = json_encode($payload);
        $signature = 'sha256=' . hash_hmac('sha256', $json, $this->appSecret);

        return $this->postJson(
            "/api/v1/public/webhooks/whatsapp/{$this->whatsappChannel->id}",
            $payload,
            ['X-Hub-Signature-256' => $signature]
        );
    }

    /**
     * Recreates the production incident:
     * Bot emits: 1) Image, 2) Link text, 3) Interactive question.
     * Meta takes longer to process/deliver the image.
     * The outbox holds messages 2 and 3 until Meta confirms delivered on message 1.
     * Verified: Recipient receives them in the exact original sequence: Image -> Link -> Question.
     */
    public function test_outbox_delivers_messages_strictly_in_order_waiting_for_meta_delivered_confirmation(): void
    {
        $dispatchedWamids = [];
        $dispatchedPayloads = [];

        Http::fake([
            'https://graph.facebook.com/*' => function (ClientRequest $request) use (&$dispatchedWamids, &$dispatchedPayloads) {
                $payload = $request->data();
                $dispatchedPayloads[] = $payload;

                $wamid = 'wamid.HBg' . Str::random(16);
                $dispatchedWamids[] = $wamid;

                return Http::response([
                    'messaging_product' => 'whatsapp',
                    'contacts' => [['input' => '50671112233', 'wa_id' => '50671112233']],
                    'messages' => [['id' => $wamid]],
                ], 200);
            },
        ]);

        $listener = app(DeliverOutboundChannelMessage::class);

        // 1. Bot creates Message 1: Image
        $imgMsg = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::BOT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::IMAGE,
            'content' => '',
            'payload' => [
                'attachment' => [
                    'url' => 'https://conexa.example/ofrenda.png',
                    'file_name' => 'ofrenda.png',
                    'mime_type' => 'image/png',
                    'caption' => '',
                ],
            ],
        ]);
        $listener->handle(new MessageCreated($imgMsg));

        // Message 1 is dispatched immediately to Meta
        $this->assertCount(1, $dispatchedPayloads);
        $this->assertEquals('image', $dispatchedPayloads[0]['type']);
        $imgMsg->refresh();
        $imgWamid = $imgMsg->external_message_id;
        $this->assertNotNull($imgWamid);
        $this->assertEquals('sent', $imgMsg->metadata['delivery_status']);

        // 2. Flow immediately creates Message 2: Link Text (while image is still in-flight)
        $linkMsg = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::BOT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Para ofrendar por medio de tu navegador: https://ejemplo.com',
        ]);
        $listener->handle(new MessageCreated($linkMsg));

        // CRITICAL CHECK: Message 2 MUST NOT be dispatched yet! Image is still awaiting delivery confirmation.
        $this->assertCount(1, $dispatchedPayloads, 'Message 2 must remain queued in outbox while Image is in-flight');
        $linkMsg->refresh();
        $this->assertNull($linkMsg->external_message_id);
        $this->assertEquals('pending', $linkMsg->metadata['delivery_status']);

        // 3. Flow immediately creates Message 3: Interactive Question
        $questionMsg = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::BOT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::INTERACTIVE,
            'content' => '¿Deseas realizar otra consulta?',
            'payload' => [
                'options' => [
                    ['id' => '1', 'title' => 'Sí'],
                    ['id' => '2', 'title' => 'No'],
                ],
            ],
            'metadata' => [
                'is_menu' => true,
            ],
        ]);
        $listener->handle(new MessageCreated($questionMsg));

        // CRITICAL CHECK: Message 3 MUST NOT be dispatched yet either!
        $this->assertCount(1, $dispatchedPayloads, 'Message 3 must remain queued in outbox');
        $questionMsg->refresh();
        $this->assertNull($questionMsg->external_message_id);
        $this->assertEquals('pending', $questionMsg->metadata['delivery_status']);

        // 4. Meta finishes downloading image and sends webhook: DELIVERED for Image (Message 1)
        $response1 = $this->sendStatusWebhook($imgWamid, 'delivered');
        $response1->assertOk();

        // Image is now delivered, triggering dispatch of Message 2 (Link Text)!
        $imgMsg->refresh();
        $this->assertEquals('delivered', $imgMsg->metadata['delivery_status']);
        $this->assertEquals(MessageStatus::DELIVERED, $imgMsg->status);

        $this->assertCount(2, $dispatchedPayloads, 'Message 2 must be dispatched immediately upon Message 1 delivered');
        $this->assertEquals('text', $dispatchedPayloads[1]['type']);
        $this->assertStringContainsString('https://ejemplo.com', $dispatchedPayloads[1]['text']['body']);

        $linkMsg->refresh();
        $linkWamid = $linkMsg->external_message_id;
        $this->assertNotNull($linkWamid);
        $this->assertEquals('sent', $linkMsg->metadata['delivery_status']);

        // Message 3 is STILL pending because Message 2 is now in-flight
        $questionMsg->refresh();
        $this->assertNull($questionMsg->external_message_id);

        // 5. Meta sends webhook: DELIVERED for Link (Message 2)
        $response2 = $this->sendStatusWebhook($linkWamid, 'delivered');
        $response2->assertOk();

        $linkMsg->refresh();
        $this->assertEquals('delivered', $linkMsg->metadata['delivery_status']);

        // Outbox immediately dispatches Message 3 (Question)!
        $this->assertCount(3, $dispatchedPayloads, 'Message 3 must be dispatched upon Message 2 delivered');
        $this->assertEquals('interactive', $dispatchedPayloads[2]['type']);

        $questionMsg->refresh();
        $questionWamid = $questionMsg->external_message_id;
        $this->assertNotNull($questionWamid);
        $this->assertEquals('sent', $questionMsg->metadata['delivery_status']);

        // 6. Meta sends webhook: DELIVERED for Question (Message 3)
        $response3 = $this->sendStatusWebhook($questionWamid, 'delivered');
        $response3->assertOk();

        $questionMsg->refresh();
        $this->assertEquals('delivered', $questionMsg->metadata['delivery_status']);

        // FINAL VERIFICATION: Sequence received by Meta Graph API is exactly: Image -> Text -> Interactive
        $this->assertEquals('image', $dispatchedPayloads[0]['type']);
        $this->assertEquals('text', $dispatchedPayloads[1]['type']);
        $this->assertEquals('interactive', $dispatchedPayloads[2]['type']);
    }

    /**
     * Verify outbox unblocks after timeout, recording the architectural trade-off:
     * continuing avoids freezing the conversation permanently, but relative delivery
     * order on recipient device may be compromised if Meta delivers late.
     */
    public function test_outbox_unblocks_conversation_if_delivery_confirmation_times_out(): void
    {
        $dispatchedPayloads = [];

        Http::fake([
            'https://graph.facebook.com/*' => function (ClientRequest $request) use (&$dispatchedPayloads) {
                $dispatchedPayloads[] = $request->data();

                return Http::response([
                    'messaging_product' => 'whatsapp',
                    'contacts' => [['input' => '50671112233', 'wa_id' => '50671112233']],
                    'messages' => [['id' => 'wamid.HBg' . Str::random(16)]],
                ], 200);
            },
        ]);

        $listener = app(DeliverOutboundChannelMessage::class);
        $outboxService = app(WhatsAppOutboxService::class);

        // Message 1: Image
        $imgMsg = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::BOT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::IMAGE,
            'content' => '',
            'payload' => [
                'attachment' => ['url' => 'https://conexa.example/test.png'],
            ],
        ]);
        $listener->handle(new MessageCreated($imgMsg));

        $this->assertCount(1, $dispatchedPayloads);

        // Message 2: Text (queued)
        $textMsg = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::BOT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Mensaje 2 esperando',
        ]);
        $listener->handle(new MessageCreated($textMsg));

        $this->assertCount(1, $dispatchedPayloads);

        // Simulate that 30 seconds have passed without Meta delivering Message 1
        Carbon::setTestNow(now()->addSeconds(30));

        // Trigger outbox processor
        $outboxService->processNextOutbound($this->conversation->id);

        // Outbox unblocks and dispatches Message 2!
        $this->assertCount(2, $dispatchedPayloads);
        $this->assertEquals('text', $dispatchedPayloads[1]['type']);

        $imgMsg->refresh();
        $this->assertTrue($imgMsg->metadata['delivery_timed_out'] ?? false);
        $this->assertTrue($imgMsg->metadata['order_compromised_by_timeout'] ?? false);

        $textMsg->refresh();
        $this->assertNotNull($textMsg->external_message_id);

        Carbon::setTestNow();
    }

    /**
     * Test case: sendOutbound() returns null.
     * Verifies that the message is unequivocally marked as FAILED with visible alert
     * and that pending messages in the queue advance rather than staying silently stuck.
     */
    public function test_outbox_handles_send_outbound_returning_null_and_unblocks_pending_messages(): void
    {
        $mockAdapter = $this->createMock(WhatsAppChannelAdapter::class);
        $mockAdapter->method('sendOutbound')->willReturn(null);

        $mockRegistry = $this->createMock(ChannelAdapterRegistry::class);
        $mockRegistry->method('forChannel')->willReturn($mockAdapter);

        $outboxService = new WhatsAppOutboxService($mockRegistry);
        $listener = new DeliverOutboundChannelMessage($mockRegistry, $outboxService);

        // 1. Create first message
        $msg1 = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::BOT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Mensaje 1 que devolverá null',
        ]);

        // 2. Create second message (waiting in outbox)
        $msg2 = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::BOT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Mensaje 2 pendiente en cola',
        ]);

        $listener->handle(new MessageCreated($msg1));

        $msg1->refresh();
        // Msg 1 must be marked as FAILED with an explicit visible error in metadata
        $this->assertEquals(MessageStatus::FAILED, $msg1->status);
        $this->assertEquals('failed', $msg1->metadata['delivery_status']);
        $this->assertNotEmpty($msg1->metadata['delivery_error']);
        $this->assertEquals('DISPATCH_RETURNED_NULL', $msg1->metadata['delivery_error_code']);

        // Msg 2 was processed by the unblocking outbox and attempted dispatch (which also returns null and fails cleanly)
        $msg2->refresh();
        $this->assertEquals(MessageStatus::FAILED, $msg2->status);
        $this->assertEquals('failed', $msg2->metadata['delivery_status']);
        $this->assertNotEmpty($msg2->metadata['delivery_error']);
        $this->assertEquals('DISPATCH_RETURNED_NULL', $msg2->metadata['delivery_error_code']);
    }

    /**
     * Test case: Missing channel credentials.
     * Verifies that messages are immediately and visibly marked FAILED with MISSING_CREDENTIALS
     * and do not block the queue silently.
     */
    public function test_outbox_handles_missing_channel_credentials_and_marks_failed_with_visible_alert(): void
    {
        // Remove credentials from channel
        $this->whatsappChannel->update([
            'configuration' => [
                'phone_number_id' => '',
                'access_token' => '',
            ],
        ]);

        $listener = app(DeliverOutboundChannelMessage::class);

        $msg1 = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::BOT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Mensaje sin credenciales',
        ]);

        $listener->handle(new MessageCreated($msg1));

        $msg1->refresh();
        $this->assertEquals(MessageStatus::FAILED, $msg1->status);
        $this->assertEquals('failed', $msg1->metadata['delivery_status']);
        $this->assertEquals('MISSING_CREDENTIALS', $msg1->metadata['delivery_error_code']);
        $this->assertStringContainsString('token de acceso', $msg1->metadata['delivery_error']);
    }

    /**
     * Test case: Network exception during dispatch.
     * Verifies that the network exception is caught, sanitized, recorded in metadata,
     * and the outbox advances without getting stuck.
     */
    public function test_outbox_handles_network_exception_sanitizes_error_and_unblocks_queue(): void
    {
        $mockAdapter = $this->createMock(WhatsAppChannelAdapter::class);
        $callCount = 0;

        $mockAdapter->method('sendOutbound')->willReturnCallback(function (Message $msg) use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new \RuntimeException('Guzzle timeout connecting to graph.facebook.com for +50671112233?token=SECRET_123');
            }

            return [
                'messages' => [['id' => 'wamid.SUCCESS_MSG_2']],
            ];
        });

        $mockRegistry = $this->createMock(ChannelAdapterRegistry::class);
        $mockRegistry->method('forChannel')->willReturn($mockAdapter);

        $outboxService = new WhatsAppOutboxService($mockRegistry);
        $listener = new DeliverOutboundChannelMessage($mockRegistry, $outboxService);

        $msg1 = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::BOT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Mensaje con excepción de red',
        ]);

        $msg2 = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::BOT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Mensaje 2 que debe avanzar',
        ]);

        $listener->handle(new MessageCreated($msg1));

        $msg1->refresh();
        $this->assertEquals(MessageStatus::FAILED, $msg1->status);
        $this->assertEquals('failed', $msg1->metadata['delivery_status']);
        $this->assertStringNotContainsString('SECRET_123', $msg1->metadata['delivery_error']);
        $this->assertStringContainsString('[REDACTED_PHONE]', $msg1->metadata['delivery_error']);

        // Msg 2 was dispatched and succeeded
        $msg2->refresh();
        $this->assertEquals(MessageStatus::SENT, $msg2->status);
        $this->assertEquals('wamid.SUCCESS_MSG_2', $msg2->external_message_id);
    }

    /**
     * Test case: Safe retries.
     * Verifies strict retry authorization rules:
     * 1. Allowed: Definitive failures with conclusive evidence of non-delivery (e.g. missing credentials or explicit Meta rejection).
     * 2. Rejected: Ambiguous network exceptions without WAMID (request may have reached Meta before timeout).
     * 3. Rejected: Ambiguous timeouts without WAMID (DELIVERY_TIMED_OUT or delivery_timed_out flag).
     * 4. Rejected: Messages with an assigned WAMID (Meta already received and acknowledged it).
     */
    public function test_safe_retry_permitted_only_for_conclusive_failures_and_rejects_ambiguous_states(): void
    {
        $outboxService = app(WhatsAppOutboxService::class);

        // Case 1: Message with WAMID -> REJECTED (Meta already accepted it, re-sending would duplicate)
        $convWamid = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => 'bot',
        ]);
        $msgWithWamid = Message::create([
            'conversation_id' => $convWamid->id,
            'sender_type' => MessageSenderType::BOT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Mensaje con WAMID existente',
            'external_message_id' => 'wamid.ALREADY_DISPATCHED_999',
            'status' => MessageStatus::SENT,
            'metadata' => ['wamid' => 'wamid.ALREADY_DISPATCHED_999'],
        ]);
        $this->assertFalse(
            $outboxService->retryMessage($msgWithWamid),
            'Must reject retry when WAMID exists to prevent duplicate send'
        );

        // Case 2: Ambiguous network exception without WAMID -> REJECTED (Meta may have received POST)
        $convNetwork = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => 'bot',
        ]);
        $msgNetwork = Message::create([
            'conversation_id' => $convNetwork->id,
            'sender_type' => MessageSenderType::BOT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Mensaje con fallo ambiguo de red',
            'status' => MessageStatus::FAILED,
            'metadata' => [
                'delivery_status' => 'failed',
                'delivery_error' => 'Connection timeout to graph.facebook.com',
                'delivery_error_code' => 'NETWORK_EXCEPTION',
            ],
        ]);
        $this->assertFalse(
            $outboxService->retryMessage($msgNetwork),
            'Must reject retry for ambiguous NETWORK_EXCEPTION even without WAMID'
        );
        $msgNetwork->refresh();
        $this->assertEquals(MessageStatus::FAILED, $msgNetwork->status);

        // Case 3a: Ambiguous timeout without WAMID (DELIVERY_TIMED_OUT code) -> REJECTED
        $convTimeoutA = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => 'bot',
        ]);
        $msgTimeoutA = Message::create([
            'conversation_id' => $convTimeoutA->id,
            'sender_type' => MessageSenderType::BOT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Mensaje con timeout de entrega',
            'status' => MessageStatus::FAILED,
            'metadata' => [
                'delivery_status' => 'failed',
                'delivery_error' => 'Timeout esperando confirmación',
                'delivery_error_code' => 'DELIVERY_TIMED_OUT',
            ],
        ]);
        $this->assertFalse(
            $outboxService->retryMessage($msgTimeoutA),
            'Must reject retry for DELIVERY_TIMED_OUT code without WAMID'
        );

        // Case 3b: Ambiguous timeout without WAMID (delivery_timed_out flag) -> REJECTED
        $convTimeoutB = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => 'bot',
        ]);
        $msgTimeoutB = Message::create([
            'conversation_id' => $convTimeoutB->id,
            'sender_type' => MessageSenderType::BOT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Mensaje marcado con flag de timeout',
            'status' => MessageStatus::SENT,
            'metadata' => [
                'delivery_status' => 'sent',
                'delivery_timed_out' => true,
                'order_compromised_by_timeout' => true,
            ],
        ]);
        $this->assertFalse(
            $outboxService->retryMessage($msgTimeoutB),
            'Must reject retry when delivery_timed_out flag is true'
        );

        // Setup fake sequence for Case 4a and Case 4b
        Http::fakeSequence('https://graph.facebook.com/*')
            ->push([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.RETRY_SUCCESS_CREDS_01']],
            ], 200)
            ->push([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.RETRY_SUCCESS_META_02']],
            ], 200);

        // Case 4a: Conclusive failure (MISSING_CREDENTIALS) -> ALLOWED
        $convCreds = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => 'bot',
        ]);
        $msgCreds = Message::create([
            'conversation_id' => $convCreds->id,
            'sender_type' => MessageSenderType::BOT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Mensaje fallido por credenciales',
            'status' => MessageStatus::FAILED,
            'metadata' => [
                'delivery_status' => 'failed',
                'delivery_error' => 'Token faltante',
                'delivery_error_code' => 'MISSING_CREDENTIALS',
            ],
        ]);

        $this->assertTrue(
            $outboxService->retryMessage($msgCreds),
            'Must allow retry for conclusive MISSING_CREDENTIALS failure'
        );
        $msgCreds->refresh();
        $this->assertEquals(MessageStatus::SENT, $msgCreds->status);
        $this->assertEquals('wamid.RETRY_SUCCESS_CREDS_01', $msgCreds->external_message_id);

        // Case 4b: Conclusive failure (explicit Meta API rejection without WAMID) -> ALLOWED
        $convMetaErr = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $this->contact->id,
            'status' => 'bot',
        ]);
        $msgMetaErr = Message::create([
            'conversation_id' => $convMetaErr->id,
            'sender_type' => MessageSenderType::BOT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Mensaje rechazado explícitamente por Meta',
            'status' => MessageStatus::FAILED,
            'metadata' => [
                'delivery_status' => 'failed',
                'delivery_error' => 'Message template not found or approved',
                'delivery_error_code' => '132000',
            ],
        ]);

        $this->assertTrue(
            $outboxService->retryMessage($msgMetaErr),
            'Must allow retry for conclusive Meta API rejection error code'
        );
        $msgMetaErr->refresh();
        $this->assertEquals(MessageStatus::SENT, $msgMetaErr->status);
        $this->assertEquals('wamid.RETRY_SUCCESS_META_02', $msgMetaErr->external_message_id);
    }

    /**
     * Verify idempotency: repeated delivery webhooks or events do not duplicate dispatches.
     */
    public function test_outbox_is_idempotent_and_prevents_duplicate_dispatches(): void
    {
        $httpCallCount = 0;

        Http::fake([
            'https://graph.facebook.com/*' => function () use (&$httpCallCount) {
                $httpCallCount++;

                return Http::response([
                    'messaging_product' => 'whatsapp',
                    'contacts' => [['input' => '50671112233', 'wa_id' => '50671112233']],
                    'messages' => [['id' => 'wamid.IDEMPOTENT_111']],
                ], 200);
            },
        ]);

        $listener = app(DeliverOutboundChannelMessage::class);

        $msg = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::BOT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Mensaje Idempotente',
        ]);

        $listener->handle(new MessageCreated($msg));
        $this->assertEquals(1, $httpCallCount);

        // Calling listener again on same message must not re-dispatch
        $listener->handle(new MessageCreated($msg->fresh()));
        $this->assertEquals(1, $httpCallCount);

        // Sending duplicate delivered webhook
        $this->sendStatusWebhook('wamid.IDEMPOTENT_111', 'delivered')->assertOk();
        $this->sendStatusWebhook('wamid.IDEMPOTENT_111', 'delivered')->assertOk();

        $this->assertEquals(1, $httpCallCount);
    }
}
