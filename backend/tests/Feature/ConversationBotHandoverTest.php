<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\FlowExecutionStatus;
use App\Enums\FlowNodeType;
use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Enums\MessageSenderType;
use App\Events\ConversationUpdated;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowExecution;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\Runtime\Channels\InboundMessage;
use App\Services\Runtime\ConversationIngressService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConversationBotHandoverTest extends TestCase
{
    use RefreshDatabase;

    protected User $agent;

    protected User $supervisor;

    protected Channel $channel;

    protected Contact $contact;

    protected Conversation $conversation;

    protected FlowVersion $flowVersion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $agentRole = Role::where('name', 'agent')->first();
        $supervisorRole = Role::where('name', 'supervisor')->first();

        $this->agent = User::create([
            'name' => 'Agente Uno',
            'email' => 'agente1@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
        ]);
        $this->agent->roles()->attach($agentRole);

        $this->supervisor = User::create([
            'name' => 'Supervisor Alpha',
            'email' => 'supervisor@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
        ]);
        $this->supervisor->roles()->attach($supervisorRole);

        // Create published flow with start and message node
        $flow = Flow::create([
            'name' => 'Flujo Handover Test',
            'status' => FlowStatus::PUBLISHED,
        ]);

        $this->flowVersion = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::PUBLISHED,
        ]);

        $flow->update(['active_version_id' => $this->flowVersion->id]);

        $startNode = FlowNode::create([
            'flow_version_id' => $this->flowVersion->id,
            'node_key' => 'start_node_1',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
            'configuration' => [],
        ]);

        FlowNode::create([
            'flow_version_id' => $this->flowVersion->id,
            'node_key' => 'q_node_1',
            'type' => FlowNodeType::QUESTION,
            'name' => 'Pregunta',
            'configuration' => [
                'text' => '¿Cuál es tu consulta?',
                'variable' => 'consulta',
                'input_type' => 'text',
            ],
        ]);

        FlowEdge::create([
            'flow_version_id' => $this->flowVersion->id,
            'source_node_key' => 'start_node_1',
            'target_node_key' => 'q_node_1',
        ]);

        $this->channel = Channel::create([
            'name' => 'WhatsApp Handover Channel',
            'type' => ChannelType::WHATSAPP,
            'status' => 'active',
            'entry_flow_id' => $flow->id,
            'configuration' => [
                'phone_number_id' => '555123456',
                'access_token' => 'handover-token',
            ],
        ]);

        $this->contact = Contact::create([
            'first_name' => 'Carlos',
            'last_name' => 'Gomez',
            'phone' => '+5215551234567',
        ]);

        $this->conversation = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::BOT,
            'bot_enabled' => true,
            'active_flow_id' => $flow->id,
            'active_flow_version_id' => $this->flowVersion->id,
        ]);
    }

    public function test_toggle_bot_pauses_bot_and_cancels_running_executions(): void
    {
        Event::fake([ConversationUpdated::class]);
        Sanctum::actingAs($this->supervisor);

        $execution = FlowExecution::create([
            'flow_id' => $this->flowVersion->flow_id,
            'flow_version_id' => $this->flowVersion->id,
            'conversation_id' => $this->conversation->id,
            'status' => FlowExecutionStatus::WAITING_INPUT,
            'context' => [],
        ]);

        $response = $this->postJson("/api/v1/conversations/{$this->conversation->id}/toggle-bot");

        $response->assertOk()
            ->assertJsonPath('data.bot_enabled', false)
            ->assertJsonPath('message', 'Bot pausado correctamente.');

        $this->conversation->refresh();
        $this->assertFalse($this->conversation->bot_enabled);

        $execution->refresh();
        $this->assertEquals(FlowExecutionStatus::CANCELLED, $execution->status);
        $this->assertNotNull($execution->completed_at);

        Event::assertDispatched(ConversationUpdated::class);
    }

    public function test_toggle_bot_resumes_bot(): void
    {
        Event::fake([ConversationUpdated::class]);
        Sanctum::actingAs($this->supervisor);

        $this->conversation->update(['bot_enabled' => false]);

        $response = $this->postJson("/api/v1/conversations/{$this->conversation->id}/toggle-bot");

        $response->assertOk()
            ->assertJsonPath('data.bot_enabled', true)
            ->assertJsonPath('message', 'Bot reanudado correctamente.');

        $this->conversation->refresh();
        $this->assertTrue($this->conversation->bot_enabled);

        Event::assertDispatched(ConversationUpdated::class);
    }

    public function test_inbound_message_does_not_trigger_bot_when_bot_is_disabled(): void
    {
        $this->conversation->update(['bot_enabled' => false, 'status' => ConversationStatus::BOT]);

        $ingress = app(ConversationIngressService::class);
        $inbound = new InboundMessage(
            channel: $this->channel,
            content: 'Hola bot, responde',
            externalMessageId: 'msg_handover_001',
            contactPhone: $this->contact->phone,
            contactDisplayName: 'Carlos Gomez'
        );

        $result = $ingress->handleInbound($inbound);

        $this->assertNotNull($result['message']);
        $this->assertEquals('Hola bot, responde', $result['message']->content);
        $this->assertNull($result['execution'], 'Flow execution must be null when bot_enabled is false');

        $activeExecutions = FlowExecution::where('conversation_id', $this->conversation->id)
            ->whereIn('status', [FlowExecutionStatus::RUNNING, FlowExecutionStatus::WAITING_INPUT])
            ->count();

        $this->assertSame(0, $activeExecutions);
    }

    public function test_restart_flow_cancels_previous_executions_and_restarts_entry_flow(): void
    {
        Event::fake([ConversationUpdated::class]);
        Sanctum::actingAs($this->supervisor);

        $this->conversation->update([
            'bot_enabled' => false,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agent->id,
        ]);

        $oldExecution = FlowExecution::create([
            'flow_id' => $this->flowVersion->flow_id,
            'flow_version_id' => $this->flowVersion->id,
            'conversation_id' => $this->conversation->id,
            'status' => FlowExecutionStatus::RUNNING,
            'context' => [],
        ]);

        $response = $this->postJson("/api/v1/conversations/{$this->conversation->id}/restart-flow");

        $response->assertOk()
            ->assertJsonPath('message', 'Flujo reiniciado correctamente.')
            ->assertJsonPath('data.conversation.bot_enabled', true)
            ->assertJsonPath('data.conversation.status', ConversationStatus::BOT->value);

        $this->conversation->refresh();
        $this->assertTrue($this->conversation->bot_enabled);
        $this->assertEquals(ConversationStatus::BOT, $this->conversation->status);

        $oldExecution->refresh();
        $this->assertEquals(FlowExecutionStatus::CANCELLED, $oldExecution->status);

        Event::assertDispatched(ConversationUpdated::class);
    }

    public function test_unassigned_agent_cannot_toggle_or_restart_bot(): void
    {
        $otherAgent = User::create([
            'name' => 'Otro Agente',
            'email' => 'otro@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
        ]);
        $otherAgent->roles()->attach(Role::where('name', 'agent')->first());

        $this->conversation->update([
            'assigned_user_id' => $this->agent->id,
            'status' => ConversationStatus::ASSIGNED,
        ]);

        Sanctum::actingAs($otherAgent);

        $responseToggle = $this->postJson("/api/v1/conversations/{$this->conversation->id}/toggle-bot");
        $responseToggle->assertForbidden();

        $responseRestart = $this->postJson("/api/v1/conversations/{$this->conversation->id}/restart-flow");
        $responseRestart->assertForbidden();
    }
}
