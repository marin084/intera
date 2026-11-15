<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\FlowNodeType;
use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Services\Runtime\ConversationIngressService;
use App\Services\Runtime\FlowResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FlowChannelApplicabilityResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected FlowResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(FlowResolver::class);
    }

    /**
     * Helper to build a minimal runnable graph.
     */
    protected function createMinimalGraph(FlowVersion $version): void
    {
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
            'position_x' => 100,
            'position_y' => 100,
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_1',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Bienvenida',
            'configuration' => ['text' => 'Hola desde Enlace Test'],
            'position_x' => 100,
            'position_y' => 200,
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'end_1',
            'type' => FlowNodeType::END,
            'name' => 'Fin',
            'configuration' => [],
            'position_x' => 100,
            'position_y' => 300,
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start_1',
            'target_node_key' => 'msg_1',
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'msg_1',
            'target_node_key' => 'end_1',
        ]);
    }

    public function test_enlace_test_resolves_for_both_webchat_and_whatsapp_channels(): void
    {
        // 1. Create published "Enlace Test" flow with applicability ['webchat', 'whatsapp']
        $flow = Flow::create([
            'id' => 'a327cdfe-606d-4d40-a921-63f10315e8f7',
            'name' => 'Enlace Test',
            'status' => FlowStatus::PUBLISHED,
            'channel_applicability' => ['webchat', 'whatsapp'],
        ]);

        $v8 = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 8,
            'status' => FlowVersionStatus::PUBLISHED,
            'published_at' => now(),
        ]);
        $this->createMinimalGraph($v8);

        $flow->update(['active_version_id' => $v8->id]);

        // 2. Webchat channel linked to Enlace Test
        $webchatChannel = Channel::create([
            'name' => 'Webchat Enlace',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
            'entry_flow_id' => $flow->id,
        ]);

        // 3. WhatsApp channel linked to Enlace Test (e.g. WABA Test)
        $whatsappChannel = Channel::create([
            'name' => 'WABA Test',
            'type' => ChannelType::WHATSAPP,
            'is_active' => true,
            'entry_flow_id' => $flow->id,
            'configuration' => [
                'phone_number_id' => '10001234567890',
                'business_account_id' => '20001234567890',
            ],
        ]);

        // Verify resolution on Webchat
        $resolvedForWebchat = $this->resolver->resolveEntryFlowVersion($webchatChannel);
        $this->assertNotNull($resolvedForWebchat, 'Enlace Test debe resolver para canales Webchat.');
        $this->assertEquals($v8->id, $resolvedForWebchat->id);
        $this->assertEquals(8, $resolvedForWebchat->version_number);

        // Verify resolution on WhatsApp
        $resolvedForWhatsApp = $this->resolver->resolveEntryFlowVersion($whatsappChannel);
        $this->assertNotNull($resolvedForWhatsApp, 'Enlace Test debe resolver para canales WhatsApp.');
        $this->assertEquals($v8->id, $resolvedForWhatsApp->id);
        $this->assertEquals(8, $resolvedForWhatsApp->version_number);
    }

    public function test_restricted_flow_only_webchat_does_not_resolve_for_whatsapp_channel(): void
    {
        $flow = Flow::create([
            'name' => 'Flujo Solo Webchat',
            'status' => FlowStatus::PUBLISHED,
            'channel_applicability' => ['webchat'],
        ]);

        $v1 = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::PUBLISHED,
            'published_at' => now(),
        ]);
        $this->createMinimalGraph($v1);
        $flow->update(['active_version_id' => $v1->id]);

        $whatsappChannel = Channel::create([
            'name' => 'WABA Canal Restringido',
            'type' => ChannelType::WHATSAPP,
            'is_active' => true,
            'entry_flow_id' => $flow->id,
        ]);

        $webchatChannel = Channel::create([
            'name' => 'Webchat Canal Permitido',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
            'entry_flow_id' => $flow->id,
        ]);

        $this->assertNull(
            $this->resolver->resolveEntryFlowVersion($whatsappChannel),
            'Un flujo restringido a webchat NO debe resolver para un canal WhatsApp.'
        );

        $this->assertNotNull(
            $this->resolver->resolveEntryFlowVersion($webchatChannel),
            'Un flujo restringido a webchat debe resolver para un canal Webchat.'
        );
    }

    public function test_restricted_flow_only_whatsapp_does_not_resolve_for_webchat_channel(): void
    {
        $flow = Flow::create([
            'name' => 'Flujo Solo WhatsApp',
            'status' => FlowStatus::PUBLISHED,
            'channel_applicability' => ['whatsapp'],
        ]);

        $v1 = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::PUBLISHED,
            'published_at' => now(),
        ]);
        $this->createMinimalGraph($v1);
        $flow->update(['active_version_id' => $v1->id]);

        $webchatChannel = Channel::create([
            'name' => 'Webchat Invalido',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
            'entry_flow_id' => $flow->id,
        ]);

        $whatsappChannel = Channel::create([
            'name' => 'WABA Valido',
            'type' => ChannelType::WHATSAPP,
            'is_active' => true,
            'entry_flow_id' => $flow->id,
        ]);

        $this->assertNull(
            $this->resolver->resolveEntryFlowVersion($webchatChannel),
            'Un flujo restringido a WhatsApp NO debe resolver para un canal Webchat.'
        );

        $this->assertNotNull(
            $this->resolver->resolveEntryFlowVersion($whatsappChannel),
            'Un flujo restringido a WhatsApp debe resolver para un canal WhatsApp.'
        );
    }

    public function test_inactive_channel_does_not_resolve_flow(): void
    {
        $flow = Flow::create([
            'name' => 'Enlace Test Inactivo',
            'status' => FlowStatus::PUBLISHED,
            'channel_applicability' => ['webchat', 'whatsapp'],
        ]);

        $v1 = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::PUBLISHED,
            'published_at' => now(),
        ]);
        $flow->update(['active_version_id' => $v1->id]);

        $inactiveChannel = Channel::create([
            'name' => 'WABA Desactivado',
            'type' => ChannelType::WHATSAPP,
            'is_active' => false,
            'entry_flow_id' => $flow->id,
        ]);

        $this->assertNull($this->resolver->resolveEntryFlowVersion($inactiveChannel));
    }

    public function test_draft_or_unversioned_flow_does_not_resolve(): void
    {
        $flow = Flow::create([
            'name' => 'Flujo Borrador',
            'status' => FlowStatus::DRAFT,
            'channel_applicability' => ['webchat', 'whatsapp'],
        ]);

        $channel = Channel::create([
            'name' => 'WABA Canal',
            'type' => ChannelType::WHATSAPP,
            'is_active' => true,
            'entry_flow_id' => $flow->id,
        ]);

        $this->assertNull($this->resolver->resolveEntryFlowVersion($channel));
    }

    public function test_migration_updates_enlace_test_channel_applicability_idempotently(): void
    {
        // 1. Create Enlace Test flow with initial ['webchat']
        $enlaceFlow = Flow::create([
            'id' => 'a327cdfe-606d-4d40-a921-63f10315e8f7',
            'name' => 'Enlace Test',
            'status' => FlowStatus::PUBLISHED,
            'channel_applicability' => ['webchat'],
        ]);

        // 2. Create another flow that MUST NOT be affected
        $otherFlow = Flow::create([
            'name' => 'Otro Flujo Protegido',
            'status' => FlowStatus::PUBLISHED,
            'channel_applicability' => ['webchat'],
        ]);

        $migration = require database_path('migrations/2026_11_08_230000_update_enlace_test_flow_channel_applicability.php');

        // Execute UP first time
        $migration->up();

        $enlaceFlow->refresh();
        $otherFlow->refresh();

        $this->assertContains('webchat', $enlaceFlow->channel_applicability);
        $this->assertContains('whatsapp', $enlaceFlow->channel_applicability);
        $this->assertCount(2, $enlaceFlow->channel_applicability);

        // Verify other flow remains completely untouched
        $this->assertEquals(['webchat'], $otherFlow->channel_applicability);

        // Execute UP second time (idempotency test)
        $migration->up();

        $enlaceFlow->refresh();
        $this->assertCount(2, $enlaceFlow->channel_applicability, 'La migración debe ser idempotente y no duplicar entradas.');
        $this->assertContains('webchat', $enlaceFlow->channel_applicability);
        $this->assertContains('whatsapp', $enlaceFlow->channel_applicability);

        // Execute DOWN (rollback test)
        $migration->down();

        $enlaceFlow->refresh();
        $otherFlow->refresh();

        $this->assertEquals(['webchat'], $enlaceFlow->channel_applicability, 'Rollback debe restaurar channel_applicability a ["webchat"].');
        $this->assertEquals(['webchat'], $otherFlow->channel_applicability, 'Rollback no debe modificar otros flujos.');
    }

    public function test_conversation_ingress_triggers_flow_for_whatsapp_channel_with_enlace_test(): void
    {
        $flow = Flow::create([
            'id' => 'a327cdfe-606d-4d40-a921-63f10315e8f7',
            'name' => 'Enlace Test',
            'status' => FlowStatus::PUBLISHED,
            'channel_applicability' => ['webchat', 'whatsapp'],
        ]);

        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 8,
            'status' => FlowVersionStatus::PUBLISHED,
            'published_at' => now(),
        ]);
        $this->createMinimalGraph($version);
        $flow->update(['active_version_id' => $version->id]);

        $whatsappChannel = Channel::create([
            'name' => 'WABA Test',
            'type' => ChannelType::WHATSAPP,
            'is_active' => true,
            'entry_flow_id' => $flow->id,
            'configuration' => [
                'phone_number_id' => '10987654321',
                'business_account_id' => '9876543210',
            ],
        ]);

        $ingressService = app(ConversationIngressService::class);

        $result = $ingressService->handleInboundMessage(
            channel: $whatsappChannel,
            contactPayload: [
                'external_identifier' => '50212345678',
                'display_name' => 'Hermano Juan',
                'phone' => '+50212345678',
            ],
            content: 'Hola, bendiciones',
            externalMessageId: 'wamid.HBgLNT...' . uniqid(),
            messageType: 'text'
        );

        $this->assertNotNull($result['execution'], 'La ejecución del flujo debe haberse iniciado para el canal WhatsApp.');
        $this->assertEquals($version->id, $result['execution']->flow_version_id);
        $this->assertEquals($flow->id, $result['conversation']->activeFlowVersion?->flow_id);

        // Also verify that a WhatsApp channel linked to a webchat-only flow does NOT start execution
        $webchatOnlyFlow = Flow::create([
            'name' => 'Webchat Only Flow',
            'status' => FlowStatus::PUBLISHED,
            'channel_applicability' => ['webchat'],
        ]);
        $webchatVersion = FlowVersion::create([
            'flow_id' => $webchatOnlyFlow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::PUBLISHED,
            'published_at' => now(),
        ]);
        $this->createMinimalGraph($webchatVersion);
        $webchatOnlyFlow->update(['active_version_id' => $webchatVersion->id]);

        $restrictedWhatsAppChannel = Channel::create([
            'name' => 'WABA Canal Restringido Ingress',
            'type' => ChannelType::WHATSAPP,
            'is_active' => true,
            'entry_flow_id' => $webchatOnlyFlow->id,
        ]);

        $restrictedResult = $ingressService->handleInboundMessage(
            channel: $restrictedWhatsAppChannel,
            contactPayload: [
                'external_identifier' => '50298765432',
                'display_name' => 'Contacto Bloqueado',
                'phone' => '+50298765432',
            ],
            content: 'Hola quiero información',
            externalMessageId: 'wamid.HBgLNT...' . uniqid(),
            messageType: 'text'
        );

        $this->assertNull(
            $restrictedResult['execution'],
            'Un canal WhatsApp con un flujo de aplicabilidad solo webchat NO debe iniciar ejecución.'
        );
    }
}
