<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FlowMigrationUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_incremental_migration_adds_positions_without_data_loss(): void
    {
        // 1. Confirm initial state after migrate has position columns
        $this->assertTrue(Schema::hasColumn('flow_nodes', 'position_x'));
        $this->assertTrue(Schema::hasColumn('flow_nodes', 'position_y'));

        // 2. Create existing Flow data (as if from Fase 2)
        $flow = Flow::create([
            'name' => 'Fase 2 Legacy Flow',
            'status' => 'draft',
            'channel_applicability' => ['webchat'],
        ]);

        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => 'draft',
        ]);

        $node = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_1',
            'type' => 'start',
            'name' => 'Inicio Legacy',
            'configuration' => ['text' => 'Legacy config preserved'],
            'position_x' => 120.5,
            'position_y' => 340.0,
        ]);

        // 3. Rollback the incremental migration (simulating pre-Fase 3 state)
        while (Schema::hasColumn('flow_nodes', 'position_x')) {
            Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
        }

        $this->assertFalse(Schema::hasColumn('flow_nodes', 'position_x'));
        $this->assertFalse(Schema::hasColumn('flow_nodes', 'position_y'));

        // Confirm node, version, and flow still exist with functional configuration
        $freshNode = FlowNode::find($node->id);
        $this->assertNotNull($freshNode);
        $this->assertEquals('start_1', $freshNode->node_key);
        $this->assertEquals('Legacy config preserved', $freshNode->configuration['text']);

        // 4. Run incremental migration forward (simulating Phase 2 -> Phase 3 upgrade)
        Artisan::call('migrate', ['--force' => true]);

        $this->assertTrue(Schema::hasColumn('flow_nodes', 'position_x'));
        $this->assertTrue(Schema::hasColumn('flow_nodes', 'position_y'));

        // Confirm legacy data preserved after upgrade
        $upgradedNode = FlowNode::find($node->id);
        $this->assertNotNull($upgradedNode);
        $this->assertEquals('start_1', $upgradedNode->node_key);
        $this->assertEquals('Legacy config preserved', $upgradedNode->configuration['text']);

        // 5. Update position on upgraded node
        $upgradedNode->update([
            'position_x' => 250.0,
            'position_y' => 500.0,
        ]);

        $this->assertEquals(250.0, $upgradedNode->fresh()->position_x);
        $this->assertEquals(500.0, $upgradedNode->fresh()->position_y);
    }
}
