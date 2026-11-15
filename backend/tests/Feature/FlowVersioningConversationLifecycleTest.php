<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\FlowExecutionStatus;
use App\Enums\FlowNodeType;
use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Models\Channel;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Services\Runtime\ConversationIngressService;
use App\Services\Runtime\FlowRuntimeEngine;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowVersioningConversationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected ConversationIngressService $ingress;

    protected FlowRuntimeEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::factory()->create();
        $this->adminUser->roles()->attach(Role::where('name', 'super_admin')->first());

        $this->ingress = app(ConversationIngressService::class);
        $this->engine = app(FlowRuntimeEngine::class);
    }

    /**
     * Mandatory 17-Step Scenario from FASE 12:
     * 1. Publicar V1.
     * 2. Iniciar conversación A utilizando V1.
     * 3. Avanzar hasta una pregunta.
     * 4. Dejar conversación A esperando respuesta.
     * 5. Crear borrador V2.
     * 6. Modificar el comportamiento del flujo.
     * 7. Simular V2.
     * 8. Publicar V2.
     * 9. Continuar conversación A.
     * 10. Verificar que sigue ejecutando V1.
     * 11. Iniciar conversación B.
     * 12. Verificar que utiliza V2.
     * 13. Ejecutar rollback hacia V1.
     * 14. Iniciar conversación C.
     * 15. Verificar que utiliza V1.
     * 16. Continuar conversación B.
     * 17. Verificar que continúa en V2.
     */
    public function test_mandatory_flow_versioning_conversation_lifecycle_with_rollback(): void
    {
        // -------------------------------------------------------------
        // STEP 1: Publicar V1
        // -------------------------------------------------------------
        $flow = Flow::create([
            'name' => 'Flujo Preferencias',
            'status' => FlowStatus::DRAFT,
            'channel_applicability' => ['webchat'],
            'created_by' => $this->adminUser->id,
        ]);

        $channel = Channel::create([
            'name' => 'Canal Webchat Principal',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
            'entry_flow_id' => $flow->id,
        ]);

        $v1 = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::DRAFT,
            'description' => 'Versión inicial V1',
        ]);

        // V1 Graph: Start -> Q(color) -> End("Elegiste el color {{color}}")
        FlowNode::create([
            'flow_version_id' => $v1->id,
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
        ]);
        FlowNode::create([
            'flow_version_id' => $v1->id,
            'node_key' => 'q_color',
            'type' => FlowNodeType::QUESTION,
            'name' => 'Pregunta Color',
            'configuration' => [
                'text' => '¿Cuál es tu color favorito?',
                'variable' => 'color',
                'input_type' => 'text',
            ],
        ]);
        FlowNode::create([
            'flow_version_id' => $v1->id,
            'node_key' => 'end_1',
            'type' => FlowNodeType::END,
            'name' => 'Fin',
            'configuration' => [
                'message' => 'Elegiste el color {{color}}',
            ],
        ]);

        FlowEdge::create([
            'flow_version_id' => $v1->id,
            'source_node_key' => 'start_1',
            'target_node_key' => 'q_color',
        ]);
        FlowEdge::create([
            'flow_version_id' => $v1->id,
            'source_node_key' => 'q_color',
            'target_node_key' => 'end_1',
        ]);

        $pubRes1 = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$v1->id}/publish");
        $pubRes1->assertStatus(200);

        $flow->refresh();
        $this->assertEquals($v1->id, $flow->active_version_id);
        $this->assertEquals(FlowStatus::PUBLISHED, $flow->status);

        // -------------------------------------------------------------
        // STEPS 2, 3 & 4: Iniciar conversación A con V1 y esperar respuesta
        // -------------------------------------------------------------
        $ingressA1 = $this->ingress->handleInboundMessage(
            channel: $channel,
            contactPayload: [
                'external_identifier' => 'contact_user_a',
                'display_name' => 'Usuario A',
            ],
            content: 'Hola quiero empezar',
            externalMessageId: 'msg_ext_a_1'
        );

        $convA = $ingressA1['conversation'];
        $this->assertNotNull($convA);
        $this->assertEquals($flow->id, $convA->active_flow_id);
        $this->assertEquals($v1->id, $convA->active_flow_version_id);

        // Verificamos que el bot emitió la pregunta de V1
        $botMsgA = $convA->messages()->where('sender_type', 'bot')->latest('sent_at')->first();
        $this->assertNotNull($botMsgA);
        $this->assertStringContainsString('¿Cuál es tu color favorito?', $botMsgA->content);

        // La conversación A queda esperando respuesta (WAITING_INPUT)
        $activeExecA = $convA->flowExecutions()->where('status', FlowExecutionStatus::WAITING_INPUT)->first();
        $this->assertNotNull($activeExecA);
        $this->assertEquals($v1->id, $activeExecA->flow_version_id);

        // -------------------------------------------------------------
        // STEPS 5 & 6: Crear borrador V2 y modificar su comportamiento
        // -------------------------------------------------------------
        $createV2Res = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions", [
                'from_version_id' => $v1->id,
                'description' => 'Borrador V2 con pregunta de comida',
            ]);
        $createV2Res->assertStatus(201);
        $v2Id = $createV2Res->json('data.id');

        $v2 = FlowVersion::findOrFail($v2Id);
        $this->assertEquals(2, $v2->version_number);
        $this->assertEquals(FlowVersionStatus::DRAFT, $v2->status);

        // Guardamos nuevo grafo en V2: Start -> Q(comida) -> End("Elegiste la comida {{comida}}")
        $saveV2Res = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson("/api/v1/flows/{$flow->id}/versions/{$v2->id}/graph", [
                'nodes' => [
                    [
                        'node_key' => 'start_1',
                        'type' => 'start',
                        'name' => 'Inicio V2',
                    ],
                    [
                        'node_key' => 'q_food',
                        'type' => 'question',
                        'name' => 'Pregunta Comida',
                        'configuration' => [
                            'text' => '¿Cuál es tu comida favorita?',
                            'variable' => 'comida',
                            'input_type' => 'text',
                        ],
                    ],
                    [
                        'node_key' => 'end_1',
                        'type' => 'end',
                        'name' => 'Fin V2',
                        'configuration' => [
                            'message' => 'Elegiste la comida {{comida}}',
                        ],
                    ],
                ],
                'edges' => [
                    ['source_node_key' => 'start_1', 'target_node_key' => 'q_food'],
                    ['source_node_key' => 'q_food', 'target_node_key' => 'end_1'],
                ],
            ]);
        $saveV2Res->assertStatus(200);

        // -------------------------------------------------------------
        // STEP 7: Simular V2
        // -------------------------------------------------------------
        $simStartRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$v2->id}/simulation/start");
        $simStartRes->assertStatus(200);
        $simBotMsg = $simStartRes->json('data.messages.0.content');
        $this->assertStringContainsString('¿Cuál es tu comida favorita?', $simBotMsg);

        // -------------------------------------------------------------
        // STEP 8: Publicar V2
        // -------------------------------------------------------------
        $pubRes2 = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$v2->id}/publish");
        $pubRes2->assertStatus(200);

        $flow->refresh();
        $this->assertEquals($v2->id, $flow->active_version_id);

        // -------------------------------------------------------------
        // STEPS 9 & 10: Continuar conversación A y verificar que sigue en V1
        // -------------------------------------------------------------
        $ingressA2 = $this->ingress->handleInboundMessage(
            channel: $channel,
            contactPayload: [
                'external_identifier' => 'contact_user_a',
                'display_name' => 'Usuario A',
            ],
            content: 'Verde',
            externalMessageId: 'msg_ext_a_2'
        );

        $convA->refresh();
        $this->assertEquals($v1->id, $convA->active_flow_version_id);

        $finalMsgA = Message::where('conversation_id', $convA->id)->where('sender_type', 'bot')->latest('sent_at')->first();
        $this->assertNotNull($finalMsgA);
        // Debe resolver con la lógica de V1 ("Elegiste el color Verde")
        $this->assertStringContainsString('Elegiste el color Verde', $finalMsgA->content);
        $this->assertStringNotContainsString('comida', $finalMsgA->content);

        // -------------------------------------------------------------
        // STEPS 11 & 12: Iniciar conversación B y verificar que utiliza V2
        // -------------------------------------------------------------
        $ingressB1 = $this->ingress->handleInboundMessage(
            channel: $channel,
            contactPayload: [
                'external_identifier' => 'contact_user_b',
                'display_name' => 'Usuario B',
            ],
            content: 'Hola soy el usuario B',
            externalMessageId: 'msg_ext_b_1'
        );

        $convB = $ingressB1['conversation'];
        $this->assertNotNull($convB);
        $this->assertEquals($v2->id, $convB->active_flow_version_id);

        $botMsgB = $convB->messages()->where('sender_type', 'bot')->latest('sent_at')->first();
        $this->assertNotNull($botMsgB);
        // Debe recibir la pregunta de V2
        $this->assertStringContainsString('¿Cuál es tu comida favorita?', $botMsgB->content);

        // -------------------------------------------------------------
        // STEP 13: Ejecutar rollback hacia V1
        // -------------------------------------------------------------
        $rollbackRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flow->id}/versions/{$v1->id}/rollback", [
                'notes' => 'Restaurando V1',
            ]);
        $rollbackRes->assertStatus(200);

        $flow->refresh();
        // Ahora la versión activa en producción vuelve a ser V1
        $this->assertEquals($v1->id, $flow->active_version_id);

        // -------------------------------------------------------------
        // STEPS 14 & 15: Iniciar conversación C y verificar que utiliza V1
        // -------------------------------------------------------------
        $ingressC1 = $this->ingress->handleInboundMessage(
            channel: $channel,
            contactPayload: [
                'external_identifier' => 'contact_user_c',
                'display_name' => 'Usuario C',
            ],
            content: 'Hola soy el usuario C',
            externalMessageId: 'msg_ext_c_1'
        );

        $convC = $ingressC1['conversation'];
        $this->assertNotNull($convC);
        // Conversación C se ancla a la versión activa actual (V1)
        $this->assertEquals($v1->id, $convC->active_flow_version_id);

        $botMsgC = $convC->messages()->where('sender_type', 'bot')->latest('sent_at')->first();
        $this->assertNotNull($botMsgC);
        $this->assertStringContainsString('¿Cuál es tu color favorito?', $botMsgC->content);

        // -------------------------------------------------------------
        // STEPS 16 & 17: Continuar conversación B y verificar que continúa en V2
        // -------------------------------------------------------------
        $ingressB2 = $this->ingress->handleInboundMessage(
            channel: $channel,
            contactPayload: [
                'external_identifier' => 'contact_user_b',
                'display_name' => 'Usuario B',
            ],
            content: 'Tacos al pastor',
            externalMessageId: 'msg_ext_b_2'
        );

        $convB->refresh();
        // Conversación B continúa en su versión anclada (V2)
        $this->assertEquals($v2->id, $convB->active_flow_version_id);

        $finalMsgB = Message::where('conversation_id', $convB->id)->where('sender_type', 'bot')->latest('sent_at')->first();
        $this->assertNotNull($finalMsgB);
        $this->assertStringContainsString('Elegiste la comida Tacos al pastor', $finalMsgB->content);
        $this->assertStringNotContainsString('color', $finalMsgB->content);
    }
}
