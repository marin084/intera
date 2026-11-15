<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Services\Media\WhatsAppMediaPrewarmService;
use App\Services\Runtime\Channels\Adapters\WhatsAppChannelAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PrewarmFlowMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public string $flowId,
        public ?string $versionId = null,
        public ?string $channelId = null
    ) {}

    public function handle(WhatsAppMediaPrewarmService $service): void
    {
        $flow = Flow::find($this->flowId);
        if (! $flow) {
            Log::info("[PrewarmFlowMediaJob] Flujo [{$this->flowId}] no encontrado.");

            return;
        }

        $applicability = $flow->channel_applicability;
        if (! empty($applicability) && is_array($applicability) && ! in_array('whatsapp', $applicability, true)) {
            Log::info("[PrewarmFlowMediaJob] Flujo [{$this->flowId}] no permite aplicabilidad WhatsApp. Omitiendo.");

            return;
        }

        $version = $this->versionId
            ? FlowVersion::find($this->versionId)
            : $flow->activeVersion;

        if (! $version) {
            Log::info("[PrewarmFlowMediaJob] Versión de flujo no disponible para prewarm [{$this->flowId}].");

            return;
        }

        $channel = $this->channelId ? Channel::find($this->channelId) : null;

        try {
            $stats = $service->prewarmVersion($version, $channel, false);
            Log::info("[PrewarmFlowMediaJob] Prewarm completado para flujo [{$flow->id}] versión [{$version->version_number}]", $stats);
        } catch (\Throwable $e) {
            $safeError = WhatsAppChannelAdapter::sanitizeErrorMessage($e->getMessage());
            Log::error("[PrewarmFlowMediaJob] Error en job de flujo [{$flow->id}]: {$safeError}");

            throw $e;
        }
    }
}
