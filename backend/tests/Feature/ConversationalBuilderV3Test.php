<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\FlowExecutionStatus;
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
use App\Services\FlowValidator;
use App\Services\Runtime\FlowRuntimeEngine;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ConversationalBuilderV3Test extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::create([
            'name' => 'Admin Test',
            'email' => 'admin_test@conexa.local',
            'password' => Hash::make('Pass123456789@'),
            'is_active' => true,
        ]);

        $this->adminUser->roles()->attach(Role::where('name', 'super_admin')->first());
    }

    /**
     * Mandatory Test Scenario:
     * 1. Inicio (start)
     * 2. Menú principal con 8 opciones
     * 3. Rama de consulta
     * 4. Rama de captura de datos (pregunta con variable personalizada)
     * 5. Variable personalizada
     * 6. Petición HTTP con respuesta simulada
     * 7. Extracción de variable JSON
     * 8. Condición sobre la variable
     * 9. Salto hacia un bloque existente (goto)
     * 10. Mensaje final
     */
    public function test_mandatory_scenario_e2e_flow_with_all_v3_blocks(): void
    {
        $flow = Flow::create([
            'name' => 'Flujo Conversacional Completo V3',
            'status' => FlowStatus::DRAFT,
            'channel_applicability' => ['webchat'],
        ]);

        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::DRAFT,
        ]);

        // 1. Inicio
        $nodeStart = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_start',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
            'configuration' => [],
            'position_x' => 100,
            'position_y' => 100,
        ]);

        // 2. Menú principal con 8 opciones
        $options = [
            ['id' => 'opt_1', 'text' => 'Ofertar', 'target_node_key' => 'node_msg_oferta'],
            ['id' => 'opt_2', 'text' => 'Compromiso de ofrenda', 'target_node_key' => 'node_msg_compromiso'],
            ['id' => 'opt_3', 'text' => 'Petición de oración', 'target_node_key' => 'node_msg_oracion'],
            ['id' => 'opt_4', 'text' => 'Información de oficinas', 'target_node_key' => 'node_msg_oficinas'],
            ['id' => 'opt_5', 'text' => 'Compartir testimonio', 'target_node_key' => 'node_msg_testimonio'],
            ['id' => 'opt_6', 'text' => 'Descargas', 'target_node_key' => 'node_msg_descargas'],
            ['id' => 'opt_7', 'text' => 'Consultas', 'target_node_key' => 'node_ask_pedido'],
            ['id' => 'opt_8', 'text' => 'Salir', 'target_node_key' => 'node_end'],
        ];

        $nodeMenu = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_menu',
            'type' => FlowNodeType::MENU,
            'name' => 'Menú Principal',
            'configuration' => [
                'text' => '¡Hola! Bienvenido al canal oficial. ¿En qué podemos servirte hoy?',
                'options' => $options,
                'variable' => 'menu_seleccionado',
            ],
            'position_x' => 100,
            'position_y' => 200,
        ]);

        // Branch 7: Consulta de pedido
        // 4. Captura de datos (Pregunta)
        $nodeAskPedido = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_ask_pedido',
            'type' => FlowNodeType::QUESTION,
            'name' => 'Captura Número de Pedido',
            'configuration' => [
                'text' => 'Por favor, ingresa tu número de pedido o consulta:',
                'variable' => 'numero_pedido',
                'input_type' => 'text',
            ],
            'position_x' => 300,
            'position_y' => 350,
        ]);

        // 6. Petición HTTP con respuesta simulada
        // 7. Extracción de variable JSON (order.status -> estado_pedido)
        $nodeHttp = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_http_pedido',
            'type' => FlowNodeType::HTTP_REQUEST,
            'name' => 'Consultar API Pedidos',
            'configuration' => [
                'method' => 'GET',
                'url' => 'https://api.example.com/orders/{{numero_pedido}}',
                'mock_response' => [
                    'status' => 200,
                    'data' => [
                        'order' => [
                            'id' => 12345,
                            'status' => 'delivered',
                            'tracking_code' => 'TRK-9876',
                        ],
                    ],
                ],
                'response_mapping' => [
                    ['json_path' => 'order.status', 'variable_name' => 'estado_pedido'],
                    ['json_path' => 'order.tracking_code', 'variable_name' => 'codigo_rastreo'],
                ],
            ],
            'position_x' => 300,
            'position_y' => 500,
        ]);

        // 8. Condición sobre la variable
        $nodeCondition = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_cond_estado',
            'type' => FlowNodeType::CONDITION,
            'name' => '¿Pedido Entregado?',
            'configuration' => [
                'variable' => 'estado_pedido',
                'operator' => 'equals',
                'value' => 'delivered',
            ],
            'position_x' => 300,
            'position_y' => 650,
        ]);

        // 9. Salto hacia un bloque existente (Goto)
        $nodeGoto = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_goto_final',
            'type' => FlowNodeType::GOTO,
            'name' => 'Salto a Mensaje Final',
            'configuration' => [
                'target_node_key' => 'node_msg_final',
            ],
            'position_x' => 300,
            'position_y' => 800,
        ]);

        $nodeFalseMsg = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_msg_en_camino',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Mensaje En Camino',
            'configuration' => [
                'text' => 'Tu pedido sigue en camino. Rastreo: {{codigo_rastreo}}.',
            ],
            'position_x' => 550,
            'position_y' => 800,
        ]);

        // 10. Mensaje final
        $nodeFinalMsg = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_msg_final',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Mensaje Final de Éxito',
            'configuration' => [
                'text' => 'Tu pedido ha sido confirmado como: {{estado_pedido}}. ¡Muchas gracias!',
            ],
            'position_x' => 300,
            'position_y' => 950,
        ]);

        $nodeEnd = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_end',
            'type' => FlowNodeType::END,
            'name' => 'Fin de Conversación',
            'configuration' => [],
            'position_x' => 300,
            'position_y' => 1100,
        ]);

        // Dummy nodes for other menu options to satisfy reachability/edges
        $otherNodes = [
            'node_msg_oferta' => 'Gracias por tu deseo de ofrendar.',
            'node_msg_compromiso' => 'Información sobre compromiso de ofrenda.',
            'node_msg_oracion' => 'Petición de oración registrada.',
            'node_msg_oficinas' => 'Nuestras oficinas atienden de 8am a 5pm.',
            'node_msg_testimonio' => 'Gracias por compartir tu testimonio.',
            'node_msg_descargas' => 'Aquí tienes el enlace de descargas.',
        ];

        foreach ($otherNodes as $key => $txt) {
            FlowNode::create([
                'flow_version_id' => $version->id,
                'node_key' => $key,
                'type' => FlowNodeType::MESSAGE,
                'name' => $key,
                'configuration' => ['text' => $txt],
                'position_x' => 10,
                'position_y' => 400,
            ]);
            FlowEdge::create([
                'flow_version_id' => $version->id,
                'source_node_key' => $key,
                'target_node_key' => 'node_end',
            ]);
        }

        // Edges
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'node_start',
            'target_node_key' => 'node_menu',
        ]);

        // Menu option edges
        foreach ($options as $opt) {
            FlowEdge::create([
                'flow_version_id' => $version->id,
                'source_node_key' => 'node_menu',
                'target_node_key' => $opt['target_node_key'],
                'condition_handle' => 'opt_'.$opt['id'],
            ]);
        }

        // Question -> HTTP
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'node_ask_pedido',
            'target_node_key' => 'node_http_pedido',
        ]);

        // HTTP -> Condition
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'node_http_pedido',
            'target_node_key' => 'node_cond_estado',
        ]);

        // Condition -> True (Goto) & False (Msg en camino)
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'node_cond_estado',
            'target_node_key' => 'node_goto_final',
            'condition_handle' => 'true',
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'node_cond_estado',
            'target_node_key' => 'node_msg_en_camino',
            'condition_handle' => 'false',
        ]);

        // Msg en camino -> End
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'node_msg_en_camino',
            'target_node_key' => 'node_end',
        ]);

        // Final Msg -> End
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'node_msg_final',
            'target_node_key' => 'node_end',
        ]);

        // 1. Validate Flow with FlowValidator
        /** @var FlowValidator $validator */
        $validator = app(FlowValidator::class);
        $validation = $validator->validate($version);

        $this->assertTrue($validation['is_valid'], 'El flujo obligatorio debe ser estructuralmente válido: '.json_encode($validation['errors']));

        // 2. Execute with FlowRuntimeEngine
        /** @var FlowRuntimeEngine $engine */
        $engine = app(FlowRuntimeEngine::class);

        $channel = Channel::create([
            'name' => 'Webchat Test Channel',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
            'configuration' => [],
        ]);

        $contact = Contact::create([
            'display_name' => 'Juan Pérez',
            'email' => 'juan@example.com',
            'external_identifier' => 'test-juan',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
            'started_at' => now(),
        ]);

        // Start execution
        $execution = $engine->start($conversation, $version);

        // Execution should pause at Menu node waiting for input
        $this->assertEquals(FlowExecutionStatus::WAITING_INPUT, $execution->status);
        $this->assertEquals($nodeMenu->id, $execution->current_node_id);

        // Check that menu message was sent with choices metadata for Webchat
        $menuMsg = Message::where('conversation_id', $conversation->id)->latest('sent_at')->first();
        $this->assertNotNull($menuMsg);
        $this->assertStringContainsString('¿En qué podemos servirte hoy?', $menuMsg->content);
        $this->assertTrue($menuMsg->metadata['is_menu']);
        $this->assertCount(8, $menuMsg->metadata['choices']);
        $this->assertContains('Consultas', $menuMsg->metadata['choices']);

        // User selects Option 7: "Consultas"
        $userMsg1 = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Consultas',
            'sent_at' => now(),
        ]);

        $execution = $engine->resumeWithInboundMessage($conversation, $userMsg1);

        // Should have transitioned to node_ask_pedido, waiting input
        $this->assertEquals(FlowExecutionStatus::WAITING_INPUT, $execution->status);
        $this->assertEquals($nodeAskPedido->id, $execution->current_node_id);
        $this->assertEquals('Consultas', $execution->context['menu_seleccionado']);

        // User enters order number: "ORD-999"
        $userMsg2 = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'ORD-999',
            'sent_at' => now(),
        ]);

        $execution = $engine->resumeWithInboundMessage($conversation, $userMsg2);

        // Engine automatically:
        // 1. Saved numero_pedido = ORD-999
        // 2. Executed HTTP node with mock response, extracted estado_pedido = 'delivered'
        // 3. Evaluated condition (estado_pedido == 'delivered') -> TRUE
        // 4. Executed Goto node -> jumped to node_msg_final
        // 5. Sent node_msg_final with interpolated {{estado_pedido}}
        // 6. Advanced to End node -> flow completed!

        $this->assertEquals(FlowExecutionStatus::COMPLETED, $execution->status);
        $this->assertEquals('ORD-999', $execution->context['numero_pedido']);
        $this->assertEquals('delivered', $execution->context['estado_pedido']);
        $this->assertEquals('TRK-9876', $execution->context['codigo_rastreo']);

        // Verify final message content
        $finalMsg = Message::where('conversation_id', $conversation->id)
            ->where('flow_node_id', $nodeFinalMsg->id)
            ->first();

        $this->assertNotNull($finalMsg);
        $this->assertStringContainsString('Tu pedido ha sido confirmado como: delivered. ¡Muchas gracias!', $finalMsg->content);
    }

    public function test_menu_node_selection_by_index_and_invalid_retry(): void
    {
        $flow = Flow::create(['name' => 'Menu Test Flow']);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1]);

        $nodeStart = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_start',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
            'configuration' => [],
        ]);

        $nodeMenu = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_menu',
            'type' => FlowNodeType::MENU,
            'name' => 'Menú',
            'configuration' => [
                'text' => 'Elige una opción:',
                'options' => [
                    ['id' => '1', 'text' => 'Ventas'],
                    ['id' => '2', 'text' => 'Soporte'],
                ],
                'variable' => 'departamento',
            ],
        ]);

        $nodeEnd = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_end',
            'type' => FlowNodeType::END,
            'name' => 'Fin',
            'configuration' => [],
        ]);

        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'node_start', 'target_node_key' => 'node_menu']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'node_menu', 'target_node_key' => 'node_end', 'condition_handle' => 'opt_1']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'node_menu', 'target_node_key' => 'node_end', 'condition_handle' => 'opt_2']);

        $channel = Channel::create(['name' => 'C1', 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'configuration' => []]);
        $contact = Contact::create(['display_name' => 'Pedro', 'external_identifier' => 'test-pedro']);
        $conversation = Conversation::create(['channel_id' => $channel->id, 'contact_id' => $contact->id, 'status' => ConversationStatus::BOT, 'started_at' => now()]);

        /** @var FlowRuntimeEngine $engine */
        $engine = app(FlowRuntimeEngine::class);
        $execution = $engine->start($conversation, $version);

        // 1. Invalid input: "Cualquier cosa"
        $invalidMsg = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Cualquier cosa',
            'sent_at' => now(),
        ]);

        $execution = $engine->resumeWithInboundMessage($conversation, $invalidMsg);
        $this->assertEquals(FlowExecutionStatus::WAITING_INPUT, $execution->status);

        // 2. Valid input by index "2" (Soporte)
        $validMsg = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => '2',
            'sent_at' => now(),
        ]);

        $execution = $engine->resumeWithInboundMessage($conversation, $validMsg);
        $this->assertEquals(FlowExecutionStatus::COMPLETED, $execution->status);
        $this->assertEquals('Soporte', $execution->context['departamento']);
    }

    public function test_set_variable_node_operations(): void
    {
        $flow = Flow::create(['name' => 'Set Variable Flow']);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_start',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
            'configuration' => [],
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_set_var',
            'type' => FlowNodeType::SET_VARIABLE,
            'name' => 'Set Contador',
            'configuration' => [
                'variable' => 'contador',
                'type' => 'number',
                'operation' => 'set',
                'value' => 10,
            ],
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_inc_var',
            'type' => FlowNodeType::SET_VARIABLE,
            'name' => 'Inc Contador',
            'configuration' => [
                'variable' => 'contador',
                'operation' => 'increment',
                'value' => 5,
            ],
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_end',
            'type' => FlowNodeType::END,
            'name' => 'Fin',
            'configuration' => [],
        ]);

        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'node_start', 'target_node_key' => 'node_set_var']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'node_set_var', 'target_node_key' => 'node_inc_var']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'node_inc_var', 'target_node_key' => 'node_end']);

        $channel = Channel::create(['name' => 'C2', 'type' => ChannelType::WEBCHAT, 'is_active' => true, 'configuration' => []]);
        $contact = Contact::create(['display_name' => 'Ana', 'external_identifier' => 'test-ana']);
        $conversation = Conversation::create(['channel_id' => $channel->id, 'contact_id' => $contact->id, 'status' => ConversationStatus::BOT, 'started_at' => now()]);

        /** @var FlowRuntimeEngine $engine */
        $engine = app(FlowRuntimeEngine::class);
        $execution = $engine->start($conversation, $version);

        $this->assertEquals(FlowExecutionStatus::COMPLETED, $execution->status);
        $this->assertEquals(15, $execution->context['contador']);
    }

    public function test_flow_simulation_api_endpoints(): void
    {
        $flow = Flow::create(['name' => 'Simulation Flow']);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_start',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
            'configuration' => [],
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_msg',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Saludo',
            'configuration' => ['text' => 'Bienvenido al simulador.'],
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_ask',
            'type' => FlowNodeType::QUESTION,
            'name' => 'Pregunta',
            'configuration' => ['text' => '¿Cuál es tu nombre?', 'variable' => 'nombre_sim'],
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_end',
            'type' => FlowNodeType::END,
            'name' => 'Fin',
            'configuration' => [],
        ]);

        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'node_start', 'target_node_key' => 'node_msg']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'node_msg', 'target_node_key' => 'node_ask']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'node_ask', 'target_node_key' => 'node_end']);

        // Start simulation via API
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/start");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertNotEmpty($data['execution_id']);
        $this->assertEquals('waiting_input', $data['status']);
        $this->assertEquals('node_ask', $data['current_node']['node_key']);
        $this->assertCount(2, $data['messages']); // Saludo + Pregunta

        // Send step via API
        $stepResponse = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/step", [
                'execution_id' => $data['execution_id'],
                'message' => 'Carlos',
            ]);

        $stepResponse->assertStatus(200);
        $stepData = $stepResponse->json('data');

        $this->assertEquals('completed', $stepData['status']);
        $this->assertEquals('Carlos', $stepData['context']['nombre_sim']);
    }

    public function test_set_variable_supports_variable_name_key_regression(): void
    {
        $flow = Flow::create([
            'name' => 'Regression Set Variable Name',
            'status' => FlowStatus::DRAFT,
            'channel_applicability' => ['webchat'],
        ]);

        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::DRAFT,
        ]);

        $nodeStart = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 's1',
            'type' => FlowNodeType::START,
            'name' => 'Start',
            'configuration' => [],
            'position_x' => 0,
            'position_y' => 0,
        ]);

        $nodeSetVar = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'sv1',
            'type' => FlowNodeType::SET_VARIABLE,
            'name' => 'Set Var',
            'configuration' => [
                'variable_name' => 'cliente_categoria',
                'operation' => 'set',
                'value' => 'premium',
            ],
            'position_x' => 0,
            'position_y' => 100,
        ]);

        $nodeEnd = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'e1',
            'type' => FlowNodeType::END,
            'name' => 'End',
            'configuration' => [],
            'position_x' => 0,
            'position_y' => 200,
        ]);

        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 's1', 'target_node_key' => 'sv1']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'sv1', 'target_node_key' => 'e1']);

        // Validation passes
        $validator = app(FlowValidator::class);
        $errors = $validator->validateVersion($version);
        $this->assertEmpty($errors);

        // Runtime simulation passes and sets context variable
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$version->id}/simulation/start");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals('completed', $data['status']);
        $this->assertEquals('premium', $data['context']['cliente_categoria']);
    }
}
