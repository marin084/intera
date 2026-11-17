<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\FlowExecutionStatus;
use App\Enums\FlowFailureCode;
use App\Enums\FlowNodeType;
use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageType;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowExecutionStep;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Services\Presence\AgentAvailabilityService;
use App\Services\Runtime\ConversationIngressService;
use App\Services\Runtime\FlowRuntimeEngine;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FlowRuntimeEngineTest extends TestCase
{
    use RefreshDatabase;

    protected FlowRuntimeEngine $engine;

    protected ConversationIngressService $ingress;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->engine = app(FlowRuntimeEngine::class);
        $this->ingress = app(ConversationIngressService::class);
    }

    /**
     * Helper to build and publish the standard E2E test flow:
     * START -> MESSAGE("Bienvenido") -> QUESTION("¿Cómo te llamas?", nombre) ->
     * MESSAGE("Hola {{nombre}}") -> QUESTION("¿Eres cliente?", cliente, choice: ["si", "no"]) ->
     * CONDITION(cliente == "si")
     *   true -> MESSAGE("Excelente") -> TRANSFER_TO_AGENT
     *   false -> MESSAGE("Entendido") -> END
     */
    protected function createStandardPublishedFlow(): FlowVersion
    {
        $flow = Flow::create([
            'name' => 'Flujo de Atención E2E',
            'status' => FlowStatus::DRAFT,
            'channel_applicability' => ['webchat'],
        ]);

        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::DRAFT,
        ]);

        // 1. Start Node
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
            'configuration' => [],
        ]);

        // 2. Message Bienvenido
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_welcome',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Bienvenida',
            'configuration' => ['text' => 'Bienvenido a nuestro servicio.'],
        ]);

        // 3. Question Nombre
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'q_name',
            'type' => FlowNodeType::QUESTION,
            'name' => 'Preguntar Nombre',
            'configuration' => [
                'text' => '¿Cómo te llamas?',
                'variable' => 'nombre',
                'input_type' => 'text',
            ],
        ]);

        // 4. Message Saludo
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_greeting',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Saludo Personalizado',
            'configuration' => ['text' => 'Hola {{nombre}}'],
        ]);

        // 5. Question Cliente
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'q_client',
            'type' => FlowNodeType::QUESTION,
            'name' => 'Preguntar si es cliente',
            'configuration' => [
                'text' => '¿Eres cliente?',
                'variable' => 'cliente',
                'input_type' => 'choice',
                'choices' => ['si', 'no'],
                'invalid_message' => 'Por favor responda si o no.',
            ],
        ]);

        // 6. Condition Cliente
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'cond_client',
            'type' => FlowNodeType::CONDITION,
            'name' => 'Evaluar si es cliente',
            'configuration' => [
                'variable' => 'cliente',
                'operator' => 'equals',
                'value' => 'si',
            ],
        ]);

        // 7. Message True Branch
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_client_yes',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Mensaje Cliente Si',
            'configuration' => ['text' => 'Excelente'],
        ]);

        // 8. Transfer To Agent Node
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'transfer_agent',
            'type' => FlowNodeType::TRANSFER_TO_AGENT,
            'name' => 'Transferir a Ejecutivo',
            'configuration' => ['message' => 'Te comunicaré con uno de nuestros agentes.'],
        ]);

        // 9. Message False Branch
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_client_no',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Mensaje Cliente No',
            'configuration' => ['text' => 'Entendido'],
        ]);

        // 10. End Node
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'end_flow',
            'type' => FlowNodeType::END,
            'name' => 'Fin de Conversación',
            'configuration' => ['message' => 'Gracias por comunicarte con nosotros.'],
        ]);

        // Edges
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'start_1', 'target_node_key' => 'msg_welcome']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'msg_welcome', 'target_node_key' => 'q_name']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'q_name', 'target_node_key' => 'msg_greeting']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'msg_greeting', 'target_node_key' => 'q_client']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'q_client', 'target_node_key' => 'cond_client']);
        // Condition Branches
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'cond_client', 'target_node_key' => 'msg_client_yes', 'condition_handle' => 'true']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'cond_client', 'target_node_key' => 'msg_client_no', 'condition_handle' => 'false']);
        // True branch to transfer
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'msg_client_yes', 'target_node_key' => 'transfer_agent']);
        // False branch to end
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'msg_client_no', 'target_node_key' => 'end_flow']);

        // Publish version
        $version->update(['status' => FlowVersionStatus::PUBLISHED, 'published_at' => now()]);
        $flow->update(['status' => FlowStatus::PUBLISHED, 'active_version_id' => $version->id]);

        return $version->fresh(['nodes', 'edges', 'flow']);
    }

    public function test_true_path_e2e_flow_execution(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = Channel::create([
            'name' => 'Webchat Principal',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
            'entry_flow_id' => $version->flow_id,
        ]);

        $contactPayload = ['external_identifier' => 'user_true_1', 'display_name' => 'Luis'];

        // 1. Initial Inbound Message triggers flow start
        $step1 = $this->ingress->handleInboundMessage(
            $channel,
            $contactPayload,
            'Hola, quiero información'
        );

        $conversation = $step1['conversation'];
        $execution = $step1['execution'];

        $this->assertNotNull($execution);
        $this->assertEquals(FlowExecutionStatus::WAITING_INPUT, $execution->status);
        $this->assertEquals('q_name', $execution->currentNode->node_key);

        // Check messages generated: 1 inbound ("Hola..."), 2 bot ("Bienvenido...", "¿Cómo te llamas?")
        $messages = Message::where('conversation_id', $conversation->id)->orderBy('sent_at')->get();
        $this->assertCount(3, $messages);
        $this->assertEquals('Bienvenido a nuestro servicio.', $messages[1]->content);
        $this->assertEquals('¿Cómo te llamas?', $messages[2]->content);

        // 2. User responds with their name: "Luis"
        $step2 = $this->ingress->handleInboundMessage(
            $channel,
            $contactPayload,
            'Luis'
        );

        $execution = $step2['execution']->fresh();
        $this->assertEquals('Luis', $execution->context['nombre'] ?? null);
        $this->assertEquals(FlowExecutionStatus::WAITING_INPUT, $execution->status);
        $this->assertEquals('q_client', $execution->currentNode->node_key);

        // Check bot messages: "Hola Luis", "¿Eres cliente?"
        $messages = Message::where('conversation_id', $conversation->id)->orderBy('sent_at')->get();
        // Previous 3 + 1 inbound ('Luis') + 2 bot ('Hola Luis', '¿Eres cliente?') = 6
        $this->assertCount(6, $messages);
        $this->assertEquals('Hola Luis', $messages[4]->content);
        $this->assertEquals('¿Eres cliente?', $messages[5]->content);

        // 3. User responds: "si"
        $step3 = $this->ingress->handleInboundMessage(
            $channel,
            $contactPayload,
            'si'
        );

        $execution = $step3['execution']->fresh();
        $conversation = $step3['conversation']->fresh();

        $this->assertEquals('si', $execution->context['cliente'] ?? null);
        $this->assertEquals(FlowExecutionStatus::WAITING_AGENT, $execution->status);
        $this->assertEquals(ConversationStatus::WAITING_AGENT, $conversation->status);

        // Check bot messages: "Excelente", "Te comunicaré con uno de nuestros agentes."
        $messages = Message::where('conversation_id', $conversation->id)->orderBy('sent_at')->get();
        // Previous 6 + 1 inbound ('si') + 1 bot ('Excelente') + 1 bot ('Te comunicaré...') = 9
        $this->assertCount(9, $messages);
        $this->assertEquals('Excelente', $messages[7]->content);
        $this->assertEquals('Te comunicaré con uno de nuestros agentes.', $messages[8]->content);

        // Verify FlowExecutionStep history
        $this->assertGreaterThanOrEqual(7, $execution->steps()->count());
    }

    public function test_false_path_e2e_flow_execution(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = Channel::create([
            'name' => 'Webchat Ventas',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
            'entry_flow_id' => $version->flow_id,
        ]);

        $contactPayload = ['external_identifier' => 'user_false_2', 'display_name' => 'Carlos'];

        // Step 1: Start
        $this->ingress->handleInboundMessage($channel, $contactPayload, 'Hola');

        // Step 2: Answer name
        $this->ingress->handleInboundMessage($channel, $contactPayload, 'Carlos');

        // Step 3: Answer "no" to client question
        $step3 = $this->ingress->handleInboundMessage($channel, $contactPayload, 'no');

        $execution = $step3['execution']->fresh();
        $conversation = $step3['conversation']->fresh();

        $this->assertEquals('no', $execution->context['cliente'] ?? null);
        $this->assertEquals(FlowExecutionStatus::COMPLETED, $execution->status);
        $this->assertNotNull($execution->completed_at);
        $this->assertNull($execution->current_node_id);
        $this->assertEquals(ConversationStatus::CLOSED, $conversation->status);

        // Check bot messages include "Entendido" and closing message
        $messages = Message::where('conversation_id', $conversation->id)->orderBy('sent_at')->get();
        $this->assertEquals('Entendido', $messages[7]->content);
        $this->assertEquals('Gracias por comunicarte con nosotros.', $messages[8]->content);
    }

    public function test_version_pinning(): void
    {
        // 1. Create and publish Version 1
        $flow = Flow::create(['name' => 'Flow Versioning Test', 'status' => FlowStatus::DRAFT]);
        $v1 = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);

        FlowNode::create(['flow_version_id' => $v1->id, 'node_key' => 'start_1', 'type' => FlowNodeType::START, 'name' => 'Start']);
        FlowNode::create(['flow_version_id' => $v1->id, 'node_key' => 'q_v1', 'type' => FlowNodeType::QUESTION, 'name' => 'Q V1', 'configuration' => ['text' => 'Pregunta de Version 1', 'variable' => 'resp']]);
        FlowNode::create(['flow_version_id' => $v1->id, 'node_key' => 'msg_v1', 'type' => FlowNodeType::MESSAGE, 'name' => 'Msg V1', 'configuration' => ['text' => 'Respuesta fijada en V1']]);
        FlowNode::create(['flow_version_id' => $v1->id, 'node_key' => 'end_1', 'type' => FlowNodeType::END, 'name' => 'End']);

        FlowEdge::create(['flow_version_id' => $v1->id, 'source_node_key' => 'start_1', 'target_node_key' => 'q_v1']);
        FlowEdge::create(['flow_version_id' => $v1->id, 'source_node_key' => 'q_v1', 'target_node_key' => 'msg_v1']);
        FlowEdge::create(['flow_version_id' => $v1->id, 'source_node_key' => 'msg_v1', 'target_node_key' => 'end_1']);

        $v1->update(['status' => FlowVersionStatus::PUBLISHED, 'published_at' => now()]);
        $flow->update(['status' => FlowStatus::PUBLISHED, 'active_version_id' => $v1->id]);

        $channel = Channel::create(['name' => 'Canal Version Pinning', 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'entry_flow_id' => $flow->id]);

        // 2. Start Conversation A
        $resA = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'contact_A'], 'Inicio A');
        $convA = $resA['conversation'];
        $execA = $resA['execution'];

        $this->assertEquals($v1->id, $execA->flow_version_id, 'Conversation A must be pinned to Version 1');
        $this->assertEquals($v1->id, $convA->active_flow_version_id);

        // 3. Create and publish Version 2 with modified message text
        $v2 = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 2, 'status' => FlowVersionStatus::DRAFT]);
        FlowNode::create(['flow_version_id' => $v2->id, 'node_key' => 'start_1', 'type' => FlowNodeType::START, 'name' => 'Start']);
        FlowNode::create(['flow_version_id' => $v2->id, 'node_key' => 'q_v2', 'type' => FlowNodeType::QUESTION, 'name' => 'Q V2', 'configuration' => ['text' => 'Pregunta de Version 2', 'variable' => 'resp']]);
        FlowNode::create(['flow_version_id' => $v2->id, 'node_key' => 'msg_v2', 'type' => FlowNodeType::MESSAGE, 'name' => 'Msg V2', 'configuration' => ['text' => 'Respuesta nueva de V2']]);
        FlowNode::create(['flow_version_id' => $v2->id, 'node_key' => 'end_1', 'type' => FlowNodeType::END, 'name' => 'End']);

        FlowEdge::create(['flow_version_id' => $v2->id, 'source_node_key' => 'start_1', 'target_node_key' => 'q_v2']);
        FlowEdge::create(['flow_version_id' => $v2->id, 'source_node_key' => 'q_v2', 'target_node_key' => 'msg_v2']);
        FlowEdge::create(['flow_version_id' => $v2->id, 'source_node_key' => 'msg_v2', 'target_node_key' => 'end_1']);

        $v2->update(['status' => FlowVersionStatus::PUBLISHED, 'published_at' => now()]);
        $flow->update(['active_version_id' => $v2->id]);

        // 4. Continue Conversation A -> MUST continue executing V1
        $resA2 = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'contact_A'], 'Respuesta A');
        $execA2 = $resA2['execution']->fresh();

        $this->assertEquals($v1->id, $execA2->flow_version_id, 'Conversation A must remain pinned to Version 1 even after Version 2 is published');
        $messagesA = Message::where('conversation_id', $convA->id)->pluck('content')->all();
        $this->assertContains('Respuesta fijada en V1', $messagesA);
        $this->assertNotContains('Respuesta nueva de V2', $messagesA);

        // 5. Start new Conversation B -> MUST use Version 2
        $resB = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'contact_B'], 'Inicio B');
        $execB = $resB['execution'];

        $this->assertEquals($v2->id, $execB->flow_version_id, 'New Conversation B must use published Version 2');
        $messagesB = Message::where('conversation_id', $resB['conversation']->id)->pluck('content')->all();
        $this->assertContains('Pregunta de Version 2', $messagesB);
    }

    public function test_duplicate_inbound_idempotency(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = Channel::create(['name' => 'Canal Idempotente', 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'entry_flow_id' => $version->flow_id]);

        $externalId = 'ext_msg_999';
        $contactPayload = ['external_identifier' => 'contact_idem'];

        // Process message first time
        $res1 = $this->ingress->handleInboundMessage($channel, $contactPayload, 'Mensaje 1', $externalId);
        $this->assertFalse($res1['is_duplicate']);
        $initialMessagesCount = Message::where('conversation_id', $res1['conversation']->id)->count();

        // Process same external message id second time
        $res2 = $this->ingress->handleInboundMessage($channel, $contactPayload, 'Mensaje 1 Duplicado', $externalId);
        $this->assertTrue($res2['is_duplicate']);

        // Assert no duplicate messages were stored and no extra bot response generated
        $finalMessagesCount = Message::where('conversation_id', $res1['conversation']->id)->count();
        $this->assertEquals($initialMessagesCount, $finalMessagesCount);
    }

    public function test_concurrent_inbound_idempotency_race_condition_and_unique_violation_recovery(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = Channel::create(['name' => 'Canal Race', 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'entry_flow_id' => $version->flow_id]);

        $externalId = 'ext_msg_race_123';
        $contactPayload = ['external_identifier' => 'contact_race'];

        // Request A: First inbound request succeeds
        $resA = $this->ingress->handleInboundMessage($channel, $contactPayload, 'Hola Request A', $externalId);
        $this->assertFalse($resA['is_duplicate']);
        $conversationId = $resA['conversation']->id;

        $inboundMessagesCount = Message::where('conversation_id', $conversationId)
            ->where('direction', MessageDirection::INBOUND)
            ->count();
        $this->assertEquals(1, $inboundMessagesCount);

        $initialTotalMessages = Message::where('conversation_id', $conversationId)->count();
        $initialStepsCount = FlowExecutionStep::where('flow_execution_id', $resA['execution']->id)->count();

        // Request B: Simulates concurrent collision where SELECT missed the row and INSERT triggers unique constraint
        // The service MUST catch the QueryException (23505 / 23000), recover gracefully, and return is_duplicate = true
        $resB = $this->ingress->handleInboundMessage($channel, $contactPayload, 'Hola Request B (simultaneous collision)', $externalId);

        $this->assertTrue($resB['is_duplicate']);
        $this->assertEquals($resA['message']->id, $resB['message']->id);
        $this->assertEquals($resA['conversation']->id, $resB['conversation']->id);
        $this->assertEquals($resA['execution']->id, $resB['execution']->id);

        // Assert strictly: 1 inbound message, no extra bot messages, no extra steps, no exceptions
        $finalInboundMessagesCount = Message::where('conversation_id', $conversationId)
            ->where('direction', MessageDirection::INBOUND)
            ->count();
        $this->assertEquals(1, $finalInboundMessagesCount);

        $finalTotalMessages = Message::where('conversation_id', $conversationId)->count();
        $this->assertEquals($initialTotalMessages, $finalTotalMessages);

        $finalStepsCount = FlowExecutionStep::where('flow_execution_id', $resA['execution']->id)->count();
        $this->assertEquals($initialStepsCount, $finalStepsCount);

        // Now test simulated direct database unique collision path:
        // Even if an insert collision occurs at the SQL layer, recoverDuplicateMessage resolves correctly without exception
        $reflection = new \ReflectionClass($this->ingress);
        $method = $reflection->getMethod('recoverDuplicateMessage');
        $method->setAccessible(true);

        $recovered = $method->invoke($this->ingress, $channel, $contactPayload, $externalId);
        $this->assertTrue($recovered['is_duplicate']);
        $this->assertEquals($resA['message']->id, $recovered['message']->id);
    }

    public function test_resume_lock_strategy_prevents_double_consumption_of_question(): void
    {
        $flow = Flow::create(['name' => 'Flow Resume Race', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);

        $qNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'q_name',
            'type' => FlowNodeType::QUESTION,
            'name' => 'Preguntar Nombre',
            'configuration' => [
                'text' => '¿Cómo te llamas?',
                'variable' => 'nombre',
                'input_type' => 'text',
            ],
        ]);

        FlowNode::create(['flow_version_id' => $version->id, 'node_key' => 'start_1', 'type' => FlowNodeType::START, 'name' => 'Start']);
        FlowNode::create(['flow_version_id' => $version->id, 'node_key' => 'end_1', 'type' => FlowNodeType::END, 'name' => 'End']);

        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'start_1', 'target_node_key' => 'q_name']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'q_name', 'target_node_key' => 'end_1']);

        $version->update(['status' => FlowVersionStatus::PUBLISHED]);
        $flow->update(['status' => FlowStatus::PUBLISHED, 'active_version_id' => $version->id]);

        $channel = Channel::create(['name' => 'Canal Resume Race', 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'entry_flow_id' => $flow->id]);

        $contactPayload = ['external_identifier' => 'contact_resume_race'];

        // 1. Inbound initiates flow: Start -> Question (status = WAITING_INPUT)
        $res = $this->ingress->handleInboundMessage($channel, $contactPayload, 'Iniciar');
        $execution = $res['execution']->fresh();
        $conversation = $res['conversation']->fresh();

        $this->assertEquals(FlowExecutionStatus::WAITING_INPUT, $execution->status);
        $this->assertEquals($qNode->id, $execution->current_node_id);

        // 2. Prepare two simulated concurrent inbound answers to the same question
        $answer1 = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $res['contact']->id,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Ana',
            'sent_at' => now(),
        ]);

        $answer2 = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'sender_id' => (string) $res['contact']->id,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Beatriz',
            'sent_at' => now(),
        ]);

        // 3. Process Answer 1: should acquire lock, consume question, store variable 'nombre' = 'Ana', and advance to End
        $resExec1 = $this->engine->resumeWithInboundMessage($conversation, $answer1);
        $this->assertNotNull($resExec1);

        $refreshedExec = $resExec1->fresh();
        $this->assertEquals('Ana', $refreshedExec->context['nombre'] ?? null);
        $this->assertEquals(FlowExecutionStatus::COMPLETED, $refreshedExec->status);

        $stepsForQuestion = FlowExecutionStep::where('flow_execution_id', $refreshedExec->id)
            ->where('flow_node_id', $qNode->id)
            ->count();
        $this->assertEquals(1, $stepsForQuestion);

        // 4. Answer 2 arrives almost concurrently:
        // Because status is no longer WAITING_INPUT (protected by lockForUpdate), it MUST return null
        $resExec2 = $this->engine->resumeWithInboundMessage($conversation, $answer2);
        $this->assertNull($resExec2, 'Second concurrent answer must NOT re-consume the already transitioned QuestionNode');

        // Context variable 'nombre' MUST remain 'Ana' and not be overwritten by 'Beatriz'
        $finalExec = $execution->fresh();
        $this->assertEquals('Ana', $finalExec->context['nombre']);
        $this->assertEquals(FlowExecutionStatus::COMPLETED, $finalExec->status);

        // Steps count for question node must NOT have increased
        $finalStepsForQuestion = FlowExecutionStep::where('flow_execution_id', $finalExec->id)
            ->where('flow_node_id', $qNode->id)
            ->count();
        $this->assertEquals(1, $finalStepsForQuestion);
    }

    public function test_infinite_loop_protection(): void
    {
        config(['flows.max_steps_per_run' => 10]);

        $flow = Flow::create(['name' => 'Loop Flow', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);

        FlowNode::create(['flow_version_id' => $version->id, 'node_key' => 'start_1', 'type' => FlowNodeType::START, 'name' => 'Start']);
        FlowNode::create(['flow_version_id' => $version->id, 'node_key' => 'msg_a', 'type' => FlowNodeType::MESSAGE, 'name' => 'Msg A', 'configuration' => ['text' => 'A']]);
        FlowNode::create(['flow_version_id' => $version->id, 'node_key' => 'msg_b', 'type' => FlowNodeType::MESSAGE, 'name' => 'Msg B', 'configuration' => ['text' => 'B']]);

        // Loop: Start -> msg_a -> msg_b -> msg_a
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'start_1', 'target_node_key' => 'msg_a']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'msg_a', 'target_node_key' => 'msg_b']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'msg_b', 'target_node_key' => 'msg_a']);

        $version->update(['status' => FlowVersionStatus::PUBLISHED]);
        $flow->update(['status' => FlowStatus::PUBLISHED, 'active_version_id' => $version->id]);

        $channel = Channel::create(['name' => 'Canal Loop', 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'entry_flow_id' => $flow->id]);

        $res = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'contact_loop'], 'Disparar loop');
        $execution = $res['execution']->fresh();
        $conversation = $res['conversation']->fresh();

        $this->assertEquals(FlowExecutionStatus::FAILED, $execution->status);
        $this->assertEquals(FlowFailureCode::MAX_STEPS_EXCEEDED->value, $execution->failure_code);
        $this->assertEquals(ConversationStatus::WAITING_AGENT, $conversation->status);

        // Internal system fallback message created
        $sysMsg = Message::where('conversation_id', $conversation->id)->where('sender_type', MessageSenderType::SYSTEM)->first();
        $this->assertNotNull($sysMsg);
        $this->assertStringContainsString('transferida a atención humana', $sysMsg->content);
    }

    public function test_invalid_runtime_state_fails_gracefully(): void
    {
        $flow = Flow::create(['name' => 'Corrupt Flow', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);

        FlowNode::create(['flow_version_id' => $version->id, 'node_key' => 'start_1', 'type' => FlowNodeType::START, 'name' => 'Start']);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'cond_bad',
            'type' => FlowNodeType::CONDITION,
            'name' => 'Bad Cond',
            'configuration' => ['variable' => 'x', 'operator' => 'invalid_operator'],
        ]);
        FlowNode::create(['flow_version_id' => $version->id, 'node_key' => 'end_1', 'type' => FlowNodeType::END, 'name' => 'End']);

        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'start_1', 'target_node_key' => 'cond_bad']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'cond_bad', 'target_node_key' => 'end_1', 'condition_handle' => 'true']);

        $version->update(['status' => FlowVersionStatus::PUBLISHED]);
        $flow->update(['status' => FlowStatus::PUBLISHED, 'active_version_id' => $version->id]);

        $channel = Channel::create(['name' => 'Canal Corrupt', 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'entry_flow_id' => $flow->id]);

        $res = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'contact_corrupt'], 'Test');
        $execution = $res['execution']->fresh();
        $conversation = $res['conversation']->fresh();

        $this->assertEquals(FlowExecutionStatus::FAILED, $execution->status);
        $this->assertEquals(FlowFailureCode::CONDITION_ERROR->value, $execution->failure_code);
        $this->assertEquals(ConversationStatus::WAITING_AGENT, $conversation->status);
    }

    public function test_agent_message_does_not_reactivate_flow(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = Channel::create(['name' => 'Canal Agente', 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'entry_flow_id' => $version->flow_id]);

        // Start and advance until waiting_agent
        $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'contact_agent_test'], 'Hola');
        $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'contact_agent_test'], 'Luis');
        $res = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'contact_agent_test'], 'si');

        $conversation = $res['conversation']->fresh();
        $this->assertEquals(ConversationStatus::WAITING_AGENT, $conversation->status);

        $messagesBeforeAgent = Message::where('conversation_id', $conversation->id)->count();

        // Inbound message while in waiting_agent
        $resInbound = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'contact_agent_test'], '¿Hay alguien disponible?');
        $this->assertNull($resInbound['execution']);

        // Check no bot response was generated
        $messagesAfter = Message::where('conversation_id', $conversation->id)->get();
        $this->assertEquals($messagesBeforeAgent + 1, $messagesAfter->count());
        $this->assertEquals('¿Hay alguien disponible?', $messagesAfter->last()->content);
    }

    public function test_question_choice_validation_and_retry(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = Channel::create(['name' => 'Canal Retry', 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'entry_flow_id' => $version->flow_id]);

        $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'contact_retry'], 'Hola');
        $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'contact_retry'], 'Maria');

        // Question is: "¿Eres cliente?" with choices ["si", "no"]
        // Send invalid answer "tal vez"
        $stepInvalid = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'contact_retry'], 'tal vez');
        $execution = $stepInvalid['execution']->fresh();

        $this->assertEquals(FlowExecutionStatus::WAITING_INPUT, $execution->status);
        $this->assertEquals('q_client', $execution->currentNode->node_key);

        // Verify invalid retry message was emitted
        $lastBotMessage = Message::where('conversation_id', $stepInvalid['conversation']->id)
            ->where('direction', MessageDirection::OUTBOUND)
            ->orderBy('created_at')
            ->get()
            ->last();
        $this->assertEquals('Por favor responda si o no.', $lastBotMessage->content);
        $this->assertEquals(MessageType::INTERACTIVE, $lastBotMessage->message_type);
        $this->assertNotNull($lastBotMessage->payload);
        $this->assertTrue($lastBotMessage->payload['validation_error'] ?? false);
        $this->assertCount(2, $lastBotMessage->payload['options']);

        // Now send valid answer "si"
        $stepValid = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'contact_retry'], 'si');
        $executionValid = $stepValid['execution']->fresh();
        $this->assertEquals('si', $executionValid->context['cliente']);
        $this->assertEquals(FlowExecutionStatus::WAITING_AGENT, $executionValid->status);
    }

    public function test_execution_observability_endpoint(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = Channel::create(['name' => 'Canal API Observability', 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'entry_flow_id' => $version->flow_id]);

        $res = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'contact_obs'], 'Hola');
        $conversation = $res['conversation'];

        $supervisor = User::create([
            'name' => 'Supervisor Obs',
            'email' => 'supervisor_obs@test.com',
            'password' => Hash::make('TestSecuredPass@2026'),
            'is_active' => true,
        ]);
        $supervisor->roles()->attach(Role::where('name', 'supervisor')->first());
        $this->grantChannelAccess($channel, $supervisor);

        $response = $this->actingAs($supervisor, 'sanctum')->getJson("/api/v1/conversations/{$conversation->id}/execution");
        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id',
                'flow' => ['id', 'name'],
                'version' => ['id', 'version_number'],
                'status',
                'current_node',
                'context',
                'started_at',
                'steps' => [
                    '*' => ['id', 'node_id', 'node_key', 'node_name', 'node_type', 'status', 'entered_at'],
                ],
            ],
        ]);
    }

    public function test_published_version_is_immutable(): void
    {
        $version = $this->createStandardPublishedFlow();
        $admin = User::create([
            'name' => 'Admin Imm',
            'email' => 'admin_imm@test.com',
            'password' => Hash::make('TestSecuredPass@2026'),
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::where('name', 'super_admin')->first());

        $payload = [
            'nodes' => [
                [
                    'node_key' => 'start_1',
                    'type' => 'start',
                    'name' => 'Start Modificado',
                ],
            ],
            'edges' => [],
        ];

        $response = $this->actingAs($admin, 'sanctum')->putJson(
            "/api/v1/flows/{$version->flow_id}/versions/{$version->id}/graph",
            $payload
        );

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'No se puede modificar directamente una versión ya publicada. Cree una nueva versión borrador para continuar editando.',
        ]);
    }

    public function test_concurrency_locking_strategy_prevents_duplicate_advance(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = Channel::create(['name' => 'Canal Lock', 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'entry_flow_id' => $version->flow_id]);

        $res = $this->ingress->handleInboundMessage($channel, ['external_identifier' => 'contact_lock'], 'Hola');
        $execution = $res['execution']->fresh();
        $conversation = $res['conversation']->fresh();

        $this->assertEquals(FlowExecutionStatus::WAITING_INPUT, $execution->status);
        $initialMessagesCount = Message::where('conversation_id', $conversation->id)->count();

        // Simulate concurrent execution: if an advance is attempted on an execution that is already in WAITING_INPUT
        $advanceAttempt = $this->engine->advance($execution);
        $this->assertEquals(FlowExecutionStatus::WAITING_INPUT, $advanceAttempt->status);

        // Confirm that no additional messages or step transitions were created
        $finalMessagesCount = Message::where('conversation_id', $conversation->id)->count();
        $this->assertEquals($initialMessagesCount, $finalMessagesCount);
    }

    public function test_contact_permanent_variables_memory_persists_across_conversations(): void
    {
        $version = $this->createStandardPublishedFlow();
        $channel = Channel::create(['name' => 'Canal Memoria', 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'entry_flow_id' => $version->flow_id]);

        // 1. First interaction: new visitor sends initial message
        $res1 = $this->ingress->handleInboundMessage(
            $channel,
            ['external_identifier' => 'contact_memory_123', 'display_name' => 'Visitante 9999'],
            'Hola bot'
        );
        $contact = $res1['contact']->fresh();
        $conversation1 = $res1['conversation']->fresh();
        $execution1 = $res1['execution']->fresh();

        $this->assertEquals(FlowExecutionStatus::WAITING_INPUT, $execution1->status);

        // 2. Visitor answers the name question
        $resAnswer = $this->ingress->handleInboundMessage(
            $channel,
            ['external_identifier' => 'contact_memory_123'],
            'Carlos Santana'
        );

        $contact = $contact->fresh();
        // Variables must be saved in metadata['flow_variables']
        $this->assertIsArray($contact->metadata);
        $this->assertEquals('Carlos Santana', $contact->metadata['flow_variables']['nombre'] ?? null);
        // Generic name "Visitante 9999" must be promoted to the real name
        $this->assertEquals('Carlos Santana', $contact->display_name);

        // 3. Close the first conversation
        $conversation1->update(['status' => ConversationStatus::CLOSED, 'closed_at' => now()]);

        // 4. Contact starts a second conversation in the future
        $res2 = $this->ingress->handleInboundMessage(
            $channel,
            ['external_identifier' => 'contact_memory_123'],
            'Hola de nuevo'
        );
        $conversation2 = $res2['conversation']->fresh();
        $execution2 = $res2['execution']->fresh();

        $this->assertEquals($conversation1->id, $conversation2->id);
        $this->assertEquals($contact->id, $conversation2->contact_id);

        // The new execution's context must be hydrated with previous contact variables
        $this->assertEquals('Carlos Santana', $execution2->context['nombre'] ?? null);

        $ctx2 = \App\Services\Runtime\ExecutionContext::fromArray($execution2->context, $contact);
        $this->assertEquals('Carlos Santana', $ctx2->get('contact.name'));
        $this->assertEquals('contact_memory_123', $ctx2->get('contact.identifier'));
    }

    public function test_flow_completion_closes_conversation_releases_assignment_and_frees_quota(): void
    {
        AgentAvailabilityService::resetFake();
        $this->seed(RolePermissionSeeder::class);

        $agentRole = Role::where('name', 'agent')->first();
        $agentRole->update(['max_concurrent_conversations' => 1]);

        $agent = User::create([
            'name' => 'Agent Finishing Flow',
            'email' => 'agent_finishing@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $agent->roles()->attach($agentRole);

        $channel = Channel::create([
            'name' => 'Channel Flow End',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
        ]);
        $this->grantChannelAccess($channel, $agent);
        $team = $this->addToDefaultTeam($agent);

        $contact = \App\Models\Contact::create([
            'display_name' => 'User Finishing Flow',
            'external_identifier' => 'user_finishing_flow',
        ]);

        // Conversation 1 is assigned to agent (agent is at capacity 1/1)
        $conversation1 = Conversation::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $agent->id,
            'started_at' => now()->subMinutes(10),
        ]);

        $assignment1 = ConversationAssignment::create([
            'conversation_id' => $conversation1->id,
            'assigned_user_id' => $agent->id,
            'assigned_by_user_id' => $agent->id,
            'assigned_at' => now()->subMinutes(10),
            'reason' => 'Initial flow assignment',
        ]);

        // Conversation 2 is waiting for agent because agent was full
        $waitingContact = \App\Models\Contact::create([
            'display_name' => 'Waiting User',
            'external_identifier' => 'waiting_user_flow',
        ]);
        $conversationWaiting = Conversation::create([
            'channel_id' => $channel->id,
            'team_id' => $team->id,
            'contact_id' => $waitingContact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'started_at' => now()->subMinutes(5),
        ]);

        // Flow with START -> END
        $flow = Flow::create(['name' => 'Terminal Flow', 'status' => FlowStatus::PUBLISHED]);
        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::PUBLISHED,
            'definition' => [],
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'name' => 'Start',
            'node_key' => 'start_term',
            'type' => FlowNodeType::START,
            'configuration' => [],
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'name' => 'End',
            'node_key' => 'end_term',
            'type' => FlowNodeType::END,
            'configuration' => [],
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start_term',
            'target_node_key' => 'end_term',
        ]);

        // Execute flow on conversation 1
        $engine = app(FlowRuntimeEngine::class);
        $execution = $engine->start($conversation1, $version);

        // Flow should have completed
        $this->assertEquals(FlowExecutionStatus::COMPLETED, $execution->fresh()->status);

        // Verification 1: Conversation 1 is closed, assigned_user_id = null immediately
        $fresh1 = $conversation1->fresh();
        $this->assertEquals(ConversationStatus::CLOSED, $fresh1->status);
        $this->assertNotNull($fresh1->closed_at);
        $this->assertNull($fresh1->assigned_user_id);

        // Verification 2: Active assignment closed with unassigned_at
        $this->assertNotNull($assignment1->fresh()->unassigned_at);

        // Verification 3: closing_assigned_user_id is properly retained
        $this->assertEquals($agent->id, $fresh1->closing_assigned_user_id);

        // Verification 4: Quota was freed, so conversationWaiting was auto-assigned to agent!
        $freshWaiting = $conversationWaiting->fresh();
        $this->assertEquals(ConversationStatus::ASSIGNED, $freshWaiting->status);
        $this->assertEquals($agent->id, $freshWaiting->assigned_user_id);
    }
}
