<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Flow;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ExportFlowCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'conexa:export-flow
                            {identifier : Flow UUID or flow name}
                            {--output= : Path to save exported JSON}
                            {--with-channels : Include linked channels and webchat settings}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export a flow with all its versions, nodes, and edges to a portable JSON file';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $identifier = $this->argument('identifier');

        $flowQuery = Flow::with([
            'versions.nodes',
            'versions.edges',
            'publications',
        ]);

        if (Str::isUuid($identifier)) {
            $flow = $flowQuery->where('id', $identifier)->first();
        } else {
            $likeOperator = \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $flow = $flowQuery->where('name', $likeOperator, "%{$identifier}%")->first();
        }

        if (! $flow) {
            $this->error("Flujo no encontrado para el identificador: {$identifier}");

            return self::FAILURE;
        }

        $this->info("Exportando flujo: {$flow->name} ({$flow->id})...");

        $payload = [
            'schema_version' => 1,
            'exported_at' => now()->toIso8601String(),
            'flow' => [
                'id' => $flow->id,
                'name' => $flow->name,
                'description' => $flow->description,
                'status' => $flow->status->value,
                'channel_applicability' => $flow->channel_applicability,
                'active_version_id' => $flow->active_version_id,
            ],
            'versions' => $flow->versions->map(function ($version) {
                return [
                    'id' => $version->id,
                    'version_number' => $version->version_number,
                    'status' => $version->status->value,
                    'description' => $version->description,
                    'metadata' => $version->metadata,
                    'published_at' => $version->published_at?->toIso8601String(),
                    'nodes' => $version->nodes->map(function ($node) {
                        return [
                            'id' => $node->id,
                            'node_key' => $node->node_key,
                            'type' => $node->type,
                            'name' => $node->name,
                            'configuration' => $node->configuration,
                            'position_x' => $node->position_x,
                            'position_y' => $node->position_y,
                        ];
                    })->all(),
                    'edges' => $version->edges->map(function ($edge) {
                        return [
                            'id' => $edge->id,
                            'source_node_key' => $edge->source_node_key,
                            'target_node_key' => $edge->target_node_key,
                            'condition_handle' => $edge->condition_handle,
                            'metadata' => $edge->metadata,
                        ];
                    })->all(),
                ];
            })->all(),
            'publications' => $flow->publications->map(function ($pub) {
                return [
                    'id' => $pub->id,
                    'flow_version_id' => $pub->flow_version_id,
                    'action' => $pub->action,
                    'previous_version_id' => $pub->previous_version_id,
                    'notes' => $pub->notes,
                    'metadata' => $pub->metadata,
                    'created_at' => $pub->created_at?->toIso8601String(),
                ];
            })->all(),
            'channels' => [],
        ];

        if ($this->option('with-channels')) {
            $channels = Channel::with('webchatSettings')
                ->where('entry_flow_id', $flow->id)
                ->get();

            $payload['channels'] = $channels->map(function ($channel) {
                return [
                    'id' => $channel->id,
                    'name' => $channel->name,
                    'type' => is_object($channel->type) ? $channel->type->value : $channel->type,
                    'is_active' => $channel->is_active,
                    'configuration' => $channel->configuration,
                    'webchat_settings' => $channel->webchatSettings ? [
                        'id' => $channel->webchatSettings->id,
                        'public_key' => $channel->webchatSettings->public_key,
                        'allowed_origins' => $channel->webchatSettings->allowed_origins,
                        'display_name' => $channel->webchatSettings->display_name,
                        'accent_color' => $channel->webchatSettings->accent_color,
                        'launcher_position' => $channel->webchatSettings->launcher_position,
                    ] : null,
                ];
            })->all();
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $outputPath = $this->option('output');
        if ($outputPath) {
            $dir = dirname($outputPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($outputPath, $json);
            $this->info("Flujo exportado con éxito en: {$outputPath}");
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }
}
