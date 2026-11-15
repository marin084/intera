<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FlowExportImportCommandTest extends TestCase
{
    use RefreshDatabase;

    protected string $testExportPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testExportPath = storage_path('framework/testing/test_flow_export.json');
        File::ensureDirectoryExists(dirname($this->testExportPath));
    }

    protected function tearDown(): void
    {
        if (File::exists($this->testExportPath)) {
            File::delete($this->testExportPath);
        }
        parent::tearDown();
    }

    public function test_export_and_import_flow(): void
    {
        $flow = Flow::create([
            'name' => 'Demo Flow',
            'status' => 'published',
            'channel_applicability' => ['webchat'],
        ]);

        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => 'published',
            'description' => 'Test version',
        ]);

        $node = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_1',
            'type' => 'start',
            'name' => 'Inicio',
            'position_x' => 100,
            'position_y' => 200,
        ]);

        $node2 = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'message_1',
            'type' => 'message',
            'name' => 'Saludo',
            'configuration' => ['text' => 'Hola mundo'],
            'position_x' => 100,
            'position_y' => 350,
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start_1',
            'target_node_key' => 'message_1',
        ]);

        $flow->update(['active_version_id' => $version->id]);

        $channel = Channel::create([
            'name' => 'Demo Channel',
            'type' => 'webchat',
            'is_active' => true,
            'entry_flow_id' => $flow->id,
        ]);

        // 1. Export command
        $this->artisan('conexa:export-flow', [
            'identifier' => 'Demo Flow',
            '--output' => $this->testExportPath,
            '--with-channels' => true,
        ])->assertSuccessful();

        $this->assertFileExists($this->testExportPath);

        // Delete flow from database to test fresh import
        $flow->delete();
        $this->assertDatabaseMissing('flows', ['name' => 'Demo Flow']);

        // 2. Import command
        $this->artisan('conexa:import-flow', [
            'file' => $this->testExportPath,
            '--with-channels' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('flows', [
            'name' => 'Demo Flow',
            'status' => 'published',
        ]);

        $importedFlow = Flow::where('name', 'Demo Flow')->first();
        $this->assertNotNull($importedFlow);
        $this->assertEquals(1, $importedFlow->versions()->count());

        $importedVersion = $importedFlow->versions()->first();
        $this->assertEquals(2, $importedVersion->nodes()->count());
        $this->assertEquals(1, $importedVersion->edges()->count());
        $this->assertEquals($importedVersion->id, $importedFlow->active_version_id);
    }
}
