<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\FlowExecutionStatus;
use App\Enums\FlowExecutionStepStatus;
use App\Enums\FlowNodeType;
use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowExecution;
use App\Models\FlowExecutionStep;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FlowAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $agentUser;

    protected Flow $flow;

    protected FlowVersion $version;

    protected FlowNode $startNode;

    protected FlowNode $menuNode;

    protected FlowNode $oracionNode;

    protected FlowNode $ofrendarNode;

    protected Channel $webchatChannel;

    protected Channel $whatsappChannel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::factory()->create();
        $this->adminUser->roles()->attach(Role::where('name', 'super_admin')->first());

        $this->agentUser = User::factory()->create();
        $this->agentUser->roles()->attach(Role::where('name', 'agent')->first());

        $this->webchatChannel = Channel::create(['name' => 'Webchat', 'type' => ChannelType::WEBCHAT, 'is_active' => true]);
        $this->whatsappChannel = Channel::create(['name' => 'WhatsApp', 'type' => ChannelType::WHATSAPP, 'is_active' => true]);

        // Create Flow & Version
        $this->flow = Flow::create([
            'name' => 'Flujo de Bienvenida Iglesia',
            'description' => 'Menú principal de atención',
            'status' => FlowStatus::PUBLISHED,
            'channel_applicability' => ['webchat', 'whatsapp'],
        ]);

        $this->version = FlowVersion::create([
            'flow_id' => $this->flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::PUBLISHED,
        ]);

        $this->flow->update(['active_version_id' => $this->version->id]);

        $this->startNode = FlowNode::create([
            'flow_version_id' => $this->version->id,
            'node_key' => 'node_start',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
            'configuration' => [],
        ]);

        $this->menuNode = FlowNode::create([
            'flow_version_id' => $this->version->id,
            'node_key' => 'menu_principal',
            'type' => FlowNodeType::MENU,
            'name' => 'Menú Principal',
            'configuration' => [
                'text' => 'Bienvenido, ¿en qué podemos ayudarte hoy?',
                'options' => [
                    [
                        'id' => '1',
                        'title' => 'Petición de Oración',
                        'text' => 'Petición de Oración',
                        'target_node_key' => 'nodo_oracion',
                    ],
                    [
                        'id' => '2',
                        'title' => 'Ofrendar',
                        'text' => 'Ofrendar',
                        'target_node_key' => 'nodo_ofrendar',
                    ],
                    [
                        'id' => '3',
                        'title' => 'Horarios de Servicio',
                        'text' => 'Horarios de Servicio',
                        'target_node_key' => 'nodo_horarios',
                    ],
                ],
            ],
        ]);

        $this->oracionNode = FlowNode::create([
            'flow_version_id' => $this->version->id,
            'node_key' => 'nodo_oracion',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Sección Oración',
            'configuration' => ['text' => 'Por favor comparte tu motivo de oración.'],
        ]);

        $this->ofrendarNode = FlowNode::create([
            'flow_version_id' => $this->version->id,
            'node_key' => 'nodo_ofrendar',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Sección Ofrendar',
            'configuration' => ['text' => 'Puedes realizar tu ofrenda mediante nuestra cuenta.'],
        ]);

        FlowNode::create([
            'flow_version_id' => $this->version->id,
            'node_key' => 'nodo_horarios',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Sección Horarios',
            'configuration' => ['text' => 'Cultos: Domingos 10:00 AM y 6:00 PM.'],
        ]);

        FlowEdge::create([
            'flow_version_id' => $this->version->id,
            'source_node_key' => 'node_start',
            'target_node_key' => 'menu_principal',
        ]);

        FlowEdge::create([
            'flow_version_id' => $this->version->id,
            'source_node_key' => 'menu_principal',
            'target_node_key' => 'nodo_oracion',
            'condition_handle' => 'opt_1',
        ]);

        FlowEdge::create([
            'flow_version_id' => $this->version->id,
            'source_node_key' => 'menu_principal',
            'target_node_key' => 'nodo_ofrendar',
            'condition_handle' => 'opt_2',
        ]);

        FlowEdge::create([
            'flow_version_id' => $this->version->id,
            'source_node_key' => 'menu_principal',
            'target_node_key' => 'nodo_horarios',
            'condition_handle' => 'opt_3',
        ]);
    }

    public function test_unauthenticated_request_returns_unauthorized(): void
    {
        $response = $this->getJson("/api/v1/flows/{$this->flow->id}/analytics");
        $response->assertStatus(401);
    }

    public function test_user_without_flows_view_permission_is_forbidden(): void
    {
        Sanctum::actingAs($this->agentUser);

        $response = $this->getJson("/api/v1/flows/{$this->flow->id}/analytics");
        $response->assertStatus(403);
    }

    public function test_flow_analytics_returns_accurate_kpis_and_menu_breakdown(): void
    {
        Sanctum::actingAs($this->adminUser);

        // Contact 1 (Juan): chooses 'Petición de Oración' and completes flow
        $contactJuan = Contact::create(['display_name' => 'Juan Perez', 'external_identifier' => 'c-1']);
        $convJuan = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contactJuan->id,
            'status' => ConversationStatus::BOT,
            'started_at' => Carbon::now()->subDays(2),
        ]);
        $execJuan = FlowExecution::create([
            'conversation_id' => $convJuan->id,
            'flow_id' => $this->flow->id,
            'flow_version_id' => $this->version->id,
            'status' => FlowExecutionStatus::COMPLETED,
            'started_at' => Carbon::now()->subDays(2),
            'completed_at' => Carbon::now()->subDays(2)->addMinutes(5),
        ]);
        // Steps for Juan
        FlowExecutionStep::create([
            'flow_execution_id' => $execJuan->id,
            'flow_node_id' => $this->menuNode->id,
            'node_type' => FlowNodeType::MENU->value,
            'status' => FlowExecutionStepStatus::COMPLETED,
            'entered_at' => Carbon::now()->subDays(2),
            'completed_at' => Carbon::now()->subDays(2)->addMinute(),
            'output' => [
                'selected_option_id' => '1',
                'selected_option_text' => 'Petición de Oración',
                'target_node_key' => 'nodo_oracion',
            ],
        ]);
        FlowExecutionStep::create([
            'flow_execution_id' => $execJuan->id,
            'flow_node_id' => $this->oracionNode->id,
            'node_type' => FlowNodeType::MESSAGE->value,
            'status' => FlowExecutionStepStatus::COMPLETED,
            'entered_at' => Carbon::now()->subDays(2)->addMinute(),
            'completed_at' => Carbon::now()->subDays(2)->addMinutes(2),
        ]);

        // Contact 2 (Maria): chooses 'Petición de Oración' and completes flow
        $contactMaria = Contact::create(['display_name' => 'Maria Lopez', 'external_identifier' => 'c-2']);
        $convMaria = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contactMaria->id,
            'status' => ConversationStatus::BOT,
            'started_at' => Carbon::now()->subDays(1),
        ]);
        $execMaria = FlowExecution::create([
            'conversation_id' => $convMaria->id,
            'flow_id' => $this->flow->id,
            'flow_version_id' => $this->version->id,
            'status' => FlowExecutionStatus::COMPLETED,
            'started_at' => Carbon::now()->subDays(1),
            'completed_at' => Carbon::now()->subDays(1)->addMinutes(3),
        ]);
        FlowExecutionStep::create([
            'flow_execution_id' => $execMaria->id,
            'flow_node_id' => $this->menuNode->id,
            'node_type' => FlowNodeType::MENU->value,
            'status' => FlowExecutionStepStatus::COMPLETED,
            'entered_at' => Carbon::now()->subDays(1),
            'completed_at' => Carbon::now()->subDays(1)->addMinute(),
            'output' => [
                'selected_option_id' => '1',
                'selected_option_text' => 'Petición de Oración',
                'target_node_key' => 'nodo_oracion',
            ],
        ]);

        // Contact 3 (Carlos): chooses 'Ofrendar'
        $contactCarlos = Contact::create(['display_name' => 'Carlos Gomez', 'external_identifier' => 'c-3']);
        $convCarlos = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $contactCarlos->id,
            'status' => ConversationStatus::BOT,
            'started_at' => Carbon::now()->subHours(5),
        ]);
        $execCarlos = FlowExecution::create([
            'conversation_id' => $convCarlos->id,
            'flow_id' => $this->flow->id,
            'flow_version_id' => $this->version->id,
            'status' => FlowExecutionStatus::COMPLETED,
            'started_at' => Carbon::now()->subHours(5),
            'completed_at' => Carbon::now()->subHours(5)->addMinutes(4),
        ]);
        FlowExecutionStep::create([
            'flow_execution_id' => $execCarlos->id,
            'flow_node_id' => $this->menuNode->id,
            'node_type' => FlowNodeType::MENU->value,
            'status' => FlowExecutionStepStatus::COMPLETED,
            'entered_at' => Carbon::now()->subHours(5),
            'completed_at' => Carbon::now()->subHours(5)->addMinute(),
            'output' => [
                'selected_option_id' => '2',
                'selected_option_text' => 'Ofrendar',
                'target_node_key' => 'nodo_ofrendar',
            ],
        ]);

        // Contact 4 (Ana): drops off at menu (never answers, stays in waiting_input)
        $contactAna = Contact::create(['display_name' => 'Ana Torres', 'external_identifier' => 'c-4']);
        $convAna = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contactAna->id,
            'status' => ConversationStatus::BOT,
            'started_at' => Carbon::now()->subHours(2),
        ]);
        $execAna = FlowExecution::create([
            'conversation_id' => $convAna->id,
            'flow_id' => $this->flow->id,
            'flow_version_id' => $this->version->id,
            'status' => FlowExecutionStatus::WAITING_INPUT,
            'current_node_id' => $this->menuNode->id,
            'started_at' => Carbon::now()->subHours(2),
            'waiting_since' => Carbon::now()->subHours(2),
        ]);
        FlowExecutionStep::create([
            'flow_execution_id' => $execAna->id,
            'flow_node_id' => $this->menuNode->id,
            'node_type' => FlowNodeType::MENU->value,
            'status' => FlowExecutionStepStatus::WAITING,
            'entered_at' => Carbon::now()->subHours(2),
        ]);

        // Query analytics
        $response = $this->getJson("/api/v1/flows/{$this->flow->id}/analytics");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'flow_id',
                    'flow_name',
                    'date_range' => ['from', 'to'],
                    'kpis' => [
                        'total_executions',
                        'unique_contacts',
                        'completed_executions',
                        'completion_rate',
                        'dropoff_count',
                        'dropoff_rate',
                        'top_section' => ['title', 'clicks_count', 'percentage'],
                    ],
                    'menu_breakdown',
                    'time_series',
                ],
            ]);

        $data = $response->json('data');

        // Total executions = 4
        $this->assertEquals(4, $data['kpis']['total_executions']);
        // Unique contacts = 4
        $this->assertEquals(4, $data['kpis']['unique_contacts']);
        // Completed executions = 3
        $this->assertEquals(3, $data['kpis']['completed_executions']);
        // Completion rate = 75.0%
        $this->assertEquals(75.0, $data['kpis']['completion_rate']);
        // Dropoff count = 1, rate = 25.0%
        $this->assertEquals(1, $data['kpis']['dropoff_count']);
        $this->assertEquals(25.0, $data['kpis']['dropoff_rate']);

        // Check menu breakdown
        $this->assertCount(1, $data['menu_breakdown']);
        $menuData = $data['menu_breakdown'][0];
        $this->assertEquals($this->menuNode->id, $menuData['node_id']);
        $this->assertEquals('Menú Principal', $menuData['node_title']);
        $this->assertEquals(3, $menuData['total_selections']); // 2 oración + 1 ofrendar

        // Check options
        $options = collect($menuData['options'])->keyBy('option_id');
        $this->assertArrayHasKey('1', $options);
        $this->assertArrayHasKey('2', $options);
        $this->assertArrayHasKey('3', $options);

        // Option 1: Petición de Oración -> 2 clicks, 66.7%
        $this->assertEquals(2, $options['1']['clicks_count']);
        $this->assertEquals(66.7, $options['1']['percentage']);
        $this->assertEquals('Petición de Oración', $options['1']['title']);

        // Option 2: Ofrendar -> 1 click, 33.3%
        $this->assertEquals(1, $options['2']['clicks_count']);
        $this->assertEquals(33.3, $options['2']['percentage']);
        $this->assertEquals('Ofrendar', $options['2']['title']);

        // Option 3: Horarios -> 0 clicks, 0.0%
        $this->assertEquals(0, $options['3']['clicks_count']);
        $this->assertEquals(0.0, $options['3']['percentage']);

        // Top section
        $this->assertEquals('Petición de Oración', $data['kpis']['top_section']['title']);
        $this->assertEquals(2, $data['kpis']['top_section']['clicks_count']);
    }

    public function test_flow_analytics_respects_date_filters(): void
    {
        Sanctum::actingAs($this->adminUser);

        $contact = Contact::create(['display_name' => 'Test User', 'external_identifier' => 'c-test']);

        // Old execution outside range (45 days ago)
        $oldConv = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
            'started_at' => Carbon::now()->subDays(45),
        ]);
        FlowExecution::create([
            'conversation_id' => $oldConv->id,
            'flow_id' => $this->flow->id,
            'flow_version_id' => $this->version->id,
            'status' => FlowExecutionStatus::COMPLETED,
            'started_at' => Carbon::now()->subDays(45),
            'completed_at' => Carbon::now()->subDays(45)->addMinutes(2),
        ]);

        // Recent execution (yesterday)
        $recentConv = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
            'started_at' => Carbon::now()->subDay(),
        ]);
        FlowExecution::create([
            'conversation_id' => $recentConv->id,
            'flow_id' => $this->flow->id,
            'flow_version_id' => $this->version->id,
            'status' => FlowExecutionStatus::COMPLETED,
            'started_at' => Carbon::now()->subDay(),
            'completed_at' => Carbon::now()->subDay()->addMinutes(2),
        ]);

        // Query with from = 7 days ago
        $from = Carbon::now()->subDays(7)->toDateString();
        $to = Carbon::now()->toDateString();

        $response = $this->getJson("/api/v1/flows/{$this->flow->id}/analytics?from={$from}&to={$to}");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Only the 1 recent execution should be included
        $this->assertEquals(1, $data['kpis']['total_executions']);
    }

    public function test_flow_analytics_handles_empty_interactions_gracefully(): void
    {
        Sanctum::actingAs($this->adminUser);

        // Flow without any executions
        $emptyFlow = Flow::create([
            'name' => 'Flujo Vacío',
            'status' => FlowStatus::PUBLISHED,
        ]);
        $emptyVersion = FlowVersion::create([
            'flow_id' => $emptyFlow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::PUBLISHED,
        ]);
        $emptyFlow->update(['active_version_id' => $emptyVersion->id]);

        $response = $this->getJson("/api/v1/flows/{$emptyFlow->id}/analytics");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals(0, $data['kpis']['total_executions']);
        $this->assertEquals(0, $data['kpis']['unique_contacts']);
        $this->assertEquals(0, $data['kpis']['completed_executions']);
        $this->assertEquals(0.0, $data['kpis']['completion_rate']);
        $this->assertEquals(0, $data['kpis']['dropoff_count']);
        $this->assertEquals(0.0, $data['kpis']['dropoff_rate']);
        $this->assertNull($data['kpis']['top_section']);
        $this->assertEmpty($data['menu_breakdown']);
    }

    public function test_flow_analytics_respects_channel_filter(): void
    {
        Sanctum::actingAs($this->adminUser);

        $contact = Contact::create(['display_name' => 'Channel User', 'external_identifier' => 'c-chan']);

        // Webchat execution
        $webchatConv = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
            'started_at' => Carbon::now()->subHours(2),
        ]);
        FlowExecution::create([
            'conversation_id' => $webchatConv->id,
            'flow_id' => $this->flow->id,
            'flow_version_id' => $this->version->id,
            'status' => FlowExecutionStatus::COMPLETED,
            'started_at' => Carbon::now()->subHours(2),
        ]);

        // WhatsApp execution
        $waConv = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
            'started_at' => Carbon::now()->subHours(1),
        ]);
        FlowExecution::create([
            'conversation_id' => $waConv->id,
            'flow_id' => $this->flow->id,
            'flow_version_id' => $this->version->id,
            'status' => FlowExecutionStatus::COMPLETED,
            'started_at' => Carbon::now()->subHours(1),
        ]);

        // Query filtering by WhatsApp channel only
        $response = $this->getJson("/api/v1/flows/{$this->flow->id}/analytics?channel_id={$this->whatsappChannel->id}");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals(1, $data['kpis']['total_executions']);
    }
}
