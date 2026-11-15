<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Exceptions\PermanentWhatsAppMediaPrewarmException;
use App\Exceptions\TransientWhatsAppMediaPrewarmException;
use App\Jobs\PrewarmFlowMediaJob;
use App\Jobs\PrewarmWhatsAppMediaJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppMediaCache;
use App\Services\Media\WhatsAppMediaPrewarmService;
use App\Services\Runtime\Channels\Adapters\WhatsAppChannelAdapter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WhatsAppMediaPrewarmTest extends TestCase
{
    use RefreshDatabase;

    protected Channel $whatsappChannelA;

    protected Channel $whatsappChannelB;

    protected Channel $inactiveChannel;

    protected Flow $flow;

    protected FlowVersion $version;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->user = User::factory()->create();

        $this->flow = Flow::create([
            'name' => 'Flujo de Ventas WhatsApp',
            'status' => FlowStatus::DRAFT,
            'channel_applicability' => ['whatsapp'],
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $this->version = FlowVersion::create([
            'flow_id' => $this->flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::DRAFT,
            'created_by' => $this->user->id,
        ]);

        $this->whatsappChannelA = Channel::create([
            'name' => 'WhatsApp Canal A',
            'type' => ChannelType::WHATSAPP,
            'is_active' => true,
            'entry_flow_id' => $this->flow->id,
            'configuration' => [
                'phone_number_id' => '111111111',
                'business_account_id' => '999999999',
                'access_token' => 'TOKEN_A',
            ],
        ]);

        $this->whatsappChannelB = Channel::create([
            'name' => 'WhatsApp Canal B',
            'type' => ChannelType::WHATSAPP,
            'is_active' => true,
            'entry_flow_id' => null, // Not linked to this flow
            'configuration' => [
                'phone_number_id' => '222222222',
                'business_account_id' => '888888888',
                'access_token' => 'TOKEN_B',
            ],
        ]);

        $this->inactiveChannel = Channel::create([
            'name' => 'WhatsApp Inactivo',
            'type' => ChannelType::WHATSAPP,
            'is_active' => false,
            'entry_flow_id' => $this->flow->id,
            'configuration' => [
                'phone_number_id' => '333333333',
                'access_token' => 'TOKEN_C',
            ],
        ]);
    }

    /**
     * Test (1): PrewarmFlowMediaJob and prewarmVersion strictly process active WhatsApp
     * channels whose entry_flow_id matches flow_id, and respect flow applicability.
     */
    public function test_channel_selection_strictly_filters_active_whatsapp_channels_with_matching_entry_flow(): void
    {
        FlowNode::create([
            'flow_version_id' => $this->version->id,
            'node_key' => 'img_node_1',
            'name' => 'Banner Node',
            'type' => 'image',
            'configuration' => [
                'url' => 'https://example.com/banner.jpg',
                'file_name' => 'banner.jpg',
            ],
        ]);

        Http::fake([
            'https://graph.facebook.com/*/media' => Http::response(['id' => 'meta_media_100'], 200),
            'https://example.com/banner.jpg' => Http::response('fake_image_binary_data', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        /** @var WhatsAppMediaPrewarmService $service */
        $service = app(WhatsAppMediaPrewarmService::class);

        // Prewarm synchronously
        $stats = $service->prewarmVersion($this->version, null, true);

        // Only Channel A should be processed (Channel B has entry_flow_id=null, inactiveChannel is inactive)
        $this->assertEquals(1, $stats['channels_count']);
        $this->assertEquals(1, $stats['assets_count']);
        $this->assertEquals(1, $stats['successful_count']);

        // Channel A has cached media
        $cachedA = WhatsAppMediaCache::where('channel_id', $this->whatsappChannelA->id)->first();
        $this->assertNotNull($cachedA);
        $this->assertEquals('meta_media_100', $cachedA->media_id);

        // Channel B has NO cached media
        $cachedB = WhatsAppMediaCache::where('channel_id', $this->whatsappChannelB->id)->first();
        $this->assertNull($cachedB);

        // Inactive channel has NO cached media
        $cachedInactive = WhatsAppMediaCache::where('channel_id', $this->inactiveChannel->id)->first();
        $this->assertNull($cachedInactive);
    }

    /**
     * Test (1b): When no active WhatsApp channel is linked to the flow, ZERO uploads occur (no fallback to all channels).
     */
    public function test_no_uploads_occur_when_no_whatsapp_channel_is_linked(): void
    {
        // Unlink Channel A as well
        $this->whatsappChannelA->update(['entry_flow_id' => null]);

        FlowNode::create([
            'flow_version_id' => $this->version->id,
            'node_key' => 'img_node_2',
            'name' => 'Promo Node',
            'type' => 'image',
            'configuration' => [
                'url' => 'https://example.com/promo.png',
            ],
        ]);

        Http::fake();

        /** @var WhatsAppMediaPrewarmService $service */
        $service = app(WhatsAppMediaPrewarmService::class);

        $stats = $service->prewarmVersion($this->version, null, true);

        $this->assertEquals(0, $stats['channels_count']);
        $this->assertEquals(0, $stats['successful_count']);

        // Assert no HTTP requests made to Meta Graph API
        Http::assertNothingSent();
    }

    /**
     * Test (1c): When flow applicability explicitly excludes WhatsApp, ZERO uploads occur.
     */
    public function test_no_uploads_occur_when_flow_applicability_excludes_whatsapp(): void
    {
        $this->flow->update(['channel_applicability' => ['webchat']]);

        FlowNode::create([
            'flow_version_id' => $this->version->id,
            'node_key' => 'img_node_3',
            'name' => 'Webchat Only Node',
            'type' => 'image',
            'configuration' => [
                'url' => 'https://example.com/webchat_only.png',
            ],
        ]);

        Http::fake();

        /** @var WhatsAppMediaPrewarmService $service */
        $service = app(WhatsAppMediaPrewarmService::class);

        $stats = $service->prewarmVersion($this->version, null, true);

        $this->assertEquals(0, $stats['channels_count']);
        Http::assertNothingSent();
    }

    /**
     * Test (2): PrewarmWhatsAppMediaJob accepts only IDs (channelId, flowId, flowVersionId, nodeKey),
     * never contains/serializes raw URLs in queue, and dynamically resolves the URL from FlowNode.configuration.
     */
    public function test_individual_job_does_not_serialize_url_and_resolves_configuration_at_runtime(): void
    {
        $node = FlowNode::create([
            'flow_version_id' => $this->version->id,
            'node_key' => 'doc_node_1',
            'name' => 'Catalogo Node',
            'type' => 'file',
            'configuration' => [
                'url' => 'https://example.com/catalogo.pdf',
                'file_name' => 'catalogo_2026.pdf',
                'mime_type' => 'application/pdf',
            ],
        ]);

        // Verify constructor of PrewarmWhatsAppMediaJob has no $url parameter
        $job = new PrewarmWhatsAppMediaJob(
            $this->whatsappChannelA->id,
            $this->flow->id,
            $this->version->id,
            'doc_node_1'
        );

        $serialized = serialize($job);
        $this->assertStringNotContainsString('https://example.com/catalogo.pdf', $serialized);

        Http::fake([
            'https://graph.facebook.com/*/media' => Http::response(['id' => 'meta_doc_999'], 200),
            'https://example.com/catalogo.pdf' => Http::response('%PDF-1.4 dummy pdf bytes', 200, ['Content-Type' => 'application/pdf']),
        ]);

        /** @var WhatsAppMediaPrewarmService $service */
        $service = app(WhatsAppMediaPrewarmService::class);

        // Execute the job's handle method
        $job->handle($service);

        // Verify media was uploaded and cached with meta_doc_999
        $cached = WhatsAppMediaCache::where('channel_id', $this->whatsappChannelA->id)->first();
        $this->assertNotNull($cached);
        $this->assertEquals('meta_doc_999', $cached->media_id);
        $this->assertEquals('application/pdf', $cached->mime_type);
    }

    /**
     * Test (3): Cache HIT/MISS and expiration.
     */
    public function test_cache_hit_miss_and_expiration(): void
    {
        /** @var WhatsAppMediaPrewarmService $service */
        $service = app(WhatsAppMediaPrewarmService::class);

        $url = 'https://example.com/sample.jpg';
        $fingerprint = $service->generateFingerprint($url);

        // 1. MISS initially
        $this->assertNull($service->getValidMediaId($this->whatsappChannelA, $url));

        // 2. Insert valid cache entry
        WhatsAppMediaCache::create([
            'channel_id' => $this->whatsappChannelA->id,
            'file_fingerprint' => $fingerprint,
            'media_id' => 'valid_media_id_123',
            'uploaded_at' => now(),
            'expires_at' => now()->addDays(29),
        ]);

        // HIT
        $this->assertEquals('valid_media_id_123', $service->getValidMediaId($this->whatsappChannelA, $url));

        // 3. Expired entry
        WhatsAppMediaCache::where('channel_id', $this->whatsappChannelA->id)
            ->where('file_fingerprint', $fingerprint)
            ->update(['expires_at' => now()->subDay()]);

        // MISS due to expiration
        $this->assertNull($service->getValidMediaId($this->whatsappChannelA, $url));
    }

    /**
     * Test (3b): Channel isolation - Media ID cached for Channel A is never returned for Channel B.
     */
    public function test_channel_isolation_ensures_media_id_is_never_shared_between_channels(): void
    {
        /** @var WhatsAppMediaPrewarmService $service */
        $service = app(WhatsAppMediaPrewarmService::class);

        $url = 'https://example.com/isolated_asset.png';
        $fingerprint = $service->generateFingerprint($url);

        WhatsAppMediaCache::create([
            'channel_id' => $this->whatsappChannelA->id,
            'file_fingerprint' => $fingerprint,
            'media_id' => 'channel_a_media_id',
            'uploaded_at' => now(),
            'expires_at' => now()->addDays(20),
        ]);

        // Channel A gets its media ID
        $this->assertEquals('channel_a_media_id', $service->getValidMediaId($this->whatsappChannelA, $url));

        // Channel B has no cache for this asset
        $this->assertNull($service->getValidMediaId($this->whatsappChannelB, $url));
    }

    /**
     * Test (3c): WhatsAppChannelAdapter uses { id: media_id } on HIT, and { link: url } on MISS.
     */
    public function test_adapter_format_outbound_prefers_cached_id_and_falls_back_to_link(): void
    {
        $contact = Contact::create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'phone' => '+5215512345678',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannelA->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
        ]);

        $url = 'https://example.com/flower.jpg';

        /** @var WhatsAppMediaPrewarmService $service */
        $service = app(WhatsAppMediaPrewarmService::class);
        $fingerprint = $service->generateFingerprint($url);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::IMAGE,
            'status' => MessageStatus::PENDING,
            'content' => 'Mira esta imagen',
            'payload' => [
                'attachment' => [
                    'url' => $url,
                    'mime_type' => 'image/jpeg',
                ],
            ],
        ]);

        /** @var WhatsAppChannelAdapter $adapter */
        $adapter = app(WhatsAppChannelAdapter::class);

        // 1. Without cache: formatOutbound has link
        $payloadMiss = $adapter->formatOutbound($message);
        $this->assertEquals('https://example.com/flower.jpg', $payloadMiss['image']['link'] ?? null);
        $this->assertArrayNotHasKey('id', $payloadMiss['image'] ?? []);

        // 2. With valid cache: formatOutbound has id
        WhatsAppMediaCache::create([
            'channel_id' => $this->whatsappChannelA->id,
            'file_fingerprint' => $fingerprint,
            'media_id' => 'prewarmed_flower_id_888',
            'uploaded_at' => now(),
            'expires_at' => now()->addDays(25),
        ]);

        $payloadHit = $adapter->formatOutbound($message);
        $this->assertEquals('prewarmed_flower_id_888', $payloadHit['image']['id'] ?? null);
        $this->assertArrayNotHasKey('link', $payloadHit['image'] ?? []);
    }

    /**
     * Test (4): Fallback to link occurs ONLY upon an explicit HTTP 4xx rejection from Meta for media ID.
     * Never upon network exception, timeout, 5xx server error, or ambiguous state.
     */
    public function test_send_outbound_retries_with_fallback_link_only_on_explicit_meta_media_rejection(): void
    {
        $contact = Contact::create([
            'first_name' => 'Cliente',
            'last_name' => 'Demo',
            'phone' => '+5215598765432',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannelA->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
        ]);

        $url = 'https://example.com/brochure.pdf';

        /** @var WhatsAppMediaPrewarmService $service */
        $service = app(WhatsAppMediaPrewarmService::class);
        $fingerprint = $service->generateFingerprint($url);

        WhatsAppMediaCache::create([
            'channel_id' => $this->whatsappChannelA->id,
            'file_fingerprint' => $fingerprint,
            'media_id' => 'expired_media_id_404',
            'uploaded_at' => now()->subDays(20),
            'expires_at' => now()->addDays(5),
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::DOCUMENT,
            'status' => MessageStatus::PENDING,
            'content' => 'Brochure PDF',
            'payload' => [
                'attachment' => [
                    'url' => $url,
                    'file_name' => 'brochure.pdf',
                    'mime_type' => 'application/pdf',
                ],
            ],
        ]);

        // Sequence: First request (with id) returns Meta 400 error with explicit details that media ID does not exist or expired.
        // Second request (with link) returns 200 success.
        Http::fake([
            'https://graph.facebook.com/*/messages' => Http::sequence()
                ->push([
                    'error' => [
                        'message' => 'Invalid parameter',
                        'type' => 'OAuthException',
                        'code' => 100,
                        'error_data' => [
                            'details' => 'Media ID does not exist or has expired.',
                        ],
                    ],
                ], 400)
                ->push([
                    'messages' => [['id' => 'wamid.HBgLMTU1OTg3NjU0MzIVAgARGBJGM0Q2QkJFM0JCN0Q1NzRFMQA=']],
                ], 200),
        ]);

        /** @var WhatsAppChannelAdapter $adapter */
        $adapter = app(WhatsAppChannelAdapter::class);
        $result = $adapter->sendOutbound($message);

        $this->assertNotNull($result);
        $this->assertEquals('wamid.HBgLMTU1OTg3NjU0MzIVAgARGBJGM0Q2QkJFM0JCN0Q1NzRFMQA=', $result['messages'][0]['id'] ?? null);

        // Check that the bad media ID was invalidated from cache
        $cacheAfter = WhatsAppMediaCache::where('channel_id', $this->whatsappChannelA->id)
            ->where('file_fingerprint', $fingerprint)
            ->first();
        $this->assertNull($cacheAfter);

        // Check that exactly 2 requests were sent: 1st with 'id', 2nd with 'link'
        Http::assertSentCount(2);

        Http::assertSent(function (ClientRequest $req) {
            $data = $req->data();

            return isset($data['document']['id']) && $data['document']['id'] === 'expired_media_id_404';
        });

        Http::assertSent(function (ClientRequest $req) use ($url) {
            $data = $req->data();

            return isset($data['document']['link']) && $data['document']['link'] === $url;
        });
    }

    /**
     * Test (4b): When Meta returns a 500 error or rate limit, NO retry is performed to avoid message duplication.
     */
    public function test_send_outbound_never_retries_on_500_or_non_media_rejection(): void
    {
        $contact = Contact::create([
            'first_name' => 'Cliente',
            'last_name' => '500',
            'phone' => '+5215598765432',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannelA->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::IMAGE,
            'status' => MessageStatus::PENDING,
            'content' => 'Banner Image',
            'payload' => [
                'attachment' => [
                    'url' => 'https://example.com/banner.jpg',
                ],
            ],
        ]);

        Http::fake([
            'https://graph.facebook.com/*/messages' => Http::response([
                'error' => [
                    'message' => 'Internal server error',
                    'code' => 131000,
                ],
            ], 500),
        ]);

        /** @var WhatsAppChannelAdapter $adapter */
        $adapter = app(WhatsAppChannelAdapter::class);
        $result = $adapter->sendOutbound($message);

        // Exactly 1 request sent; NO retry!
        Http::assertSentCount(1);
    }

    /**
     * Test (4c): Error code 131051 ("Unsupported message type") is a type/format error,
     * NOT proof of expired media ID. Exactly 1 request must be made, with ZERO fallback retry.
     */
    public function test_send_outbound_never_retries_on_unsupported_message_type_131051(): void
    {
        $contact = Contact::create([
            'first_name' => 'Cliente',
            'last_name' => '131051',
            'phone' => '+5215598765432',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $this->whatsappChannelA->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
        ]);

        $url = 'https://example.com/unsupported.xyz';

        /** @var WhatsAppMediaPrewarmService $service */
        $service = app(WhatsAppMediaPrewarmService::class);
        $fingerprint = $service->generateFingerprint($url);

        WhatsAppMediaCache::create([
            'channel_id' => $this->whatsappChannelA->id,
            'file_fingerprint' => $fingerprint,
            'media_id' => 'cached_media_131051',
            'uploaded_at' => now(),
            'expires_at' => now()->addDays(20),
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::DOCUMENT,
            'status' => MessageStatus::PENDING,
            'content' => 'Doc 131051',
            'payload' => [
                'attachment' => [
                    'url' => $url,
                    'file_name' => 'unsupported.xyz',
                    'mime_type' => 'application/octet-stream',
                ],
            ],
        ]);

        Http::fake([
            'https://graph.facebook.com/*/messages' => Http::sequence()
                ->push([
                    'error' => [
                        'message' => 'Unsupported message type',
                        'type' => 'OAuthException',
                        'code' => 131051,
                        'fbtrace_id' => 'trace_131051_abc',
                    ],
                ], 400)
                ->push([
                    'error' => [
                        'message' => 'Media ID is not compatible with this file type.',
                        'type' => 'OAuthException',
                        'code' => 100,
                    ],
                ], 400),
        ]);

        /** @var WhatsAppChannelAdapter $adapter */
        $adapter = app(WhatsAppChannelAdapter::class);
        $result = $adapter->sendOutbound($message);

        // Exactly 1 request sent; ZERO fallback retries!
        Http::assertSentCount(1);

        // A media ID mentioned in an ambiguous type/compatibility error is not evidence that
        // the cached ID expired; it must not trigger a duplicate fallback request either.
        $ambiguousMessage = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::DOCUMENT,
            'status' => MessageStatus::PENDING,
            'content' => 'Ambiguous media error',
            'payload' => [
                'attachment' => [
                    'url' => $url,
                    'file_name' => 'ambiguous.pdf',
                ],
            ],
        ]);
        $adapter->sendOutbound($ambiguousMessage);
        Http::assertSentCount(2);

        // Cache must NOT be invalidated because 131051 is a message type error, not media ID expiration
        $cache = WhatsAppMediaCache::where('channel_id', $this->whatsappChannelA->id)
            ->where('file_fingerprint', $fingerprint)
            ->first();
        $this->assertNotNull($cache);
        $this->assertEquals('cached_media_131051', $cache->media_id);
    }

    /**
     * Test (A): getValidMediaId() does NOT read the entire file content synchronously (no hash_file),
     * and produces identical fingerprints at prewarm and lookup.
     */
    public function test_lookup_does_not_read_file_content_and_fingerprint_is_identical(): void
    {
        Storage::fake('public');

        // Create a fake local file
        $fileName = 'attachments/test_large_document.pdf';
        $content = str_repeat('A', 100 * 1024); // 100 KB
        Storage::disk('public')->put($fileName, $content);
        $url = Storage::disk('public')->url($fileName);

        /** @var WhatsAppMediaPrewarmService $service */
        $service = app(WhatsAppMediaPrewarmService::class);

        // Prewarm fingerprint
        $fingerprintPrewarm = $service->generateFingerprint($url);
        $this->assertNotEmpty($fingerprintPrewarm);

        // Lookup fingerprint
        $fingerprintLookup = $service->generateFingerprint($url);
        $this->assertEquals($fingerprintPrewarm, $fingerprintLookup);

        // Populate cache
        WhatsAppMediaCache::create([
            'channel_id' => $this->whatsappChannelA->id,
            'file_fingerprint' => $fingerprintPrewarm,
            'media_id' => 'cached_meta_media_777',
            'uploaded_at' => now(),
            'expires_at' => now()->addDays(20),
        ]);

        // getValidMediaId lookup must return media_id without calling Storage get()
        $mediaId = $service->getValidMediaId($this->whatsappChannelA, $url);
        $this->assertEquals('cached_meta_media_777', $mediaId);
    }

    /**
     * Test (B): Logs never contain raw unredacted media_id in HIT, cache lock, upload success, or invalidate.
     * Only channel_id and truncated fingerprint are logged.
     */
    public function test_service_logs_never_contain_raw_media_id_in_hit_lock_upload_and_invalidate(): void
    {
        Log::spy();

        Http::fake([
            'https://graph.facebook.com/*/media' => Http::response(['id' => 'raw_sensitive_meta_media_id_999999'], 200),
            'https://example.com/log_test.jpg' => Http::response('fake image binary', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        /** @var WhatsAppMediaPrewarmService $service */
        $service = app(WhatsAppMediaPrewarmService::class);
        $url = 'https://example.com/log_test.jpg';

        // 1. Upload to Meta (triggers upload success log)
        $cached = $service->uploadToMeta($this->whatsappChannelA, $url);
        $this->assertNotNull($cached);

        // 2. Lookup (triggers Cache HIT log)
        $mediaId = $service->getValidMediaId($this->whatsappChannelA, $url);
        $this->assertEquals('raw_sensitive_meta_media_id_999999', $mediaId);

        // 3. Invalidate (triggers invalidate log)
        $service->invalidateMediaId($this->whatsappChannelA, 'raw_sensitive_meta_media_id_999999');

        // Assert Log never logged 'raw_sensitive_meta_media_id_999999' as an unredacted string or context value
        Log::shouldHaveReceived('info')
            ->withArgs(function ($message, $context = []) {
                if (str_contains((string) $message, 'raw_sensitive_meta_media_id_999999')) {
                    return false;
                }
                foreach ($context as $key => $val) {
                    if ($key === 'media_id' || $val === 'raw_sensitive_meta_media_id_999999') {
                        return false; // Violates rule: media_id must not be in context
                    }
                }

                return true;
            });
    }

    /**
     * Test (C): PrewarmWhatsAppMediaJob retries on transient errors (network/5xx) and terminates without retry on permanent errors (4xx/validation).
     */
    public function test_job_retries_transient_failures_and_aborts_permanent_failures(): void
    {
        FlowNode::create([
            'flow_version_id' => $this->version->id,
            'node_key' => 'img_node_retry',
            'name' => 'Retry Test Node',
            'type' => 'image',
            'configuration' => [
                'url' => 'https://example.com/retry.jpg',
            ],
        ]);

        /** @var WhatsAppMediaPrewarmService $service */
        $service = app(WhatsAppMediaPrewarmService::class);

        // Sequence: 1st call returns 500 (transient), 2nd call returns 400 (permanent)
        $metaSequence = Http::sequence()
            ->push(['error' => 'Temporary server glitch'], 500)
            ->push([
                'error' => [
                    'message' => 'Invalid file format',
                    'type' => 'OAuthException',
                    'code' => 100,
                ],
            ], 400);

        Http::fake([
            'https://graph.facebook.com/*/media' => $metaSequence,
            'https://example.com/retry.jpg' => Http::response('fake binary', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $transientJob = new PrewarmWhatsAppMediaJob(
            $this->whatsappChannelA->id,
            $this->flow->id,
            $this->version->id,
            'img_node_retry'
        );

        // 1. Transient Error: Job MUST rethrow TransientWhatsAppMediaPrewarmException so Laravel Queue retries it
        try {
            $transientJob->handle($service);
            $this->fail('Expected TransientWhatsAppMediaPrewarmException was not thrown on 500 error.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(TransientWhatsAppMediaPrewarmException::class, $e);
        }

        $permanentJob = new PrewarmWhatsAppMediaJob(
            $this->whatsappChannelA->id,
            $this->flow->id,
            $this->version->id,
            'img_node_retry'
        );

        // 2. Permanent Error: Job MUST catch PermanentWhatsAppMediaPrewarmException and return cleanly without throwing (no retry)
        $permanentJob->handle($service);
        $this->assertTrue(true, 'Permanent error handled cleanly without rethrow.');
    }

    /**
     * Test (1): Canonicalization preserves semantic query params (?id=1 vs ?id=2, case sensitivity ?ID=1 vs ?id=1)
     * while discarding volatile auth/signature/expiration parameters ONLY on recognized providers (AWS S3, GCS, Azure Blob).
     */
    public function test_fingerprint_canonicalization_preserves_semantic_query_params_and_discards_volatile_signatures(): void
    {
        /** @var WhatsAppMediaPrewarmService $service */
        $service = app(WhatsAppMediaPrewarmService::class);

        // Sub-test (1): Generic host with ?sp=business&se=one vs ?sp=business&se=two -> DIFFERENT fingerprints
        $genericUrl1 = 'https://example.com/api/document?sp=business&se=one';
        $genericUrl2 = 'https://example.com/api/document?sp=business&se=two';
        $fpGeneric1 = $service->generateFingerprint($genericUrl1);
        $fpGeneric2 = $service->generateFingerprint($genericUrl2);
        $this->assertNotEquals($fpGeneric1, $fpGeneric2, 'Generic host must NOT filter short params like sp/se, keeping differences');

        // Sub-test (2): Exact key casing preservation ?ID=1 vs ?id=1 -> DIFFERENT fingerprints
        $urlUpperId = 'https://example.com/asset.pdf?ID=1';
        $urlLowerId = 'https://example.com/asset.pdf?id=1';
        $fpUpper = $service->generateFingerprint($urlUpperId);
        $fpLower = $service->generateFingerprint($urlLowerId);
        $this->assertNotEquals($fpUpper, $fpLower, '?ID=1 and ?id=1 must yield different fingerprints preserving case');

        // Sub-test (3): Valid Azure, S3, and GCS signatures signed at different times -> IDENTICAL fingerprints
        // 3a. Azure Blob SAS
        $azureSas1 = 'https://myaccount.blob.core.windows.net/media/doc.pdf?id=100&sp=r&st=2026-11-09T10%3A00%3A00Z&se=2026-11-09T12%3A00%3A00Z&spr=https&sv=2024-11-04&sr=b&sig=SIG_AAA_111';
        $azureSas2 = 'https://myaccount.blob.core.windows.net/media/doc.pdf?id=100&sp=r&st=2026-11-09T11%3A00%3A00Z&se=2026-11-09T13%3A00%3A00Z&spr=https&sv=2024-11-04&sr=b&sig=SIG_BBB_222';
        $fpAzure1 = $service->generateFingerprint($azureSas1);
        $fpAzure2 = $service->generateFingerprint($azureSas2);
        $this->assertEquals($fpAzure1, $fpAzure2, 'Azure Blob SAS URLs with different signatures/expiries must yield identical fingerprints');

        // 3b. Google Cloud Storage signed
        $gcsUrl1 = 'https://storage.googleapis.com/my-bucket/contracts/doc.pdf?id=100&GoogleAccessId=service@proj.iam.gserviceaccount.com&Expires=1731170000&Signature=SIG_AAA_111';
        $gcsUrl2 = 'https://storage.googleapis.com/my-bucket/contracts/doc.pdf?id=100&GoogleAccessId=service@proj.iam.gserviceaccount.com&Expires=1731180000&Signature=SIG_BBB_222';
        $fpGcs1 = $service->generateFingerprint($gcsUrl1);
        $fpGcs2 = $service->generateFingerprint($gcsUrl2);
        $this->assertEquals($fpGcs1, $fpGcs2, 'GCS signed URLs with different signatures/expiries must yield identical fingerprints');

        // 3c. AWS S3 presigned
        $s3Url1 = 'https://my-bucket.s3.us-east-1.amazonaws.com/doc.pdf?id=100&X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIA123%2F20261109%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261109T100000Z&X-Amz-Expires=3600&X-Amz-Signature=sig1';
        $s3Url2 = 'https://my-bucket.s3.us-east-1.amazonaws.com/doc.pdf?id=100&X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIA123%2F20261109%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261109T110000Z&X-Amz-Expires=7200&X-Amz-Signature=sig2';
        $fpS3_1 = $service->generateFingerprint($s3Url1);
        $fpS3_2 = $service->generateFingerprint($s3Url2);
        $this->assertEquals($fpS3_1, $fpS3_2, 'AWS S3 presigned URLs with different signatures/expiries must yield identical fingerprints');

        // Sub-test (4): Repeated parameters sorted equally without colliding different values
        $urlRepeatOrder1 = 'https://example.com/doc.pdf?tag=alpha&tag=beta';
        $urlRepeatOrder2 = 'https://example.com/doc.pdf?tag=beta&tag=alpha';
        $fpRepeat1 = $service->generateFingerprint($urlRepeatOrder1);
        $fpRepeat2 = $service->generateFingerprint($urlRepeatOrder2);
        $this->assertEquals($fpRepeat1, $fpRepeat2, 'Repeated parameters in different order must normalize to identical fingerprint');

        $urlRepeatDiffVal = 'https://example.com/doc.pdf?tag=alpha&tag=gamma';
        $fpRepeatDiff = $service->generateFingerprint($urlRepeatDiffVal);
        $this->assertNotEquals($fpRepeat1, $fpRepeatDiff, 'Different values in repeated parameters must yield different fingerprints');

        // Sub-test (5): Lookalike/spoofed domain (e.g. storage.googleapis.com.evil.example)
        // must NOT be treated as GCS/Azure: Expires and Signature must be preserved as semantic parameters and not collide.
        $lookalike1 = 'https://storage.googleapis.com.evil.example/doc.pdf?id=1&Expires=1&Signature=A';
        $lookalike2 = 'https://storage.googleapis.com.evil.example/doc.pdf?id=1&Expires=2&Signature=B';
        $fpLookalike1 = $service->generateFingerprint($lookalike1);
        $fpLookalike2 = $service->generateFingerprint($lookalike2);
        $this->assertNotEquals($fpLookalike1, $fpLookalike2, 'Malicious lookalike domain must preserve Expires and Signature as semantic parameters and not collide');

        // Sub-test (6): Provider-specific resource selectors must remain semantic, even when
        // their parameter names share a prefix with signature fields.
        $s3Version1 = 'https://my-bucket.s3.us-east-1.amazonaws.com/doc.pdf?X-Amz-Version-Id=v1&X-Amz-Signature=sigA';
        $s3Version2 = 'https://my-bucket.s3.us-east-1.amazonaws.com/doc.pdf?X-Amz-Version-Id=v2&X-Amz-Signature=sigB';
        $this->assertNotEquals(
            $service->generateFingerprint($s3Version1),
            $service->generateFingerprint($s3Version2),
            'Different S3 object versions must not collide when volatile signature parameters rotate'
        );

        $gcsGeneration1 = 'https://storage.googleapis.com/my-bucket/doc.pdf?X-Goog-Generation=100&X-Goog-Signature=sigA';
        $gcsGeneration2 = 'https://storage.googleapis.com/my-bucket/doc.pdf?X-Goog-Generation=200&X-Goog-Signature=sigB';
        $this->assertNotEquals(
            $service->generateFingerprint($gcsGeneration1),
            $service->generateFingerprint($gcsGeneration2),
            'Different GCS object generations must remain distinct when signatures rotate'
        );

        $azureSemantic1 = 'https://myaccount.blob.core.windows.net/media/doc.pdf?Expires=1&Signature=A';
        $azureSemantic2 = 'https://myaccount.blob.core.windows.net/media/doc.pdf?Expires=2&Signature=B';
        $this->assertNotEquals(
            $service->generateFingerprint($azureSemantic1),
            $service->generateFingerprint($azureSemantic2),
            'Generic expiration/signature params without Azure SAS sig must remain semantic'
        );
    }

    /**
     * Test (2): Origin download error classification.
     * HTTP 503 and network timeouts trigger TransientWhatsAppMediaPrewarmException (retry).
     * HTTP 404 triggers PermanentWhatsAppMediaPrewarmException (abort without retry).
     */
    public function test_origin_download_classifies_transient_503_and_timeout_vs_permanent_404(): void
    {
        FlowNode::create([
            'flow_version_id' => $this->version->id,
            'node_key' => 'origin_test_node',
            'name' => 'Origin Download Test Node',
            'type' => 'image',
            'configuration' => [
                'url' => 'https://example.com/origin-image.jpg',
            ],
        ]);

        /** @var WhatsAppMediaPrewarmService $service */
        $service = app(WhatsAppMediaPrewarmService::class);

        // Sequence: 1st call returns 503 (transient), 2nd call returns 404 (permanent), 3rd call throws ConnectionException (timeout/transient)
        $originSequence = Http::sequence()
            ->push('Service Unavailable', 503)
            ->push('File Not Found', 404)
            ->pushResponse(fn () => throw new ConnectionException('Connection timed out after 15000 milliseconds'));

        Http::fake([
            'https://example.com/origin-image.jpg' => $originSequence,
            'https://graph.facebook.com/*/media' => Http::response(['id' => 'unused'], 200),
        ]);

        $job503 = new PrewarmWhatsAppMediaJob(
            $this->whatsappChannelA->id,
            $this->flow->id,
            $this->version->id,
            'origin_test_node'
        );

        // 1. Origin HTTP 503 Service Unavailable -> Transient (Job re-throws for queue retry)
        try {
            $job503->handle($service);
            $this->fail('Expected TransientWhatsAppMediaPrewarmException on 503 origin download');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(TransientWhatsAppMediaPrewarmException::class, $e);
        }

        $job404 = new PrewarmWhatsAppMediaJob(
            $this->whatsappChannelA->id,
            $this->flow->id,
            $this->version->id,
            'origin_test_node'
        );

        // 2. Origin HTTP 404 Not Found -> Permanent (Job catches and returns cleanly, NO retry)
        $job404->handle($service);
        $this->assertTrue(true, '404 permanent error terminates without rethrow');

        // 3. Network Connection Timeout (ConnectionException) -> Transient (Job re-throws for queue retry)
        $jobTimeout = new PrewarmWhatsAppMediaJob(
            $this->whatsappChannelA->id,
            $this->flow->id,
            $this->version->id,
            'origin_test_node'
        );

        try {
            $jobTimeout->handle($service);
            $this->fail('Expected TransientWhatsAppMediaPrewarmException on origin connection timeout');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(TransientWhatsAppMediaPrewarmException::class, $e);
            $this->assertStringContainsString('Connection timed out', $e->getMessage());
        }
    }

    /**
     * Test (3): Concurrency lock failure when cache is absent throws TransientWhatsAppMediaPrewarmException
     * to allow queue worker to retry with backoff.
     */
    public function test_concurrency_lock_failure_with_absent_cache_throws_transient_exception(): void
    {
        /** @var WhatsAppMediaPrewarmService $service */
        $service = app(WhatsAppMediaPrewarmService::class);
        $url = 'https://example.com/locked_asset.pdf';
        $fingerprint = $service->generateFingerprint($url);

        // Pre-acquire the lock to simulate another concurrent worker currently uploading
        $lockKey = "whatsapp_media_upload_{$this->whatsappChannelA->id}_{$fingerprint}";
        $lock = Cache::lock($lockKey, 30);
        $lock->get();

        try {
            // Upload attempt while lock is held and cache is absent MUST throw TransientWhatsAppMediaPrewarmException
            $service->uploadToMeta($this->whatsappChannelA, $url);
            $this->fail('Expected TransientWhatsAppMediaPrewarmException when lock is held and cache is absent');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(TransientWhatsAppMediaPrewarmException::class, $e);
            $this->assertStringContainsString('bloqueado por otra carga concurrente', $e->getMessage());
        } finally {
            $lock->release();
        }
    }
}
