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
use App\Services\Presence\AgentAvailabilityService;
use App\Services\Runtime\Channels\Adapters\WhatsAppChannelAdapter;
use App\Services\Runtime\ExecutionContext;
use App\Services\Runtime\FlowRuntimeEngine;
use App\Services\Runtime\Handlers\MenuNodeHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuNodeHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_menu_node_excludes_hidden_options_from_outbound_payload_and_metadata(): void
    {
        $flow = Flow::create(['name' => 'Menu Hidden Test', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);

        $nodeStart = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_start',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
            'configuration' => [],
        ]);

        $menuNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'menu_1',
            'type' => FlowNodeType::MENU,
            'name' => 'Menú de Servicios',
            'configuration' => [
                'text' => 'Por favor elige una opción:',
                'options' => [
                    ['id' => '1', 'text' => 'Ventas', 'is_hidden' => false],
                    ['id' => '2', 'text' => 'Soporte', 'is_hidden' => false],
                    ['id' => '9', 'text' => 'Varios', 'is_hidden' => true],
                ],
                'variable' => 'selected_service',
            ],
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'node_start',
            'target_node_key' => 'menu_1',
        ]);

        $channel = Channel::create(['name' => 'Webchat', 'type' => ChannelType::WEBCHAT, 'is_active' => true]);
        $contact = Contact::create(['display_name' => 'Cliente Test', 'external_identifier' => 'client-1']);
        $conversation = Conversation::create(['channel_id' => $channel->id, 'contact_id' => $contact->id, 'status' => ConversationStatus::BOT, 'started_at' => now()]);

        /** @var FlowRuntimeEngine $engine */
        $engine = app(FlowRuntimeEngine::class);
        $execution = $engine->start($conversation, $version);

        // MenuNodeHandler execution
        /** @var MenuNodeHandler $handler */
        $handler = app(MenuNodeHandler::class);
        $context = new ExecutionContext([]);

        $result = $handler->execute($menuNode, $execution, $context);

        $this->assertTrue($result->isWaitingInput());
        $this->assertEquals(2, $result->output['options_count']);
        $this->assertEquals(3, $result->output['total_options_count']);

        // Inspect outbound message
        $botMessage = Message::where('flow_node_id', $menuNode->id)
            ->where('sender_type', MessageSenderType::BOT)
            ->first();

        $this->assertNotNull($botMessage);
        $payloadOptions = $botMessage->payload['options'] ?? [];
        $metadataChoices = $botMessage->metadata['choices'] ?? [];
        $metadataOptions = $botMessage->metadata['options'] ?? [];

        // Only visible options in payload
        $this->assertCount(2, $payloadOptions);
        $this->assertEquals(['Ventas', 'Soporte'], array_column($payloadOptions, 'text'));

        // Hidden option must NOT be present in payload or choices
        $this->assertNotContains('Varios', array_column($payloadOptions, 'text'));
        $this->assertNotContains('9', array_column($payloadOptions, 'id'));
        $this->assertCount(2, $metadataChoices);
        $this->assertEquals(['Ventas', 'Soporte'], $metadataChoices);
        $this->assertCount(2, $metadataOptions);
    }

    public function test_menu_node_selection_by_index_and_visible_option_text(): void
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
                'text' => 'Elige:',
                'options' => [
                    ['id' => '1', 'text' => 'Ventas', 'is_hidden' => false],
                    ['id' => '2', 'text' => 'Soporte', 'is_hidden' => false],
                    ['id' => '9', 'text' => 'Varios', 'is_hidden' => true],
                ],
                'variable' => 'dept',
            ],
        ]);

        $nodeEndVentas = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_end_ventas',
            'type' => FlowNodeType::END,
            'name' => 'Fin Ventas',
            'configuration' => [],
        ]);

        $nodeEndSoporte = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_end_soporte',
            'type' => FlowNodeType::END,
            'name' => 'Fin Soporte',
            'configuration' => [],
        ]);

        $nodeEndHidden = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_end_hidden',
            'type' => FlowNodeType::END,
            'name' => 'Fin Hidden',
            'configuration' => [],
        ]);

        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'node_start', 'target_node_key' => 'node_menu']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'node_menu', 'target_node_key' => 'node_end_ventas', 'condition_handle' => 'opt_1']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'node_menu', 'target_node_key' => 'node_end_soporte', 'condition_handle' => 'opt_2']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'node_menu', 'target_node_key' => 'node_end_hidden', 'condition_handle' => 'opt_9']);

        $channel = Channel::create(['name' => 'C1', 'type' => ChannelType::WEBCHAT, 'is_active' => true]);
        $contact = Contact::create(['display_name' => 'Ana', 'external_identifier' => 'test-ana']);
        $conversation = Conversation::create(['channel_id' => $channel->id, 'contact_id' => $contact->id, 'status' => ConversationStatus::BOT, 'started_at' => now()]);

        /** @var FlowRuntimeEngine $engine */
        $engine = app(FlowRuntimeEngine::class);
        $execution = $engine->start($conversation, $version);

        // 1. User writes "2" (maps to 2nd visible option "Soporte", NOT option 9)
        $inbound2 = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => '2',
            'sent_at' => now(),
        ]);

        $execution = $engine->resumeWithInboundMessage($conversation, $inbound2);
        $this->assertEquals(FlowExecutionStatus::COMPLETED, $execution->status);
        $this->assertEquals('Soporte', $execution->context['dept']);
    }

    public function test_menu_node_activates_hidden_option_with_exact_secret_shortcut_and_follows_canvas_edge(): void
    {
        $flow = Flow::create(['name' => 'Menu Secret Shortcut Flow']);
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
            'name' => 'Menú Oculto',
            'configuration' => [
                'text' => 'Elige una opción:',
                'options' => [
                    ['id' => '1', 'text' => 'Ventas', 'is_hidden' => false],
                    ['id' => '2', 'text' => 'Soporte', 'is_hidden' => false],
                    ['id' => '9', 'text' => 'Varios', 'is_hidden' => true],
                ],
                'variable' => 'seleccion',
            ],
        ]);

        $nodeEndHidden = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_end_secret',
            'type' => FlowNodeType::END,
            'name' => 'Fin Rama Secreta',
            'configuration' => [],
        ]);

        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'node_start', 'target_node_key' => 'node_menu']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'node_menu', 'target_node_key' => 'node_end_secret', 'condition_handle' => 'opt_9']);

        $channel = Channel::create(['name' => 'C1', 'type' => ChannelType::WEBCHAT, 'is_active' => true]);
        $contact = Contact::create(['display_name' => 'Lucas', 'external_identifier' => 'test-lucas']);
        $conversation = Conversation::create(['channel_id' => $channel->id, 'contact_id' => $contact->id, 'status' => ConversationStatus::BOT, 'started_at' => now()]);

        /** @var FlowRuntimeEngine $engine */
        $engine = app(FlowRuntimeEngine::class);
        $execution = $engine->start($conversation, $version);

        // User writes the exact secret shortcut text "Varios"
        $inboundSecret = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Varios',
            'sent_at' => now(),
        ]);

        $execution = $engine->resumeWithInboundMessage($conversation, $inboundSecret);
        $this->assertEquals(FlowExecutionStatus::COMPLETED, $execution->status);
        $this->assertEquals('Varios', $execution->context['seleccion']);
    }

    public function test_menu_node_invalid_input_retry_does_not_leak_hidden_options(): void
    {
        $flow = Flow::create(['name' => 'Menu Retry Test']);
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
                'text' => 'Selecciona:',
                'options' => [
                    ['id' => '1', 'text' => 'Ventas', 'is_hidden' => false],
                    ['id' => '2', 'text' => 'Soporte', 'is_hidden' => false],
                    ['id' => '9', 'text' => 'Admin Secreto', 'is_hidden' => true],
                ],
            ],
        ]);

        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'node_start', 'target_node_key' => 'node_menu']);

        $channel = Channel::create(['name' => 'C1', 'type' => ChannelType::WEBCHAT, 'is_active' => true]);
        $contact = Contact::create(['display_name' => 'Carlos', 'external_identifier' => 'test-carlos']);
        $conversation = Conversation::create(['channel_id' => $channel->id, 'contact_id' => $contact->id, 'status' => ConversationStatus::BOT, 'started_at' => now()]);

        /** @var FlowRuntimeEngine $engine */
        $engine = app(FlowRuntimeEngine::class);
        $execution = $engine->start($conversation, $version);

        // User enters invalid text
        $invalidMsg = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'PalabraNoValida',
            'sent_at' => now(),
        ]);

        $execution = $engine->resumeWithInboundMessage($conversation, $invalidMsg);
        $this->assertEquals(FlowExecutionStatus::WAITING_INPUT, $execution->status);

        // Retrieve retry message
        $retryMsg = Message::where('conversation_id', $conversation->id)
            ->where('sender_type', MessageSenderType::BOT)
            ->latest('id')
            ->first();

        $this->assertNotNull($retryMsg);
        $this->assertStringContainsString('Ventas', $retryMsg->content);
        $this->assertStringContainsString('Soporte', $retryMsg->content);
        $this->assertStringNotContainsString('Admin Secreto', $retryMsg->content);

        $payloadOptions = $retryMsg->payload['options'] ?? [];
        $this->assertCount(2, $payloadOptions);
        $this->assertNotContains('Admin Secreto', array_column($payloadOptions, 'text'));
    }

    public function test_whatsapp_adapter_excludes_hidden_options(): void
    {
        $channel = Channel::create([
            'name' => 'WhatsApp Meta',
            'type' => ChannelType::WHATSAPP,
            'is_active' => true,
            'configuration' => [
                'access_token' => 'mock_token',
                'phone_number_id' => '123456789',
            ],
        ]);

        $contact = Contact::create([
            'display_name' => 'Cliente WhatsApp',
            'external_identifier' => '5491100001111',
            'phone' => '+54 9 11 0000-1111',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
            'started_at' => now(),
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::BOT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::INTERACTIVE,
            'content' => 'Por favor elige:',
            'payload' => [
                'options' => [
                    ['id' => '1', 'text' => 'Opción Visible 1', 'is_hidden' => false],
                    ['id' => '2', 'text' => 'Opción Visible 2', 'is_hidden' => false],
                    ['id' => '9', 'text' => 'Opción Oculta 9', 'is_hidden' => true],
                ],
            ],
            'metadata' => [
                'is_menu' => true,
                'choices' => ['Opción Visible 1', 'Opción Visible 2'],
            ],
            'sent_at' => now(),
        ]);

        $adapter = app(WhatsAppChannelAdapter::class);
        $formatted = $adapter->formatOutbound($message);

        $this->assertEquals('interactive', $formatted['type']);
        $this->assertEquals('button', $formatted['interactive']['type']);

        $buttons = $formatted['interactive']['action']['buttons'] ?? [];
        // Only 2 visible options should be buttons
        $this->assertCount(2, $buttons);
        $this->assertEquals('Opción Visible 1', $buttons[0]['reply']['title']);
        $this->assertEquals('Opción Visible 2', $buttons[1]['reply']['title']);
    }

    public function test_menu_node_dynamically_hides_options_when_no_agents_are_online(): void
    {
        AgentAvailabilityService::fake(0);

        $flow = Flow::create(['name' => 'Menu Agent Test', 'status' => FlowStatus::DRAFT]);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1, 'status' => FlowVersionStatus::DRAFT]);

        $nodeStart = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_start',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
            'configuration' => [],
        ]);

        $menuNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'menu_agent_1',
            'type' => FlowNodeType::MENU,
            'name' => 'Menú Atención',
            'configuration' => [
                'text' => '¿Cómo podemos ayudarte?',
                'options' => [
                    ['id' => '1', 'text' => 'Ver preguntas frecuentes', 'hide_if_no_agents' => false],
                    ['id' => '2', 'text' => 'Hablar con un asesor humano', 'hide_if_no_agents' => true],
                ],
            ],
        ]);

        $channel = Channel::create(['name' => 'Webchat', 'type' => ChannelType::WEBCHAT, 'is_active' => true]);
        $contact = Contact::create(['display_name' => 'Cliente Test', 'external_identifier' => 'client-agent-1']);
        $conversation = Conversation::create(['channel_id' => $channel->id, 'contact_id' => $contact->id, 'status' => ConversationStatus::BOT, 'started_at' => now()]);

        $engine = app(FlowRuntimeEngine::class);
        $execution = $engine->start($conversation, $version);

        $handler = app(MenuNodeHandler::class);
        $context = new ExecutionContext([]);

        // When 0 agents are online:
        $result = $handler->execute($menuNode, $execution, $context);
        $this->assertEquals(1, $result->output['options_count']);
        $this->assertEquals(2, $result->output['total_options_count']);

        $botMsg = Message::where('flow_node_id', $menuNode->id)->latest('id')->first();
        $this->assertNotNull($botMsg);
        $this->assertCount(1, $botMsg->payload['options']);
        $this->assertEquals('Ver preguntas frecuentes', $botMsg->payload['options'][0]['text']);

        // Now simulate 2 agents online:
        AgentAvailabilityService::fake(2);
        $resultWithAgents = $handler->execute($menuNode, $execution, $context);
        $this->assertEquals(2, $resultWithAgents->output['options_count']);

        $botMsg2 = Message::where('flow_node_id', $menuNode->id)->latest('id')->first();
        $this->assertCount(2, $botMsg2->payload['options']);
        $this->assertEquals(['Ver preguntas frecuentes', 'Hablar con un asesor humano'], array_column($botMsg2->payload['options'], 'text'));

        AgentAvailabilityService::resetFake();
    }
}
