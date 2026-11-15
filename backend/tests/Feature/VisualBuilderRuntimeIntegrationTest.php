<?php

namespace Tests\Feature;

use App\Enums\ConversationStatus;
use App\Enums\FlowStatus;
use App\Models\Channel;
use App\Models\Flow;
use App\Models\Role;
use App\Models\User;
use App\Services\Runtime\ConversationIngressService;
use App\Services\Runtime\FlowRuntimeEngine;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VisualBuilderRuntimeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected ConversationIngressService $ingress;

    protected FlowRuntimeEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::create([
            'name' => 'Visual Builder Admin',
            'email' => 'builder_admin@test.com',
            'password' => Hash::make('TestSecuredPass@2026'),
            'is_active' => true,
        ]);

        $this->adminUser->roles()->attach(Role::where('name', 'super_admin')->first());

        $this->ingress = app(ConversationIngressService::class);
        $this->engine = app(FlowRuntimeEngine::class);
    }

    /**
     * Test full lifecycle:
     * 1. Create Flow via API
     * 2. Save Graph via Visual Builder API
     * 3. Fetch/Re-open Graph and assert positions, configs, and edges
     * 4. Validate Graph via Validator API
     * 5. Publish Flow via Publish API
     * 6. Associate Flow as Channel Entry Flow
     * 7. Execute Runtime Engine True Path
     * 8. Execute Runtime Engine False Path
     */
    public function test_visual_builder_graph_publication_and_runtime_execution_e2e(): void
    {
        // Step 1: Create Flow via API
        $createFlowRes = $this->actingAs($this->adminUser, 'sanctum')->postJson('/api/v1/flows', [
            'name' => 'Flujo Atención Visual Builder',
            'description' => 'Creado mediante el constructor visual',
            'channel_applicability' => ['webchat'],
        ]);

        $createFlowRes->assertStatus(201);
        $flowId = $createFlowRes->json('data.id');

        $flow = Flow::with('versions')->findOrFail($flowId);
        $version = $flow->versions->first();
        $this->assertNotNull($version);

        // Step 2: Save Graph constructed in Visual Builder (Section 58)
        $builderPayload = [
            'nodes' => [
                [
                    'node_key' => 'start_1',
                    'type' => 'start',
                    'name' => 'Inicio',
                    'position_x' => 250,
                    'position_y' => 50,
                    'configuration' => [],
                ],
                [
                    'node_key' => 'msg_welcome',
                    'type' => 'message',
                    'name' => 'Bienvenida',
                    'position_x' => 250,
                    'position_y' => 170,
                    'configuration' => ['text' => 'Bienvenido a nuestro servicio.'],
                ],
                [
                    'node_key' => 'q_name',
                    'type' => 'question',
                    'name' => 'Preguntar Nombre',
                    'position_x' => 250,
                    'position_y' => 310,
                    'configuration' => [
                        'text' => '¿Cómo te llamas?',
                        'variable' => 'nombre',
                        'input_type' => 'text',
                    ],
                ],
                [
                    'node_key' => 'msg_greeting',
                    'type' => 'message',
                    'name' => 'Saludo Personalizado',
                    'position_x' => 250,
                    'position_y' => 470,
                    'configuration' => ['text' => 'Hola {{nombre}}'],
                ],
                [
                    'node_key' => 'q_client',
                    'type' => 'question',
                    'name' => 'Preguntar si es cliente',
                    'position_x' => 250,
                    'position_y' => 610,
                    'configuration' => [
                        'text' => '¿Eres cliente?',
                        'variable' => 'cliente',
                        'input_type' => 'choice',
                        'choices' => ['si', 'no'],
                        'invalid_message' => 'Por favor responda si o no.',
                    ],
                ],
                [
                    'node_key' => 'cond_client',
                    'type' => 'condition',
                    'name' => 'Evaluar si es cliente',
                    'position_x' => 250,
                    'position_y' => 770,
                    'configuration' => [
                        'variable' => 'cliente',
                        'operator' => 'equals',
                        'value' => 'si',
                    ],
                ],
                [
                    'node_key' => 'msg_client_yes',
                    'type' => 'message',
                    'name' => 'Mensaje Si',
                    'position_x' => 100,
                    'position_y' => 930,
                    'configuration' => ['text' => 'Excelente'],
                ],
                [
                    'node_key' => 'transfer_agent',
                    'type' => 'transfer_to_agent',
                    'name' => 'Transferir a Ejecutivo',
                    'position_x' => 100,
                    'position_y' => 1080,
                    'configuration' => ['message' => 'Te comunicaré con uno de nuestros agentes.'],
                ],
                [
                    'node_key' => 'msg_client_no',
                    'type' => 'message',
                    'name' => 'Mensaje No',
                    'position_x' => 400,
                    'position_y' => 930,
                    'configuration' => ['text' => 'Entendido'],
                ],
                [
                    'node_key' => 'end_flow',
                    'type' => 'end',
                    'name' => 'Fin de Conversación',
                    'position_x' => 400,
                    'position_y' => 1080,
                    'configuration' => ['message' => 'Gracias por contactarnos.'],
                ],
            ],
            'edges' => [
                ['source_node_key' => 'start_1', 'target_node_key' => 'msg_welcome'],
                ['source_node_key' => 'msg_welcome', 'target_node_key' => 'q_name'],
                ['source_node_key' => 'q_name', 'target_node_key' => 'msg_greeting'],
                ['source_node_key' => 'msg_greeting', 'target_node_key' => 'q_client'],
                ['source_node_key' => 'q_client', 'target_node_key' => 'cond_client'],
                ['source_node_key' => 'cond_client', 'target_node_key' => 'msg_client_yes', 'condition_handle' => 'true'],
                ['source_node_key' => 'cond_client', 'target_node_key' => 'msg_client_no', 'condition_handle' => 'false'],
                ['source_node_key' => 'msg_client_yes', 'target_node_key' => 'transfer_agent'],
                ['source_node_key' => 'msg_client_no', 'target_node_key' => 'end_flow'],
            ],
        ];

        $saveRes = $this->actingAs($this->adminUser, 'sanctum')->putJson(
            "/api/v1/flows/{$flow->id}/versions/{$version->id}/graph",
            $builderPayload
        );
        $saveRes->assertStatus(200);

        // Step 3: Close & Reopen Editor Verification (Section 58)
        $reopenRes = $this->actingAs($this->adminUser, 'sanctum')->getJson(
            "/api/v1/flows/{$flow->id}/versions/{$version->id}"
        );
        $reopenRes->assertStatus(200);

        $savedNodes = $reopenRes->json('data.nodes');
        $savedEdges = $reopenRes->json('data.edges');

        $this->assertCount(10, $savedNodes, 'Must persist all 10 nodes');
        $this->assertCount(9, $savedEdges, 'Must persist all 9 edges');

        // Confirm position persistence
        $qClientNode = collect($savedNodes)->firstWhere('node_key', 'q_client');
        $this->assertEquals(250, $qClientNode['position_x']);
        $this->assertEquals(610, $qClientNode['position_y']);
        $this->assertEquals('cliente', $qClientNode['configuration']['variable']);

        // Confirm condition handles persistence
        $trueEdge = collect($savedEdges)->firstWhere('condition_handle', 'true');
        $this->assertNotNull($trueEdge);
        $this->assertEquals('cond_client', $trueEdge['source_node_key']);
        $this->assertEquals('msg_client_yes', $trueEdge['target_node_key']);

        $falseEdge = collect($savedEdges)->firstWhere('condition_handle', 'false');
        $this->assertNotNull($falseEdge);
        $this->assertEquals('cond_client', $falseEdge['source_node_key']);
        $this->assertEquals('msg_client_no', $falseEdge['target_node_key']);

        // Step 4: Validate Graph
        $validateRes = $this->actingAs($this->adminUser, 'sanctum')->postJson(
            "/api/v1/flows/{$flow->id}/versions/{$version->id}/validate"
        );
        $validateRes->assertStatus(200);
        $validateRes->assertJson([
            'valid' => true,
            'is_valid' => true,
            'errors' => [],
        ]);

        // Step 5: Publish Version
        $publishRes = $this->actingAs($this->adminUser, 'sanctum')->postJson(
            "/api/v1/flows/{$flow->id}/versions/{$version->id}/publish"
        );
        $publishRes->assertStatus(200);
        $publishRes->assertJson([
            'data' => [
                'status' => FlowStatus::PUBLISHED->value,
                'active_version_id' => $version->id,
            ],
        ]);

        // Step 6: Associate Flow as Channel Entry Flow
        $channel = Channel::create([
            'name' => 'Canal Webchat Principal',
            'type' => 'webchat',
            'is_active' => true,
            'entry_flow_id' => $flow->id,
            'configuration' => [],
        ]);

        // Step 7: True Path Execution (Section 59)
        $payloadTrue = [
            'display_name' => 'Carlos Cliente',
            'external_identifier' => 'client_carlos_1',
        ];

        // Turn 1: Inbound message starts flow -> sends Welcome and asks Name
        $resTrue = $this->ingress->handleInboundMessage($channel, $payloadTrue, 'Hola buenas');
        $convTrue = $resTrue['conversation'];
        $this->assertEquals(ConversationStatus::BOT, $convTrue->fresh()->status);

        $messages = $convTrue->messages()->orderBy('sent_at')->pluck('content')->all();
        $this->assertContains('Bienvenido a nuestro servicio.', $messages);
        $this->assertContains('¿Cómo te llamas?', $messages);

        // Turn 2: Carlos replies with his name
        $this->ingress->handleInboundMessage($channel, $payloadTrue, 'Carlos');
        $messages = $convTrue->messages()->orderBy('sent_at')->pluck('content')->all();
        $this->assertContains('Hola Carlos', $messages);
        $this->assertContains('¿Eres cliente?', $messages);

        // Turn 3: Carlos replies "si" -> Condition evaluates TRUE -> Message "Excelente" -> Transfer to agent
        $this->ingress->handleInboundMessage($channel, $payloadTrue, 'si');
        $messages = $convTrue->messages()->orderBy('sent_at')->pluck('content')->all();
        $this->assertContains('Excelente', $messages);
        $this->assertContains('Te comunicaré con uno de nuestros agentes.', $messages);
        $this->assertEquals(ConversationStatus::WAITING_AGENT, $convTrue->fresh()->status, 'Carlos must be waiting for human agent');

        // Step 8: False Path Execution (Section 59)
        $payloadFalse = [
            'display_name' => 'Lucia Prospecto',
            'external_identifier' => 'prospect_lucia_2',
        ];

        // Turn 1: Inbound message starts flow
        $resFalse = $this->ingress->handleInboundMessage($channel, $payloadFalse, 'Hola');
        $convFalse = $resFalse['conversation'];
        $this->assertEquals(ConversationStatus::BOT, $convFalse->fresh()->status);

        // Turn 2: Lucia replies name
        $this->ingress->handleInboundMessage($channel, $payloadFalse, 'Lucia');
        $messages = $convFalse->messages()->orderBy('sent_at')->pluck('content')->all();
        $this->assertContains('Hola Lucia', $messages);
        $this->assertContains('¿Eres cliente?', $messages);

        // Turn 3: Lucia replies "no" -> Condition evaluates FALSE -> Message "Entendido" -> End Flow
        $this->ingress->handleInboundMessage($channel, $payloadFalse, 'no');
        $messages = $convFalse->messages()->orderBy('sent_at')->pluck('content')->all();
        $this->assertContains('Entendido', $messages);
        $this->assertContains('Gracias por contactarnos.', $messages);
        $this->assertEquals(ConversationStatus::CLOSED, $convFalse->fresh()->status, 'Lucia conversation must be closed at End node');
    }
}
