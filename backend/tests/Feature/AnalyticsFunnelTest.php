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

class AnalyticsFunnelTest extends TestCase
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
    }

    public function test_funnels_endpoint_returns_flow_menu_statistics(): void
    {
        Sanctum::actingAs($this->adminUser);

        $contact = Contact::create(['display_name' => 'Carlos Orante', 'external_identifier' => 'c-orante', 'phone' => '+5212345678']);
        $conv = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
            'started_at' => Carbon::now()->subDays(2),
        ]);

        $exec = FlowExecution::create([
            'conversation_id' => $conv->id,
            'flow_id' => $this->flow->id,
            'flow_version_id' => $this->version->id,
            'status' => FlowExecutionStatus::COMPLETED,
            'started_at' => Carbon::now()->subDays(2),
        ]);

        FlowExecutionStep::create([
            'flow_execution_id' => $exec->id,
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

        $response = $this->getJson("/api/v1/analytics/funnels?flow_id={$this->flow->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'flow_id',
                    'flow_name',
                    'kpis',
                    'menu_breakdown',
                ],
            ]);

        $data = $response->json('data');
        $this->assertEquals($this->flow->id, $data['flow_id']);
        $this->assertEquals(1, $data['kpis']['total_executions']);
        $this->assertCount(1, $data['menu_breakdown']);
        $this->assertEquals(1, $data['menu_breakdown'][0]['options'][0]['clicks_count']);
    }

    public function test_funnel_contacts_drill_down_endpoint(): void
    {
        Sanctum::actingAs($this->adminUser);

        $contact = Contact::create([
            'display_name' => 'Lucia Gomez',
            'external_identifier' => 'lucia-1',
            'phone' => '+54911223344',
            'email' => 'lucia@example.com',
        ]);

        $conv = Conversation::create([
            'channel_id' => $this->whatsappChannel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
            'started_at' => Carbon::now()->subHour(),
        ]);

        $exec = FlowExecution::create([
            'conversation_id' => $conv->id,
            'flow_id' => $this->flow->id,
            'flow_version_id' => $this->version->id,
            'status' => FlowExecutionStatus::COMPLETED,
            'started_at' => Carbon::now()->subHour(),
        ]);

        FlowExecutionStep::create([
            'flow_execution_id' => $exec->id,
            'flow_node_id' => $this->menuNode->id,
            'node_type' => FlowNodeType::MENU->value,
            'status' => FlowExecutionStepStatus::COMPLETED,
            'entered_at' => Carbon::now()->subHour(),
            'completed_at' => Carbon::now()->subHour()->addMinutes(2),
            'output' => [
                'selected_option_id' => '2',
                'selected_option_text' => 'Ofrendar',
                'target_node_key' => 'nodo_ofrendar',
            ],
        ]);

        $response = $this->getJson("/api/v1/analytics/funnels/contacts?flow_id={$this->flow->id}&node_id={$this->menuNode->id}&option_id=2");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'contact_id',
                        'contact_name',
                        'phone_number',
                        'channel_type',
                        'conversation_id',
                        'stepped_at',
                    ],
                ],
                'total',
            ]);

        $contacts = $response->json('data');
        $this->assertCount(1, $contacts);
        $this->assertEquals('Lucia Gomez', $contacts[0]['contact_name']);
        $this->assertEquals('+54911223344', $contacts[0]['phone_number']);
        $this->assertEquals($conv->id, $contacts[0]['conversation_id']);
    }

    public function test_funnel_contacts_export_csv(): void
    {
        Sanctum::actingAs($this->adminUser);

        $contact = Contact::create([
            'display_name' => 'Pedro Export',
            'external_identifier' => 'pedro-1',
            'phone' => '+52199887766',
        ]);

        $conv = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
            'started_at' => Carbon::now()->subHours(2),
        ]);

        $exec = FlowExecution::create([
            'conversation_id' => $conv->id,
            'flow_id' => $this->flow->id,
            'flow_version_id' => $this->version->id,
            'status' => FlowExecutionStatus::COMPLETED,
            'started_at' => Carbon::now()->subHours(2),
        ]);

        FlowExecutionStep::create([
            'flow_execution_id' => $exec->id,
            'flow_node_id' => $this->menuNode->id,
            'node_type' => FlowNodeType::MENU->value,
            'status' => FlowExecutionStepStatus::COMPLETED,
            'entered_at' => Carbon::now()->subHours(2),
            'completed_at' => Carbon::now()->subHours(2)->addMinute(),
            'output' => [
                'selected_option_id' => '1',
                'selected_option_text' => 'Petición de Oración',
                'target_node_key' => 'nodo_oracion',
            ],
        ]);

        $response = $this->get("/api/v1/analytics/funnels/contacts?flow_id={$this->flow->id}&node_id={$this->menuNode->id}&option_id=1&export=csv");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Pedro Export', $response->getContent());
        $this->assertStringContainsString('+52199887766', $response->getContent());
    }

    public function test_sequential_funnel_multi_step_calculation_and_dropoff(): void
    {
        Sanctum::actingAs($this->adminUser);

        // Contact 1: Traverses Start -> Menu Option 1 (Oración) -> Sección Oración
        $contact1 = Contact::create(['display_name' => 'Usuario Uno', 'phone' => '+5211111111']);
        $conv1 = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contact1->id,
            'status' => ConversationStatus::BOT,
            'started_at' => Carbon::now()->subHours(3),
        ]);
        $exec1 = FlowExecution::create([
            'conversation_id' => $conv1->id,
            'flow_id' => $this->flow->id,
            'flow_version_id' => $this->version->id,
            'status' => FlowExecutionStatus::COMPLETED,
            'started_at' => Carbon::now()->subHours(3),
        ]);
        FlowExecutionStep::create([
            'flow_execution_id' => $exec1->id,
            'flow_node_id' => $this->startNode->id,
            'node_type' => FlowNodeType::START->value,
            'status' => FlowExecutionStepStatus::COMPLETED,
            'entered_at' => Carbon::now()->subHours(3),
            'completed_at' => Carbon::now()->subHours(3)->addSeconds(10),
        ]);
        FlowExecutionStep::create([
            'flow_execution_id' => $exec1->id,
            'flow_node_id' => $this->menuNode->id,
            'node_type' => FlowNodeType::MENU->value,
            'status' => FlowExecutionStepStatus::COMPLETED,
            'entered_at' => Carbon::now()->subHours(3)->addSeconds(15),
            'completed_at' => Carbon::now()->subHours(3)->addSeconds(40),
            'output' => [
                'selected_option_id' => '1',
                'selected_option_text' => 'Petición de Oración',
                'target_node_key' => 'nodo_oracion',
            ],
        ]);
        FlowExecutionStep::create([
            'flow_execution_id' => $exec1->id,
            'flow_node_id' => $this->oracionNode->id,
            'node_type' => FlowNodeType::MESSAGE->value,
            'status' => FlowExecutionStepStatus::COMPLETED,
            'entered_at' => Carbon::now()->subHours(3)->addSeconds(45),
            'completed_at' => Carbon::now()->subHours(3)->addSeconds(60),
        ]);

        // Contact 2: Traverses Start -> Menu Option 2 (Ofrendar) -> Sección Ofrendar
        $contact2 = Contact::create(['display_name' => 'Usuario Dos', 'phone' => '+5222222222']);
        $conv2 = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contact2->id,
            'status' => ConversationStatus::BOT,
            'started_at' => Carbon::now()->subHours(2),
        ]);
        $exec2 = FlowExecution::create([
            'conversation_id' => $conv2->id,
            'flow_id' => $this->flow->id,
            'flow_version_id' => $this->version->id,
            'status' => FlowExecutionStatus::COMPLETED,
            'started_at' => Carbon::now()->subHours(2),
        ]);
        FlowExecutionStep::create([
            'flow_execution_id' => $exec2->id,
            'flow_node_id' => $this->startNode->id,
            'node_type' => FlowNodeType::START->value,
            'status' => FlowExecutionStepStatus::COMPLETED,
            'entered_at' => Carbon::now()->subHours(2),
            'completed_at' => Carbon::now()->subHours(2)->addSeconds(10),
        ]);
        FlowExecutionStep::create([
            'flow_execution_id' => $exec2->id,
            'flow_node_id' => $this->menuNode->id,
            'node_type' => FlowNodeType::MENU->value,
            'status' => FlowExecutionStepStatus::COMPLETED,
            'entered_at' => Carbon::now()->subHours(2)->addSeconds(15),
            'completed_at' => Carbon::now()->subHours(2)->addSeconds(40),
            'output' => [
                'selected_option_id' => '2',
                'selected_option_text' => 'Ofrendar',
                'target_node_key' => 'nodo_ofrendar',
            ],
        ]);
        FlowExecutionStep::create([
            'flow_execution_id' => $exec2->id,
            'flow_node_id' => $this->ofrendarNode->id,
            'node_type' => FlowNodeType::MESSAGE->value,
            'status' => FlowExecutionStepStatus::COMPLETED,
            'entered_at' => Carbon::now()->subHours(2)->addSeconds(45),
            'completed_at' => Carbon::now()->subHours(2)->addSeconds(60),
        ]);

        // Configure a 3-step sequence: Start -> Menu Option 1 (Oración) -> Sección Oración
        $steps = [
            [
                'node_id' => $this->startNode->id,
                'label' => 'Inicio',
                'is_active' => true,
            ],
            [
                'node_id' => $this->menuNode->id,
                'option_id' => '1',
                'label' => 'Menú › Petición de Oración',
                'is_active' => true,
            ],
            [
                'node_id' => $this->oracionNode->id,
                'label' => 'Sección Oración',
                'is_active' => true,
            ],
        ];

        $stepsJson = json_encode($steps);
        $response = $this->getJson("/api/v1/analytics/funnels?flow_id={$this->flow->id}&steps=".urlencode($stepsJson));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'sequential_funnel',
                    'available_steps',
                ],
            ]);

        $seq = $response->json('data.sequential_funnel');
        $this->assertCount(3, $seq);

        // Step 1: Both users reached start
        $this->assertEquals(1, $seq[0]['step_number']);
        $this->assertEquals(2, $seq[0]['users_count']);
        $this->assertEquals(100.0, $seq[0]['conversion_from_previous']);
        $this->assertEquals(0.0, $seq[0]['dropoff_from_previous']);

        // Step 2: Only Contact 1 chose option 1
        $this->assertEquals(2, $seq[1]['step_number']);
        $this->assertEquals(1, $seq[1]['users_count']);
        $this->assertEquals(50.0, $seq[1]['conversion_from_previous']);
        $this->assertEquals(50.0, $seq[1]['dropoff_from_previous']);
        $this->assertEquals(50.0, $seq[1]['conversion_from_first']);

        // Step 3: Contact 1 continued to oracion node
        $this->assertEquals(3, $seq[2]['step_number']);
        $this->assertEquals(1, $seq[2]['users_count']);
        $this->assertEquals(100.0, $seq[2]['conversion_from_previous']);
        $this->assertEquals(0.0, $seq[2]['dropoff_from_previous']);
        $this->assertEquals(50.0, $seq[2]['conversion_from_first']);
    }

    public function test_step_contacts_endpoint_and_csv_export(): void
    {
        Sanctum::actingAs($this->adminUser);

        $contact = Contact::create(['display_name' => 'Marcos Secuencial', 'phone' => '+54911998877']);
        $conv = Conversation::create([
            'channel_id' => $this->webchatChannel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
            'started_at' => Carbon::now()->subMinutes(30),
        ]);
        $exec = FlowExecution::create([
            'conversation_id' => $conv->id,
            'flow_id' => $this->flow->id,
            'flow_version_id' => $this->version->id,
            'status' => FlowExecutionStatus::COMPLETED,
            'started_at' => Carbon::now()->subMinutes(30),
        ]);
        FlowExecutionStep::create([
            'flow_execution_id' => $exec->id,
            'flow_node_id' => $this->startNode->id,
            'node_type' => FlowNodeType::START->value,
            'status' => FlowExecutionStepStatus::COMPLETED,
            'entered_at' => Carbon::now()->subMinutes(30),
            'completed_at' => Carbon::now()->subMinutes(30)->addSeconds(5),
        ]);
        FlowExecutionStep::create([
            'flow_execution_id' => $exec->id,
            'flow_node_id' => $this->menuNode->id,
            'node_type' => FlowNodeType::MENU->value,
            'status' => FlowExecutionStepStatus::COMPLETED,
            'entered_at' => Carbon::now()->subMinutes(30)->addSeconds(10),
            'completed_at' => Carbon::now()->subMinutes(30)->addSeconds(20),
            'output' => [
                'selected_option_id' => '1',
                'selected_option_text' => 'Petición de Oración',
                'target_node_key' => 'nodo_oracion',
            ],
        ]);

        $steps = [
            ['node_id' => $this->startNode->id, 'label' => 'Inicio', 'is_active' => true],
            ['node_id' => $this->menuNode->id, 'option_id' => '1', 'label' => 'Opción 1', 'is_active' => true],
        ];
        $stepsJson = json_encode($steps);

        // JSON query for Step 2 contacts
        $resJson = $this->getJson("/api/v1/analytics/funnels/step-contacts?flow_id={$this->flow->id}&step_number=2&steps=".urlencode($stepsJson));
        $resJson->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'contact_id',
                        'contact_name',
                        'phone_number',
                        'conversation_id',
                        'stepped_at',
                    ],
                ],
                'total',
            ]);

        $this->assertEquals(1, $resJson->json('total'));
        $this->assertEquals('Marcos Secuencial', $resJson->json('data.0.contact_name'));

        // CSV export for Step 2 contacts
        $resCsv = $this->get("/api/v1/analytics/funnels/step-contacts?flow_id={$this->flow->id}&step_number=2&steps=".urlencode($stepsJson).'&export=csv');
        $resCsv->assertStatus(200);
        $resCsv->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Marcos Secuencial', $resCsv->getContent());
        $this->assertStringContainsString('+54911998877', $resCsv->getContent());
    }

    public function test_sequential_funnel_respects_step_is_active_toggle(): void
    {
        Sanctum::actingAs($this->adminUser);

        $steps = [
            ['node_id' => $this->startNode->id, 'label' => 'Inicio', 'is_active' => true],
            ['node_id' => $this->menuNode->id, 'option_id' => '1', 'label' => 'Opción 1 Desactivada', 'is_active' => false],
            ['node_id' => $this->oracionNode->id, 'label' => 'Sección Oración', 'is_active' => true],
        ];

        $stepsJson = json_encode($steps);
        $response = $this->getJson("/api/v1/analytics/funnels?flow_id={$this->flow->id}&steps=".urlencode($stepsJson));

        $response->assertStatus(200);
        $seq = $response->json('data.sequential_funnel');

        // Step 2 was inactive, so only 2 steps should be evaluated
        $this->assertCount(2, $seq);
        $this->assertEquals('Inicio', $seq[0]['label']);
        $this->assertEquals('Sección Oración', $seq[1]['label']);
    }
}
