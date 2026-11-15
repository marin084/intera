<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\FlowExecutionStatus;
use App\Enums\FlowFailureCode;
use App\Enums\FlowNodeType;
use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Enums\MessageSenderType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowExecution;
use App\Models\FlowExecutionStep;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Services\Runtime\Channels\InboundMessage;
use App\Services\Runtime\ConversationIngressService;
use App\Services\Runtime\ExecutionContext;
use App\Services\Runtime\FlowGraphCacheService;
use App\Services\Runtime\FlowRuntimeEngine;
use App\Services\Runtime\Handlers\HttpRequestNodeHandler;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FlowRuntimeEngineHardeningFase4Test extends TestCase
{
    use RefreshDatabase;

    protected FlowRuntimeEngine $engine;

    protected ConversationIngressService $ingress;

    protected FlowGraphCacheService $graphCache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->engine = app(FlowRuntimeEngine::class);
        $this->ingress = app(ConversationIngressService::class);
        $this->graphCache = app(FlowGraphCacheService::class);
    }

    /**
     * Test 1: Isolation between concurrent conversations - variables & state never leak.
     */
    public function test_concurrent_conversations_variable_and_session_isolation(): void
    {
        $flow = Flow::create(['name' => 'Isolation Flow', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);

        FlowNode::create(['flow_version_id' => $version->id, 'node_key' => 'start_1', 'type' => FlowNodeType::START, 'name' => 'Start']);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'q_color',
            'type' => FlowNodeType::QUESTION,
            'name' => 'Color Favorito',
            'configuration' => [
                'text' => '¿Cuál es tu color favorito?',
                'variable' => 'color_fav',
                'input_type' => 'text',
            ],
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_result',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Confirmar',
            'configuration' => [
                'text' => 'Tu color es {{color_fav}}.',
            ],
        ]);

        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'start_1', 'target_node_key' => 'q_color']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'q_color', 'target_node_key' => 'msg_result']);

        $version->update(['status' => FlowVersionStatus::PUBLISHED]);
        $flow->update(['status' => FlowStatus::PUBLISHED, 'active_version_id' => $version->id]);

        $channel = Channel::create(['name' => 'Webchat Iso', 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'entry_flow_id' => $flow->id]);

        // Start Conversation A
        $resA = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'user_alpha'], 'Hola A');
        $convA = $resA['conversation'];
        $execA = $resA['execution'];
        $this->assertEquals('q_color', $execA->fresh()->currentNode->node_key);

        // Start Conversation B
        $resB = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'user_beta'], 'Hola B');
        $convB = $resB['conversation'];
        $execB = $resB['execution'];
        $this->assertEquals('q_color', $execB->fresh()->currentNode->node_key);

        $this->assertNotEquals($convA->id, $convB->id);
        $this->assertNotEquals($execA->id, $execB->id);

        // User A answers "Azul"
        $replyA = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'user_alpha'], 'Azul');
        $execAFresh = $replyA['execution']->fresh();
        $this->assertEquals(['color_fav' => 'Azul'], $execAFresh->context);

        // User B answers "Rojo"
        $replyB = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'user_beta'], 'Rojo');
        $execBFresh = $replyB['execution']->fresh();
        $this->assertEquals(['color_fav' => 'Rojo'], $execBFresh->context);

        // Verify message contents in each conversation
        $lastMsgA = Message::where('conversation_id', $convA->id)->where('sender_type', MessageSenderType::BOT)->latest('sent_at')->first();
        $lastMsgB = Message::where('conversation_id', $convB->id)->where('sender_type', MessageSenderType::BOT)->latest('sent_at')->first();

        $this->assertEquals('Tu color es Azul.', $lastMsgA->content);
        $this->assertEquals('Tu color es Rojo.', $lastMsgB->content);
    }

    /**
     * Test 2: InboundMessage DTO channel abstraction & correlation ID propagation.
     */
    public function test_inbound_message_dto_channel_abstraction(): void
    {
        $flow = Flow::create(['name' => 'DTO Flow', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);
        FlowNode::create(['flow_version_id' => $version->id, 'node_key' => 'start_1', 'type' => FlowNodeType::START, 'name' => 'Start']);
        FlowNode::create(['flow_version_id' => $version->id, 'node_key' => 'msg_1', 'type' => FlowNodeType::MESSAGE, 'name' => 'Msg', 'configuration' => ['text' => 'Recibido DTO']]);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'start_1', 'target_node_key' => 'msg_1']);
        $version->update(['status' => FlowVersionStatus::PUBLISHED]);
        $flow->update(['status' => FlowStatus::PUBLISHED, 'active_version_id' => $version->id]);

        $channel = Channel::create(['name' => 'Channel Abstraction', 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'entry_flow_id' => $flow->id]);

        $correlationId = (string) Str::uuid();
        $inbound = InboundMessage::fromPayload(
            channel: $channel,
            contactPayload: ['external_identifier' => 'dto_user_1', 'display_name' => 'DTO Contact'],
            content: 'Mensaje vía DTO',
            externalMessageId: (string) Str::uuid(),
            metadata: ['source' => 'test_suite'],
            correlationId: $correlationId
        );

        $res = $this->ingress->handleInbound($inbound);
        $this->assertFalse($res['is_duplicate']);
        $this->assertEquals('dto_user_1', $res['contact']->external_identifier);
        $this->assertEquals($correlationId, $res['message']->metadata['_correlation_id']);
    }

    /**
     * Test 3: Permitted conversational cycle (Menu -> Info -> Return to Menu).
     */
    public function test_permitted_conversational_cycle_across_human_turns(): void
    {
        $flow = Flow::create(['name' => 'Menu Cycle Flow', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);

        FlowNode::create(['flow_version_id' => $version->id, 'node_key' => 'start_1', 'type' => FlowNodeType::START, 'name' => 'Start']);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'menu_main',
            'type' => FlowNodeType::MENU,
            'name' => 'Menú Principal',
            'configuration' => [
                'text' => 'Menú Principal:',
                'options' => [
                    ['id' => 'info', 'text' => 'Información', 'target_node_key' => 'msg_info'],
                    ['id' => 'exit', 'text' => 'Salir', 'target_node_key' => 'node_end'],
                ],
            ],
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_info',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Información',
            'configuration' => ['text' => 'Nuestros horarios son de 9 a 18.'],
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'goto_menu',
            'type' => FlowNodeType::GOTO,
            'name' => 'Volver a Menú',
            'configuration' => ['target_node_key' => 'menu_main'],
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_end',
            'type' => FlowNodeType::END,
            'name' => 'Fin',
            'configuration' => ['message' => '¡Hasta pronto!'],
        ]);

        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'start_1', 'target_node_key' => 'menu_main']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'msg_info', 'target_node_key' => 'goto_menu']);

        $version->update(['status' => FlowVersionStatus::PUBLISHED]);
        $flow->update(['status' => FlowStatus::PUBLISHED, 'active_version_id' => $version->id]);

        $channel = Channel::create(['name' => 'Channel Menu Cycle', 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'entry_flow_id' => $flow->id]);

        // Turn 1: Starts flow -> pauses at menu_main
        $res1 = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'cycler_1'], 'Hola');
        $exec1 = $res1['execution']->fresh();
        $this->assertEquals(FlowExecutionStatus::WAITING_INPUT, $exec1->status);
        $this->assertEquals('menu_main', $exec1->currentNode->node_key);

        // Turn 2: User selects "1" (Información) -> msg_info -> goto_menu -> returns to menu_main waiting for input!
        $res2 = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'cycler_1'], '1');
        $exec2 = $res2['execution']->fresh();
        $this->assertEquals(FlowExecutionStatus::WAITING_INPUT, $exec2->status);
        $this->assertEquals('menu_main', $exec2->currentNode->node_key);

        // Turn 3: User selects "Información" again! -> msg_info -> goto_menu -> returns to menu_main again!
        $res3 = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'cycler_1'], 'Información');
        $exec3 = $res3['execution']->fresh();
        $this->assertEquals(FlowExecutionStatus::WAITING_INPUT, $exec3->status);
        $this->assertEquals('menu_main', $exec3->currentNode->node_key);

        // Turn 4: User selects "Salir" -> end node -> completes flow!
        $res4 = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'cycler_1'], 'Salir');
        $exec4 = $res4['execution']->fresh();
        $this->assertEquals(FlowExecutionStatus::COMPLETED, $exec4->status);
        $this->assertEquals(ConversationStatus::CLOSED, $res4['conversation']->fresh()->status);
    }

    protected function createDummyExecution(): FlowExecution
    {
        $flow = Flow::create(['name' => 'Dummy Flow', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);
        $channel = Channel::create(['name' => 'Dummy Channel', 'type' => ChannelType::WEBCHAT, 'is_active' => true]);
        $contact = Contact::create([
            'display_name' => 'Dummy Contact',
            'external_identifier' => (string) Str::uuid(),
        ]);
        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
        ]);

        return FlowExecution::create([
            'conversation_id' => $conversation->id,
            'flow_id' => $flow->id,
            'flow_version_id' => $version->id,
            'status' => FlowExecutionStatus::RUNNING,
            'context' => [],
        ]);
    }

    /**
     * Test 4: SSRF protection in HttpRequestNodeHandler blocks private IPs and metadata endpoints.
     */
    public function test_ssrf_protection_blocks_private_ips_and_cloud_metadata(): void
    {
        $handler = app(HttpRequestNodeHandler::class);
        $execution = $this->createDummyExecution();

        $context = new ExecutionContext;

        // 1. Loopback IP (127.0.0.1)
        $nodeLoopback = FlowNode::make([
            'node_key' => 'http_loopback',
            'type' => FlowNodeType::HTTP_REQUEST,
            'configuration' => ['url' => 'http://127.0.0.1:8000/internal-admin'],
        ]);
        $resLoopback = $handler->execute($nodeLoopback, $execution, $context);
        $this->assertTrue($resLoopback->isFailed());
        $this->assertStringContainsString('SSRF', $resLoopback->errorMessage);

        // 2. Private LAN IP (192.168.1.1)
        $nodeLan = FlowNode::make([
            'node_key' => 'http_lan',
            'type' => FlowNodeType::HTTP_REQUEST,
            'configuration' => ['url' => 'http://192.168.1.1/router-settings'],
        ]);
        $resLan = $handler->execute($nodeLan, $execution, $context);
        $this->assertTrue($resLan->isFailed());
        $this->assertStringContainsString('SSRF', $resLan->errorMessage);

        // 3. Cloud Metadata IP (169.254.169.254)
        $nodeMeta = FlowNode::make([
            'node_key' => 'http_meta',
            'type' => FlowNodeType::HTTP_REQUEST,
            'configuration' => ['url' => 'http://169.254.169.254/latest/meta-data/'],
        ]);
        $resMeta = $handler->execute($nodeMeta, $execution, $context);
        $this->assertTrue($resMeta->isFailed());
        $this->assertStringContainsString('SSRF', $resMeta->errorMessage);

        // 4. IPv4-mapped IPv6 loopback (::ffff:127.0.0.1)
        $nodeV6 = FlowNode::make([
            'node_key' => 'http_v6',
            'type' => FlowNodeType::HTTP_REQUEST,
            'configuration' => ['url' => 'http://[::ffff:127.0.0.1]/secret'],
        ]);
        $resV6 = $handler->execute($nodeV6, $execution, $context);
        $this->assertTrue($resV6->isFailed());
        $this->assertStringContainsString('SSRF', $resV6->errorMessage);
    }

    /**
     * Test 5: Enterprise allowed hosts allows explicit private destinations.
     */
    public function test_ssrf_allows_configured_enterprise_hosts(): void
    {
        config(['flows.http.allowed_hosts' => ['crm.internal.corp', '10.50.1.20']]);

        $handler = app(HttpRequestNodeHandler::class);
        $execution = $this->createDummyExecution();

        $context = new ExecutionContext;

        Http::fake([
            'http://crm.internal.corp/*' => Http::response(['status' => 'ok', 'lead_id' => 123], 200),
        ]);

        $nodeAllowed = FlowNode::make([
            'node_key' => 'http_allowed',
            'type' => FlowNodeType::HTTP_REQUEST,
            'configuration' => [
                'url' => 'http://crm.internal.corp/leads',
                'method' => 'POST',
                'response_mapping' => [
                    ['json_path' => 'lead_id', 'variable_name' => 'crm_lead'],
                ],
            ],
        ]);

        $res = $handler->execute($nodeAllowed, $execution, $context);
        $this->assertTrue($res->isCompleted());
        $this->assertEquals(123, $context->get('crm_lead'));
    }

    /**
     * Test 6: Sensitive headers redaction in HTTP execution diagnostics.
     */
    public function test_http_node_redacts_sensitive_headers(): void
    {
        $handler = app(HttpRequestNodeHandler::class);
        $execution = $this->createDummyExecution();

        $context = new ExecutionContext;

        Http::fake([
            'https://example.com/api' => Http::response(['data' => 'test'], 200),
        ]);

        $node = FlowNode::make([
            'node_key' => 'http_secure_headers',
            'type' => FlowNodeType::HTTP_REQUEST,
            'configuration' => [
                'url' => 'https://example.com/api',
                'headers' => [
                    ['key' => 'Authorization', 'value' => 'Bearer super_secret_token_123'],
                    ['key' => 'X-Api-Key', 'value' => 'api_key_xyz'],
                    ['key' => 'Accept', 'value' => 'application/json'],
                ],
            ],
        ]);

        $res = $handler->execute($node, $execution, $context);
        $this->assertTrue($res->isCompleted());
        $savedHeaders = $res->metadata['headers'];

        $this->assertEquals('***REDACTED***', $savedHeaders['Authorization']);
        $this->assertEquals('***REDACTED***', $savedHeaders['X-Api-Key']);
        $this->assertEquals('application/json', $savedHeaders['Accept']);
    }

    /**
     * Test 7: HTTP response size limit prevents memory exhaustion.
     */
    public function test_http_node_response_size_limit(): void
    {
        config(['flows.http.max_response_bytes' => 1024]); // 1 KB limit

        $handler = app(HttpRequestNodeHandler::class);
        $execution = $this->createDummyExecution();

        $context = new ExecutionContext;

        // 2 KB large string
        $largeBody = str_repeat('A', 2048);
        Http::fake([
            'https://example.com/huge' => Http::response($largeBody, 200),
        ]);

        $node = FlowNode::make([
            'node_key' => 'http_huge',
            'type' => FlowNodeType::HTTP_REQUEST,
            'configuration' => [
                'url' => 'https://example.com/huge',
            ],
        ]);

        $res = $handler->execute($node, $execution, $context);
        $this->assertTrue($res->isFailed());
        $this->assertEquals(FlowFailureCode::HTTP_REQUEST_ERROR->value, $res->failureCode);
        $this->assertStringContainsString('superó el límite', $res->errorMessage);
    }

    /**
     * Test 8: SetVariable node arithmetic increment and JSON decoding.
     */
    public function test_set_variable_advanced_types_and_operations(): void
    {
        $flow = Flow::create(['name' => 'SetVar Flow', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);

        FlowNode::create(['flow_version_id' => $version->id, 'node_key' => 'start_1', 'type' => FlowNodeType::START, 'name' => 'Start']);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'set_counter',
            'type' => FlowNodeType::SET_VARIABLE,
            'name' => 'Set Counter',
            'configuration' => ['variable' => 'counter', 'value' => '10', 'type' => 'number', 'operation' => 'set'],
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'inc_counter',
            'type' => FlowNodeType::SET_VARIABLE,
            'name' => 'Increment Counter',
            'configuration' => ['variable' => 'counter', 'value' => '5', 'operation' => 'increment'],
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'set_json',
            'type' => FlowNodeType::SET_VARIABLE,
            'name' => 'Set JSON',
            'configuration' => ['variable' => 'user_data', 'value' => '{"role":"vip","score":100}', 'type' => 'json'],
        ]);

        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'start_1', 'target_node_key' => 'set_counter']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'set_counter', 'target_node_key' => 'inc_counter']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'inc_counter', 'target_node_key' => 'set_json']);

        $version->update(['status' => FlowVersionStatus::PUBLISHED]);
        $flow->update(['status' => FlowStatus::PUBLISHED, 'active_version_id' => $version->id]);

        $channel = Channel::create(['name' => 'Channel SetVar', 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'entry_flow_id' => $flow->id]);

        $res = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'setvar_user'], 'Iniciar');
        $exec = $res['execution']->fresh();

        $this->assertEquals(15, $exec->context['counter']);
        $this->assertEquals(['role' => 'vip', 'score' => 100], $exec->context['user_data']);
    }

    /**
     * Test 9: Graph cache invalidation upon publish.
     */
    public function test_graph_cache_invalidation_on_publish(): void
    {
        $flow = Flow::create(['name' => 'Cache Test Flow', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::PUBLISHED]);

        FlowNode::create(['flow_version_id' => $version->id, 'node_key' => 'start_1', 'type' => FlowNodeType::START, 'name' => 'Start']);

        // First call populates cache
        $loaded1 = $this->graphCache->getVersionWithGraph($version->id);
        $this->assertNotNull($loaded1);

        // Invalidate
        $this->graphCache->invalidate($version->id);

        // Second call reloads cleanly
        $loaded2 = $this->graphCache->getVersionWithGraph($version->id);
        $this->assertNotNull($loaded2);
        $this->assertEquals($version->id, $loaded2->id);
    }

    /**
     * Test 10: Multi-rule condition node evaluation with AND/OR logic.
     */
    public function test_condition_node_multi_rule_evaluation(): void
    {
        $flow = Flow::create(['name' => 'Condition Multi Flow', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);

        FlowNode::create(['flow_version_id' => $version->id, 'node_key' => 'start_1', 'type' => FlowNodeType::START, 'name' => 'Start']);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'set_data',
            'type' => FlowNodeType::SET_VARIABLE,
            'name' => 'Set Data',
            'configuration' => ['variable' => 'tier', 'value' => 'premium', 'operation' => 'set'],
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'cond_check',
            'type' => FlowNodeType::CONDITION,
            'name' => 'Check Tier',
            'configuration' => [
                'logic' => 'or',
                'rules' => [
                    ['variable' => 'tier', 'operator' => 'equals', 'value' => 'vip'],
                    ['variable' => 'tier', 'operator' => 'equals', 'value' => 'premium'],
                ],
            ],
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_success',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Success',
            'configuration' => ['text' => 'Acceso concedido para nivel {{tier}}.'],
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_fail',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Fail',
            'configuration' => ['text' => 'Acceso denegado.'],
        ]);

        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'start_1', 'target_node_key' => 'set_data']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'set_data', 'target_node_key' => 'cond_check']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'cond_check', 'target_node_key' => 'msg_success', 'condition_handle' => 'true']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'cond_check', 'target_node_key' => 'msg_fail', 'condition_handle' => 'false']);

        $version->update(['status' => FlowVersionStatus::PUBLISHED]);
        $flow->update(['status' => FlowStatus::PUBLISHED, 'active_version_id' => $version->id]);

        $channel = Channel::create(['name' => 'Channel Multi Cond', 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'entry_flow_id' => $flow->id]);

        $res = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'cond_user'], 'Comprobar');
        $conversation = $res['conversation'];

        $lastMsg = Message::where('conversation_id', $conversation->id)->where('sender_type', MessageSenderType::BOT)->latest('sent_at')->first();
        $this->assertEquals('Acceso concedido para nivel premium.', $lastMsg->content);
    }

    /**
     * Test 11: Idempotency with external_message_id prevents duplicate state advances.
     */
    public function test_inbound_idempotency_prevents_duplicate_flow_advances(): void
    {
        $flow = Flow::create(['name' => 'Idempotency Test Flow', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);

        FlowNode::create(['flow_version_id' => $version->id, 'node_key' => 'start_1', 'type' => FlowNodeType::START, 'name' => 'Start']);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'q_age',
            'type' => FlowNodeType::QUESTION,
            'name' => 'Edad',
            'configuration' => ['text' => '¿Cuál es tu edad?', 'variable' => 'edad', 'input_type' => 'number'],
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_done',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Listo',
            'configuration' => ['text' => 'Tienes {{edad}} años.'],
        ]);

        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'start_1', 'target_node_key' => 'q_age']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'q_age', 'target_node_key' => 'msg_done']);

        $version->update(['status' => FlowVersionStatus::PUBLISHED]);
        $flow->update(['status' => FlowStatus::PUBLISHED, 'active_version_id' => $version->id]);

        $channel = Channel::create(['name' => 'Channel Idempotency', 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'entry_flow_id' => $flow->id]);

        $clientId = (string) Str::uuid();

        // Start
        $resStart = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'idem_user'], 'Hola');
        $this->assertEquals('q_age', $resStart['execution']->fresh()->currentNode->node_key);

        // Answer question with unique external_message_id
        $firstAnswer = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'idem_user'], '25', $clientId);
        $this->assertFalse($firstAnswer['is_duplicate']);
        $this->assertEquals(25, $firstAnswer['execution']->fresh()->context['edad']);

        $botMessagesCountBefore = Message::where('conversation_id', $firstAnswer['conversation']->id)
            ->where('sender_type', MessageSenderType::BOT)
            ->count();

        // Exact retry with same external_message_id (e.g. network timeout retry from widget)
        $secondAnswer = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'idem_user'], '25', $clientId);
        $this->assertTrue($secondAnswer['is_duplicate']);
        $this->assertEquals($firstAnswer['message']->id, $secondAnswer['message']->id);

        $botMessagesCountAfter = Message::where('conversation_id', $firstAnswer['conversation']->id)
            ->where('sender_type', MessageSenderType::BOT)
            ->count();

        // Bot must NOT emit additional duplicate responses
        $this->assertEquals($botMessagesCountBefore, $botMessagesCountAfter);
    }

    /**
     * Test 12: Question retry attempt metadata tracking.
     */
    public function test_question_retry_attempt_metadata_tracking(): void
    {
        $flow = Flow::create(['name' => 'Question Retry Flow', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);

        FlowNode::create(['flow_version_id' => $version->id, 'node_key' => 'start_1', 'type' => FlowNodeType::START, 'name' => 'Start']);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'q_email',
            'type' => FlowNodeType::QUESTION,
            'name' => 'Email',
            'configuration' => [
                'text' => 'Ingresa tu correo:',
                'variable' => 'email',
                'input_type' => 'email',
                'invalid_message' => 'El correo es inválido. Intenta de nuevo.',
            ],
        ]);

        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'start_1', 'target_node_key' => 'q_email']);
        $version->update(['status' => FlowVersionStatus::PUBLISHED]);
        $flow->update(['status' => FlowStatus::PUBLISHED, 'active_version_id' => $version->id]);

        $channel = Channel::create(['name' => 'Channel Retry Q', 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'entry_flow_id' => $flow->id]);

        $res1 = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'retry_user'], 'Hola');
        $exec = $res1['execution'];

        // Send invalid email
        $res2 = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'retry_user'], 'esto-no-es-un-email');
        $step = FlowExecutionStep::where('flow_execution_id', $exec->id)->where('status', 'waiting')->first();
        $this->assertNotNull($step);
        $this->assertEquals(2, $step->metadata['attempts']);

        // Send valid email
        $res3 = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'retry_user'], 'valido@empresa.com');
        $this->assertEquals('valido@empresa.com', $res3['execution']->fresh()->context['email']);
    }

    /**
     * Test 13: Transfer to human agent suspends bot automatic responses.
     */
    public function test_transfer_to_human_agent_suspends_bot(): void
    {
        $flow = Flow::create(['name' => 'Handoff Flow', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);

        FlowNode::create(['flow_version_id' => $version->id, 'node_key' => 'start_1', 'type' => FlowNodeType::START, 'name' => 'Start']);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'transfer_node',
            'type' => FlowNodeType::TRANSFER_TO_AGENT,
            'name' => 'Transferir',
            'configuration' => ['message' => 'Te transferimos con un asesor.'],
        ]);

        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'start_1', 'target_node_key' => 'transfer_node']);
        $version->update(['status' => FlowVersionStatus::PUBLISHED]);
        $flow->update(['status' => FlowStatus::PUBLISHED, 'active_version_id' => $version->id]);

        $channel = Channel::create(['name' => 'Channel Handoff', 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'entry_flow_id' => $flow->id]);

        $res1 = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'handoff_user'], 'Quiero un agente');
        $conversation = $res1['conversation']->fresh();

        $this->assertEquals(ConversationStatus::WAITING_AGENT, $conversation->status);

        // When user sends another message while waiting for agent, bot must NOT reply or restart flow
        $botMsgCountBefore = Message::where('conversation_id', $conversation->id)->where('sender_type', MessageSenderType::BOT)->count();
        $res2 = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'handoff_user'], '¿Hay alguien ahí?');
        $botMsgCountAfter = Message::where('conversation_id', $conversation->id)->where('sender_type', MessageSenderType::BOT)->count();

        $this->assertEquals($botMsgCountBefore, $botMsgCountAfter);
        $this->assertNull($res2['execution']);
        $this->assertEquals(ConversationStatus::WAITING_AGENT, $res2['conversation']->status);
    }
}
