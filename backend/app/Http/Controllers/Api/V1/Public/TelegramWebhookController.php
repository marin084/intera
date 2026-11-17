<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\ChannelType;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Services\Runtime\Channels\ChannelAdapterRegistry;
use App\Services\Runtime\ConversationIngressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __construct(
        protected ChannelAdapterRegistry $adapterRegistry,
        protected ConversationIngressService $ingressService
    ) {}

    /**
     * Webhook Event Ingestion Endpoint (Telegram POST request).
     */
    public function handle(Request $request, Channel $channel): JsonResponse
    {
        if ($channel->type !== ChannelType::TELEGRAM || ! $channel->is_active) {
            return response()->json(['message' => 'Canal inactivo o no soportado.'], 403);
        }

        // Validate Telegram Secret Token: strictly required for Telegram webhooks
        $expectedSecret = (string) ($channel->configuration['webhook_secret_token']
            ?? ($channel->configuration['verify_token'] ?? ''));

        if (empty($expectedSecret)) {
            Log::warning("[TelegramWebhookController] Webhook secret token no configurado para canal [{$channel->id}]. Rechazando request.");

            return response()->json(['message' => 'Configuración de seguridad incompleta en el canal.'], 403);
        }

        $receivedSecret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        if (empty($receivedSecret) || ! hash_equals($expectedSecret, $receivedSecret)) {
            Log::warning("[TelegramWebhookController] Secret token inválido o ausente en webhook para canal [{$channel->id}].");

            return response()->json(['message' => 'Secret token inválido o ausente.'], 403);
        }

        $payload = $request->all();
        $updateId = $payload['update_id'] ?? null;
        if (! $updateId) {
            return response()->json(['ok' => false, 'description' => 'Missing update_id'], 400);
        }

        // Persistent deduplication check: if already processed durably, return 200 ok
        $dedupeKey = "dedupe_webhook_tg_{$channel->id}_{$updateId}";
        if (Cache::has($dedupeKey)) {
            return response()->json(['ok' => true, 'description' => 'Duplicate update ignored'], 200);
        }

        // In-flight concurrency lock per update_id
        $lockKey = "lock_webhook_tg_update_{$channel->id}_{$updateId}";
        $lock = Cache::lock($lockKey, 15);
        if (! $lock->get()) {
            return response()->json(['ok' => false, 'description' => 'Update still processing'], 503);
        }

        try {
            $adapter = $this->adapterRegistry->forChannel($channel);
            $inbound = $adapter->normalizeInbound($channel, $payload);

            if ($inbound->content !== '' || ! empty($inbound->payload)) {
                $this->ingressService->handleInbound($inbound);
            }

            // Mark persistently as durably processed for 48 hours
            Cache::put($dedupeKey, true, now()->addHours(48));
        } catch (\Throwable $e) {
            Log::error("[TelegramWebhookController] Error procesando update de Telegram: {$e->getMessage()}", [
                'channel_id' => $channel->id,
                'update_id' => $updateId,
            ]);

            optional($lock)->release();

            // Return 500 so Telegram will retry the update instead of dropping it
            return response()->json(['ok' => false, 'error' => 'Internal server error processing update'], 500);
        } finally {
            optional($lock)->release();
        }

        return response()->json(['ok' => true], 200);
    }
}
