<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\FlowExecutionStatus;
use App\Enums\FlowNodeType;
use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\Channel;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Services\Runtime\Channels\Adapters\WebchatChannelAdapter;
use App\Services\Runtime\Channels\Adapters\WhatsAppChannelAdapter;
use App\Services\Runtime\ConversationIngressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionNodeHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_input_to_question_with_choices_reattaches_interactive_options(): void
    {
        $flow = Flow::create(['name' => 'Question Choices Flow']);
        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::PUBLISHED,
            'published_at' => now(),
        ]);
        $flow->update([
            'status' => FlowStatus::PUBLISHED,
            'active_version_id' => $version->id,
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
            'configuration' => [],
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'q_agent',
            'type' => FlowNodeType::QUESTION,
            'name' => 'Preguntar por agente',
            'configuration' => [
                'text' => '¿Quieres conversar con un agente?',
                'variable' => 'quiere_agente',
                'input_type' => 'choice',
                'choices' => ['Sí', 'No'],
                'invalid_message' => 'Por favor, elige una de las opciones válidas: Sí, No.',
            ],
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start',
            'target_node_key' => 'q_agent',
        ]);

        $channel = Channel::create([
            'name' => 'Webchat Channel',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
            'entry_flow_id' => $flow->id,
        ]);

        /** @var ConversationIngressService $ingress */
        $ingress = app(ConversationIngressService::class);

        // 1. Inbound greeting starts flow and executes question node
        $result = $ingress->handleInboundMessage($channel, ['external_identifier' => 'test_user'], 'Hola');
        $conversation = $result['conversation'];

        // Initial question message
        $initialMsg = Message::where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::OUTBOUND)
            ->latest('id')
            ->first();

        $this->assertNotNull($initialMsg);
        $this->assertEquals(MessageType::INTERACTIVE, $initialMsg->message_type);
        $this->assertNotNull($initialMsg->payload);
        $this->assertCount(2, $initialMsg->payload['options']);
        $this->assertEquals('Sí', $initialMsg->payload['options'][0]['title']);
        $this->assertEquals('No', $initialMsg->payload['options'][1]['title']);

        // 2. User writes invalid response like "Hola"
        $invalidStep = $ingress->handleInboundMessage($channel, ['external_identifier' => 'test_user'], 'Hola');
        $execution = $invalidStep['execution']->fresh();

        $this->assertEquals(FlowExecutionStatus::WAITING_INPUT, $execution->status);

        // Verify invalid retry message was emitted with interactive payload
        $retryMsg = Message::where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::OUTBOUND)
            ->latest('id')
            ->first();

        $this->assertNotNull($retryMsg);
        $this->assertEquals('Por favor, elige una de las opciones válidas: Sí, No.', $retryMsg->content);
        $this->assertEquals(MessageType::INTERACTIVE, $retryMsg->message_type);
        $this->assertNotNull($retryMsg->payload);
        $this->assertTrue($retryMsg->payload['validation_error']);
        $this->assertCount(2, $retryMsg->payload['options']);
        $this->assertEquals('Sí', $retryMsg->payload['options'][0]['title']);
        $this->assertEquals('No', $retryMsg->payload['options'][1]['title']);

        // Check WebchatChannelAdapter formats it with payload
        $webchatAdapter = new WebchatChannelAdapter;
        $formattedWebchat = $webchatAdapter->formatOutbound($retryMsg);
        $this->assertArrayHasKey('payload', $formattedWebchat);
        $this->assertTrue($formattedWebchat['payload']['validation_error']);
        $this->assertCount(2, $formattedWebchat['payload']['options']);

        // Check WhatsAppChannelAdapter formats it as Meta interactive buttons
        $waAdapter = new WhatsAppChannelAdapter;
        $formattedWa = $waAdapter->formatOutbound($retryMsg);
        $this->assertEquals('interactive', $formattedWa['type']);
        $this->assertEquals('button', $formattedWa['interactive']['type']);
        $this->assertCount(2, $formattedWa['interactive']['action']['buttons']);
        $this->assertEquals('Sí', $formattedWa['interactive']['action']['buttons'][0]['reply']['title']);
        $this->assertEquals('No', $formattedWa['interactive']['action']['buttons'][1]['reply']['title']);

        // 3. User selects valid choice "Sí"
        $validStep = $ingress->handleInboundMessage($channel, ['external_identifier' => 'test_user'], 'Sí');
        $validExec = $validStep['execution']->fresh();
        $this->assertEquals('Sí', $validExec->context['quiere_agente']);
    }

    public function test_menu_node_invalid_input_reattaches_interactive_options(): void
    {
        $flow = Flow::create(['name' => 'Menu Flow']);
        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::PUBLISHED,
            'published_at' => now(),
        ]);
        $flow->update([
            'status' => FlowStatus::PUBLISHED,
            'active_version_id' => $version->id,
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
            'configuration' => [],
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'menu',
            'type' => FlowNodeType::MENU,
            'name' => 'Menú principal',
            'configuration' => [
                'text' => 'Elige un área:',
                'variable' => 'area',
                'options' => [
                    ['id' => 'opt_ventas', 'text' => 'Ventas', 'value' => 'ventas'],
                    ['id' => 'opt_soporte', 'text' => 'Soporte', 'value' => 'soporte'],
                ],
            ],
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start',
            'target_node_key' => 'menu',
        ]);

        $channel = Channel::create([
            'name' => 'Webchat Channel 2',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
            'entry_flow_id' => $flow->id,
        ]);

        /** @var ConversationIngressService $ingress */
        $ingress = app(ConversationIngressService::class);
        $result = $ingress->handleInboundMessage($channel, ['external_identifier' => 'user_2'], 'Hola');
        $conversation = $result['conversation'];

        // Send invalid text
        $stepInvalid = $ingress->handleInboundMessage($channel, ['external_identifier' => 'user_2'], 'desconocido');

        $retryMsg = Message::where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::OUTBOUND)
            ->latest('id')
            ->first();

        $this->assertNotNull($retryMsg);
        $this->assertEquals(MessageType::INTERACTIVE, $retryMsg->message_type);
        $this->assertTrue($retryMsg->payload['validation_error']);
        $this->assertCount(2, $retryMsg->payload['options']);
        $this->assertEquals('Ventas', $retryMsg->payload['options'][0]['title']);
        $this->assertEquals('Soporte', $retryMsg->payload['options'][1]['title']);
    }

    public function test_question_node_country_input_type_saves_normalized_iso_and_syncs_contact(): void
    {
        $flow = Flow::create(['name' => 'Country Question Flow']);
        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::PUBLISHED,
            'published_at' => now(),
        ]);
        $flow->update([
            'status' => FlowStatus::PUBLISHED,
            'active_version_id' => $version->id,
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
            'configuration' => [],
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'q_pais',
            'type' => FlowNodeType::QUESTION,
            'name' => 'Preguntar País',
            'configuration' => [
                'text' => '¿De qué país nos escribes?',
                'variable' => 'pais_usuario',
                'input_type' => 'country',
                'contact_field' => 'country',
            ],
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'end',
            'type' => FlowNodeType::END,
            'name' => 'Fin',
            'configuration' => [],
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start',
            'target_node_key' => 'q_pais',
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'q_pais',
            'target_node_key' => 'end',
        ]);

        $channel = Channel::create([
            'name' => 'Webchat Channel Country',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
            'entry_flow_id' => $flow->id,
        ]);

        /** @var ConversationIngressService $ingress */
        $ingress = app(ConversationIngressService::class);

        // 1. Inbound greeting starts flow and prompts country question
        $result = $ingress->handleInboundMessage($channel, ['external_identifier' => 'user_country_test'], 'Hola');
        $conversation = $result['conversation']->fresh();
        $contact = $conversation->contact;

        $this->assertNull($contact->country);

        // 2. User writes invalid response like "manzana"
        $invalidStep = $ingress->handleInboundMessage($channel, ['external_identifier' => 'user_country_test'], 'manzana');
        $execution = $invalidStep['execution']->fresh();
        $this->assertEquals(FlowExecutionStatus::WAITING_INPUT, $execution->status);

        $retryMsg = Message::where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::OUTBOUND)
            ->latest('id')
            ->first();

        $this->assertNotNull($retryMsg);
        $this->assertStringContainsString('Por favor, escribe un país válido', $retryMsg->content);

        // 3. User responds with conversational phrase "Soy de Costa Rica"
        $validStep = $ingress->handleInboundMessage($channel, ['external_identifier' => 'user_country_test'], 'Soy de Costa Rica');
        $validExec = $validStep['execution']->fresh();

        $this->assertEquals(FlowExecutionStatus::COMPLETED, $validExec->status);
        $this->assertEquals('CR', $validExec->context['pais_usuario']);

        $contact->refresh();
        $this->assertEquals('CR', $contact->country);
    }
}
