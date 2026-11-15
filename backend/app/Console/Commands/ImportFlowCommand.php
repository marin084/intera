<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowPublication;
use App\Models\FlowVersion;
use App\Models\WebchatChannelSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportFlowCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'conexa:import-flow
                            {file : Path to the exported JSON file}
                            {--force : Overwrite existing flow if ID or name already exists}
                            {--with-channels : Also import channels included in the package}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import a portable JSON flow package into Intera';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $filePath = $this->argument('file');

        if (! file_exists($filePath)) {
            $this->error("El archivo no existe: {$filePath}");

            return self::FAILURE;
        }

        $raw = file_get_contents($filePath);
        $data = json_decode($raw, true);

        if (! is_array($data) || ! isset($data['flow']) || ! isset($data['versions'])) {
            $this->error('Formato JSON inválido. Se requieren las secciones "flow" y "versions".');

            return self::FAILURE;
        }

        $flowData = $data['flow'];
        $flowId = $flowData['id'];
        $flowName = $flowData['name'];

        $existing = Flow::where('id', $flowId)->orWhere('name', $flowName)->first();

        if ($existing) {
            if (! $this->option('force')) {
                $this->warn("El flujo '{$existing->name}' ({$existing->id}) ya existe en la base de datos. Use --force para sobrescribirlo.");

                return self::SUCCESS;
            }

            $this->info("Sobrescribiendo flujo existente: {$existing->name}...");
            $existing->delete();
        }

        $this->info("Importando flujo: {$flowName} ({$flowId})...");

        DB::beginTransaction();

        try {
            // 1. Crear el Flow base (con active_version_id null temporalmente)
            $flow = Flow::forceCreate([
                'id' => $flowId,
                'name' => $flowName,
                'description' => $flowData['description'] ?? null,
                'status' => $flowData['status'] ?? 'draft',
                'channel_applicability' => $flowData['channel_applicability'] ?? null,
                'active_version_id' => null,
            ]);

            // 2. Importar versiones, nodos y aristas
            foreach ($data['versions'] as $vData) {
                $version = FlowVersion::forceCreate([
                    'id' => $vData['id'],
                    'flow_id' => $flow->id,
                    'version_number' => $vData['version_number'],
                    'status' => $vData['status'] ?? 'draft',
                    'description' => $vData['description'] ?? null,
                    'metadata' => $vData['metadata'] ?? null,
                    'published_at' => $vData['published_at'] ?? null,
                ]);

                if (! empty($vData['nodes'])) {
                    foreach ($vData['nodes'] as $nData) {
                        FlowNode::forceCreate([
                            'id' => $nData['id'],
                            'flow_version_id' => $version->id,
                            'node_key' => $nData['node_key'],
                            'type' => $nData['type'],
                            'name' => $nData['name'],
                            'configuration' => $nData['configuration'] ?? [],
                            'position_x' => $nData['position_x'] ?? null,
                            'position_y' => $nData['position_y'] ?? null,
                        ]);
                    }
                }

                if (! empty($vData['edges'])) {
                    foreach ($vData['edges'] as $eData) {
                        FlowEdge::forceCreate([
                            'id' => $eData['id'],
                            'flow_version_id' => $version->id,
                            'source_node_key' => $eData['source_node_key'],
                            'target_node_key' => $eData['target_node_key'],
                            'condition_handle' => $eData['condition_handle'] ?? null,
                            'metadata' => $eData['metadata'] ?? null,
                        ]);
                    }
                }
            }

            // 3. Restaurar active_version_id en el Flow
            if (! empty($flowData['active_version_id'])) {
                $flow->update(['active_version_id' => $flowData['active_version_id']]);
            }

            // 4. Importar publicaciones históricas
            if (! empty($data['publications'])) {
                foreach ($data['publications'] as $pubData) {
                    FlowPublication::forceCreate([
                        'id' => $pubData['id'],
                        'flow_id' => $flow->id,
                        'flow_version_id' => $pubData['flow_version_id'],
                        'action' => $pubData['action'] ?? 'publish',
                        'previous_version_id' => $pubData['previous_version_id'] ?? null,
                        'notes' => $pubData['notes'] ?? null,
                        'metadata' => $pubData['metadata'] ?? null,
                        'created_at' => $pubData['created_at'] ?? now(),
                    ]);
                }
            }

            // 5. Importar canales asociados si se solicita
            if ($this->option('with-channels') && ! empty($data['channels'])) {
                foreach ($data['channels'] as $chData) {
                    $existingChannel = Channel::find($chData['id']);
                    if ($existingChannel) {
                        $existingChannel->update([
                            'name' => $chData['name'],
                            'type' => $chData['type'],
                            'is_active' => $chData['is_active'] ?? true,
                            'configuration' => $chData['configuration'] ?? [],
                            'entry_flow_id' => $flow->id,
                        ]);
                        $channel = $existingChannel;
                    } else {
                        $channel = Channel::forceCreate([
                            'id' => $chData['id'],
                            'name' => $chData['name'],
                            'type' => $chData['type'],
                            'is_active' => $chData['is_active'] ?? true,
                            'configuration' => $chData['configuration'] ?? [],
                            'entry_flow_id' => $flow->id,
                        ]);
                    }

                    if (! empty($chData['webchat_settings'])) {
                        $ws = $chData['webchat_settings'];
                        $origins = $ws['allowed_origins'] ?? [];

                        // Garantizar que dominios de producción estén en allowed_origins
                        $defaultOrigins = [
                            'https://conexa.withstack.xyz',
                            'http://conexa.withstack.xyz',
                            'https://withstack.xyz',
                            'http://withstack.xyz',
                        ];
                        foreach ($defaultOrigins as $prodOrigin) {
                            if (! in_array($prodOrigin, $origins, true)) {
                                $origins[] = $prodOrigin;
                            }
                        }

                        WebchatChannelSettings::updateOrCreate(
                            ['channel_id' => $channel->id],
                            [
                                'id' => $ws['id'] ?? null,
                                'public_key' => $ws['public_key'],
                                'allowed_origins' => $origins,
                                'display_name' => $ws['display_name'] ?? 'Asistente Virtual',
                                'accent_color' => $ws['accent_color'] ?? '#2563EB',
                                'launcher_position' => $ws['launcher_position'] ?? 'bottom-right',
                            ]
                        );
                    }
                }
            }

            DB::commit();
            $this->info("¡Flujo '{$flowName}' importado con éxito!");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("Error al importar el flujo: {$e->getMessage()}\n{$e->getTraceAsString()}");

            return self::FAILURE;
        }
    }
}
