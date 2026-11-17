<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\ChannelType;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Services\Runtime\Channels\ChannelAdapterRegistry;
use App\Services\Runtime\Channels\MetaMessageReactionProcessor;
use App\Services\Runtime\ConversationIngressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class InstagramWebhookController extends Controller
{
    public function __construct(
        protected ChannelAdapterRegistry $adapterRegistry,
        protected ConversationIngressService $ingressService,
        protected MetaMessageReactionProcessor $reactionProcessor
    ) {}

    /**
     * Webhook Verification Endpoint (Meta GET request for Instagram).
     */
    public function verify(Request $request, Channel $channel): Response
    {
        if ($channel->type !== ChannelType::INSTAGRAM || ! $channel->is_active) {
            return response('Forbidden', 403);
        }

        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $verifyToken = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

        $expectedToken = (string) ($channel->configuration['verify_token'] ?? '');

        if ($mode === 'subscribe' && ! empty($expectedToken) && hash_equals($expectedToken, (string) $verifyToken)) {
            return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * Webhook Event Ingestion Endpoint (Meta POST request for Instagram Direct).
     */
    public function handle(Request $request, Channel $channel): JsonResponse
    {
        if ($channel->type !== ChannelType::INSTAGRAM || ! $channel->is_active) {
            return response()->json(['message' => 'Canal inactivo o no soportado.'], 403);
        }

        // Validate HMAC-SHA256 signature
        $appSecret = (string) ($channel->configuration['app_secret'] ?? config('services.meta.app_secret', ''));
        if (empty($appSecret)) {
            Log::warning("[InstagramWebhookController] App secret no configurado para canal [{$channel->id}].");

            return response()->json(['message' => 'App secret no configurado.'], 403);
        }

        $hubSignature = (string) $request->header('X-Hub-Signature-256', '');
        if (! str_starts_with($hubSignature, 'sha256=')) {
            return response()->json(['message' => 'Firma ausente o con formato inválido.'], 403);
        }

        $receivedHash = substr($hubSignature, 7);
        $expectedHash = hash_hmac('sha256', $request->getContent(), $appSecret);

        if (! hash_equals($expectedHash, $receivedHash)) {
            Log::warning("[InstagramWebhookController] Firma HMAC inválida en webhook para canal [{$channel->id}].");

            return response()->json(['message' => 'Firma HMAC inválida.'], 403);
        }

        $payload = $request->all();

        // Safety gate: verify payload object is 'instagram' or 'page'
        $object = $payload['object'] ?? '';
        if ($object !== 'instagram' && $object !== 'page') {
            return response()->json(['status' => 'ignored'], 200);
        }

        $entries = $payload['entry'] ?? [];
        if (! is_array($entries)) {
            return response()->json(['status' => 'ignored'], 200);
        }

        $adapter = $this->adapterRegistry->forChannel($channel);

        foreach ($entries as $entry) {
            // Strictly ignore comments, likes, media feed changes, mentions, stories
            if (isset($entry['changes'])) {
                continue;
            }

            $messagingItems = $entry['messaging'] ?? [];
            if (! is_array($messagingItems)) {
                continue;
            }

            foreach ($messagingItems as $messaging) {
                // Ignore page / bot echoes
                if (! empty($messaging['message']['is_echo'])) {
                    continue;
                }

                // Reactions update an existing message; they must not start or advance a flow.
                if (isset($messaging['reaction']) || isset($messaging['message_reaction'])) {
                    try {
                        $this->reactionProcessor->process($channel, $messaging);
                    } catch (\Throwable $e) {
                        Log::error("[InstagramWebhookController] Error procesando reacción: {$e->getMessage()}", [
                            'channel_id' => $channel->id,
                        ]);

                        return response()->json(['message' => 'Error interno procesando reacción.'], 500);
                    }

                    continue;
                }

                $messageId = $messaging['message']['mid']
                    ?? ($messaging['postback']['mid']
                    ?? ('ig_'.($messaging['timestamp'] ?? time()).'_'.($messaging['sender']['id'] ?? 'user')));

                // Persistent deduplication check: if already processed durably, ignore duplicate
                $dedupeKey = "dedupe_webhook_ig_{$channel->id}_{$messageId}";
                if (Cache::has($dedupeKey)) {
                    continue;
                }

                // In-flight concurrency lock per external message id
                $lockKey = "lock_webhook_ig_{$channel->id}_{$messageId}";
                $lock = Cache::lock($lockKey, 15);
                if (! $lock->get()) {
                    return response()->json(['message' => 'Evento todavía en proceso.'], 503);
                }

                try {
                    $inbound = $adapter->normalizeInbound($channel, ['messaging' => [$messaging]]);
                    if ($inbound->content !== '' || ! empty($inbound->payload)) {
                        $this->ingressService->handleInbound($inbound);
                    }

                    // Mark persistently as durably processed for 48 hours
                    Cache::put($dedupeKey, true, now()->addHours(48));
                } catch (\Throwable $e) {
                    Log::error("[InstagramWebhookController] Error procesando mensaje entrante: {$e->getMessage()}", [
                        'channel_id' => $channel->id,
                        'message_id' => $messageId,
                    ]);

                    optional($lock)->release();

                    // Return 500 so Meta will retry the event instead of losing the message
                    return response()->json(['message' => 'Error interno procesando evento de webhook.'], 500);
                } finally {
                    optional($lock)->release();
                }
            }
        }

        return response()->json(['status' => 'ok'], 200);
    }
}
