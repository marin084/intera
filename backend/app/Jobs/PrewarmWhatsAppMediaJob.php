<?php

namespace App\Jobs;

use App\Enums\ChannelType;
use App\Exceptions\PermanentWhatsAppMediaPrewarmException;
use App\Exceptions\TransientWhatsAppMediaPrewarmException;
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

class PrewarmWhatsAppMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 90];

    public int $timeout = 60;

    /**
     * Create a new pre-warm job referencing only IDs.
     * Raw or signed URLs are strictly resolved at execution time to avoid persisting sensitive tokens in queues.
     */
    public function __construct(
        public string $channelId,
        public string $flowId,
        public string $flowVersionId,
        public string $nodeKey,
        public array $context = []
    ) {}

    public function handle(WhatsAppMediaPrewarmService $service): void
    {
        $channel = Channel::find($this->channelId);
        if (! $channel || ! $channel->is_active || $channel->type !== ChannelType::WHATSAPP) {
            Log::info("[PrewarmWhatsAppMediaJob] Canal [{$this->channelId}] no encontrado, inactivo o no es WhatsApp. Omitiendo.");

            return;
        }

        // Channel must have this flow assigned as entry_flow_id
        if ((string) $channel->entry_flow_id !== (string) $this->flowId) {
            Log::info("[PrewarmWhatsAppMediaJob] Canal [{$this->channelId}] ya no tiene enlazado el flujo [{$this->flowId}]. Omitiendo.");

            return;
        }

        $flow = Flow::find($this->flowId);
        if (! $flow) {
            Log::info("[PrewarmWhatsAppMediaJob] Flujo [{$this->flowId}] no encontrado. Omitiendo.");

            return;
        }

        // Check applicability: empty/null means all channels; otherwise must contain 'whatsapp'
        $applicability = $flow->channel_applicability;
        if (! empty($applicability) && is_array($applicability) && ! in_array('whatsapp', $applicability, true)) {
            Log::info("[PrewarmWhatsAppMediaJob] Flujo [{$this->flowId}] no tiene aplicabilidad para WhatsApp. Omitiendo.");

            return;
        }

        $version = FlowVersion::find($this->flowVersionId);
        if (! $version || (string) $version->flow_id !== (string) $this->flowId) {
            Log::info("[PrewarmWhatsAppMediaJob] Versión [{$this->flowVersionId}] no encontrada o no pertenece al flujo. Omitiendo.");

            return;
        }

        $node = $version->nodes()->where('node_key', $this->nodeKey)->first();
        if (! $node) {
            Log::info("[PrewarmWhatsAppMediaJob] Nodo [{$this->nodeKey}] no encontrado en versión [{$this->flowVersionId}]. Omitiendo.");

            return;
        }

        $type = is_object($node->type) ? $node->type->value : (string) $node->type;
        if (! in_array($type, ['image', 'file', 'document', 'audio'], true)) {
            return;
        }

        $config = $node->configuration ?? [];
        $rawUrl = $config['url'] ?? null;

        if (empty($rawUrl) || ! is_string($rawUrl)) {
            return;
        }

        // Exclude dynamic variables that depend on runtime conversation context
        if (str_contains($rawUrl, '{{') && str_contains($rawUrl, '}}')) {
            return;
        }

        $filename = $config['file_name'] ?? null;
        $mimeType = $config['mime_type'] ?? null;

        try {
            $service->uploadToMeta(
                $channel,
                trim($rawUrl),
                $filename,
                $mimeType,
                array_merge($this->context, [
                    'source' => 'PrewarmWhatsAppMediaJob',
                    'flow_id' => $this->flowId,
                    'flow_version_id' => $this->flowVersionId,
                    'node_key' => $this->nodeKey,
                ])
            );
        } catch (TransientWhatsAppMediaPrewarmException $e) {
            $safeError = WhatsAppChannelAdapter::sanitizeErrorMessage($e->getMessage());
            Log::warning("[PrewarmWhatsAppMediaJob] Error transitorio en pre-cargado para canal [{$this->channelId}]. Reintentando según backoff: {$safeError}");

            // Rethrow so Laravel Queue retries according to $tries and $backoff
            throw $e;
        } catch (PermanentWhatsAppMediaPrewarmException $e) {
            $safeError = WhatsAppChannelAdapter::sanitizeErrorMessage($e->getMessage());
            Log::error("[PrewarmWhatsAppMediaJob] Error permanente en pre-cargado para canal [{$this->channelId}]. Descartando sin reintentar: {$safeError}");

            // Permanent validation/4xx error: finish cleanly without retrying
            return;
        } catch (\Throwable $e) {
            $safeError = WhatsAppChannelAdapter::sanitizeErrorMessage($e->getMessage());
            Log::error("[PrewarmWhatsAppMediaJob] Error inesperado en pre-cargado para canal [{$this->channelId}]: {$safeError}");

            throw $e;
        }
    }
}
