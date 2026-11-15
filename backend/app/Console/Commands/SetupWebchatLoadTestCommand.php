<?php

namespace App\Console\Commands;

use App\Enums\ChannelType;
use App\Enums\FlowNodeType;
use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Models\Channel;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Models\WebchatChannelSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetupWebchatLoadTestCommand extends Command
{
    private const CHANNEL_NAME = 'Conexa Webchat Load Test';

    private const FLOW_NAME = 'Conexa Webchat Load Test Fixture';

    private const PUBLIC_KEY = 'wc_loadtest_only_not_for_production';

    private const ORIGIN = 'http://loadtest.local';

    protected $signature = 'loadtest:webchat:setup';

    protected $description = 'Create the isolated Webchat channel and reply loop used by the load-test harness';

    public function handle(): int
    {
        $connection = config('database.connections.'.config('database.default'));

        if (
            app()->environment() !== 'loadtest'
            || config('database.default') !== 'pgsql'
            || ($connection['host'] ?? null) !== 'postgres'
            || ($connection['database'] ?? null) !== 'conexa_loadtest'
        ) {
            $this->error('Safety check failed. This command only runs in APP_ENV=loadtest against postgres/conexa_loadtest.');

            return self::FAILURE;
        }

        DB::transaction(function (): void {
            $flow = Flow::updateOrCreate(
                ['name' => self::FLOW_NAME],
                [
                    'description' => 'Synthetic flow for isolated Webchat throughput tests.',
                    'status' => FlowStatus::PUBLISHED,
                    'channel_applicability' => [ChannelType::WEBCHAT->value],
                ]
            );

            $version = FlowVersion::updateOrCreate(
                ['flow_id' => $flow->id, 'version_number' => 1],
                [
                    'status' => FlowVersionStatus::PUBLISHED,
                    'description' => 'Load-test fixture version.',
                    'published_at' => now(),
                ]
            );

            $flow->update(['active_version_id' => $version->id]);

            FlowNode::updateOrCreate(
                ['flow_version_id' => $version->id, 'node_key' => 'load_start'],
                [
                    'type' => FlowNodeType::START,
                    'name' => 'Load test start',
                    'configuration' => [],
                ]
            );

            FlowNode::updateOrCreate(
                ['flow_version_id' => $version->id, 'node_key' => 'load_question'],
                [
                    'type' => FlowNodeType::QUESTION,
                    'name' => 'Load test reply loop',
                    'configuration' => [
                        'text' => 'Carga sintética de Webchat: responde para continuar.',
                        'variable' => 'loadtest_reply',
                        'input_type' => 'text',
                    ],
                ]
            );

            // These edges belong only to the reserved load-test flow version.
            FlowEdge::where('flow_version_id', $version->id)->delete();
            FlowEdge::create([
                'flow_version_id' => $version->id,
                'source_node_key' => 'load_start',
                'target_node_key' => 'load_question',
            ]);
            FlowEdge::create([
                'flow_version_id' => $version->id,
                'source_node_key' => 'load_question',
                'target_node_key' => 'load_question',
            ]);

            $channel = Channel::updateOrCreate(
                ['name' => self::CHANNEL_NAME],
                [
                    'type' => ChannelType::WEBCHAT,
                    'is_active' => true,
                    'entry_flow_id' => $flow->id,
                    'configuration' => [],
                ]
            );

            WebchatChannelSettings::updateOrCreate(
                ['channel_id' => $channel->id],
                [
                    'public_key' => self::PUBLIC_KEY,
                    'allowed_origins' => [self::ORIGIN],
                    'display_name' => 'Conexa Load Test',
                    'accent_color' => '#2563EB',
                    'launcher_position' => 'bottom-right',
                ]
            );
        });

        $this->info('Isolated Webchat load-test fixture is ready.');
        $this->line('Public key: '.self::PUBLIC_KEY);
        $this->line('Allowed origin: '.self::ORIGIN);

        return self::SUCCESS;
    }
}
