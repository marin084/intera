<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\ChannelType;
use App\Enums\MessageType;
use App\Enums\MessageStatus;
use App\Events\ConversationUpdated;
use App\Events\MessageStatusUpdated;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppMediaAttachmentJob;
use App\Models\Channel;
use App\Models\Message;
use App\Services\Runtime\Channels\ChannelAdapterRegistry;
use App\Services\Runtime\Channels\Adapters\WhatsAppChannelAdapter;
use App\Services\Runtime\Channels\WhatsAppOutboxService;
use App\Services\Runtime\ConversationIngressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    protected WhatsAppOutboxService $outboxService;

    public function __construct(
        protected ChannelAdapterRegistry $adapterRegistry,
        protected ConversationIngressService $ingressService,
        ?WhatsAppOutboxService $outboxService = null
    ) {
        $this->outboxService = $outboxService ?? new WhatsAppOutboxService($this->adapterRegistry);
    }

    /**
     * Webhook Verification Endpoint (Meta GET request).
     */
    public function verify(Request $request, Channel $channel): Response
    {
        if ($channel->type !== ChannelType::WHATSAPP || ! $channel->is_active) {
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
     * Webhook Event Ingestion Endpoint (Meta POST request).
     */
    public function handle(Request $request, Channel $channel): JsonResponse
    {
        // 1. Verify channel is WhatsApp and active
        if ($channel->type !== ChannelType::WHATSAPP || ! $channel->is_active) {
            return response()->json(['message' => 'Canal inactivo o no soportado.'], 403);
        }

        // 2. Validate HMAC-SHA256 signature
        $appSecret = (string) ($channel->configuration['app_secret'] ?? '');
        if (empty($appSecret)) {
            Log::warning("[WhatsAppWebhookController] App secret no configurado para canal [{$channel->id}].");

            return response()->json(['message' => 'App secret no configurado.'], 403);
        }

        $hubSignature = (string) $request->header('X-Hub-Signature-256', '');
        if (! str_starts_with($hubSignature, 'sha256=')) {
            return response()->json(['message' => 'Firma ausente o con formato inválido.'], 403);
        }

        $receivedHash = substr($hubSignature, 7);
        $expectedHash = hash_hmac('sha256', $request->getContent(), $appSecret);

        if (! hash_equals($expectedHash, $receivedHash)) {
            Log::warning("[WhatsAppWebhookController] Firma HMAC inválida en webhook para canal [{$channel->id}].");

            return response()->json(['message' => 'Firma HMAC inválida.'], 403);
        }

        $payload = $request->all();

        // 3. Process entries from Meta
        $entries = $payload['entry'] ?? [];
        if (! is_array($entries)) {
            return response()->json(['status' => 'ignored'], 200);
        }

        $adapter = $this->adapterRegistry->forChannel($channel);
        $processingFailed = false;

        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];
            if (! is_array($changes)) {
                continue;
            }

            foreach ($changes as $change) {
                $value = $change['value'] ?? [];
                if (! is_array($value)) {
                    continue;
                }

                // Process status updates (sent, delivered, read, failed)
                $statuses = $value['statuses'] ?? [];
                if (is_array($statuses) && ! empty($statuses)) {
                    foreach ($statuses as $statusUpdate) {
                        try {
                            $this->handleStatusUpdate($statusUpdate, $channel);
                        } catch (\Throwable $e) {
                            $processingFailed = true;
                            $safeError = WhatsAppChannelAdapter::sanitizeErrorMessage($e->getMessage());
                            Log::error("[WhatsAppWebhookController] Error procesando status de WhatsApp: {$safeError}", [
                                'status_id' => $statusUpdate['id'] ?? null,
                                'status' => $statusUpdate['status'] ?? null,
                                'channel_id' => $channel->id,
                            ]);
                        }
                    }
                }

                $messages = $value['messages'] ?? [];
                if (! is_array($messages) || empty($messages)) {
                    continue;
                }

                $contacts = $value['contacts'] ?? [];
                $contactProfile = $contacts[0]['profile']['name'] ?? null;
                $contactWaId = $contacts[0]['wa_id'] ?? null;

                foreach ($messages as $msg) {
                    $processingStartedAt = hrtime(true);
                    try {
                        $inboundDto = $adapter->normalizeInbound($channel, [
                            'message' => $msg,
                            'contact_profile' => $contactProfile,
                            'contact_wa_id' => $contactWaId,
                            'value' => $value,
                        ]);

                        $result = $this->ingressService->handleInbound($inboundDto);

                        // Asynchronously process media downloads in background without blocking Meta webhook response
                        $inboundMsg = $result['message'] ?? null;
                        $conversation = $result['conversation'] ?? null;
                        $execution = $result['execution'] ?? null;
                        $executionStatus = $execution?->status;
                        if ($executionStatus instanceof \BackedEnum) {
                            $executionStatus = $executionStatus->value;
                        }
                        $messageType = $inboundDto->messageType instanceof MessageType
                            ? $inboundDto->messageType->value
                            : (string) $inboundDto->messageType;

                        Log::info('whatsapp.inbound.processed', [
                            'channel_id' => $channel->id,
                            'conversation_id' => $conversation?->id,
                            'message_id' => $inboundMsg?->id,
                            'external_message_id_hash' => $this->hashExternalMessageId($inboundDto->externalMessageId),
                            'message_type' => $messageType,
                            'is_duplicate' => (bool) ($result['is_duplicate'] ?? false),
                            'flow_execution_id' => $execution?->id,
                            'flow_execution_status' => $executionStatus,
                            'correlation_id' => $inboundDto->correlationId,
                            'processing_ms' => (int) round((hrtime(true) - $processingStartedAt) / 1_000_000),
                        ]);

                        if ($inboundMsg) {
                            $att = $inboundMsg->payload['attachment'] ?? $inboundMsg->metadata['attachment'] ?? null;
                            if (is_array($att) && ! empty($att['media_id']) && ($att['status'] ?? '') === 'pending_download') {
                                ProcessWhatsAppMediaAttachmentJob::dispatch($inboundMsg->id, $channel->id);
                            }
                        }

                        if (! empty($result['conversation'])) {
                            broadcast(new ConversationUpdated($result['conversation']));
                        }
                    } catch (\Throwable $e) {
                        $processingFailed = true;
                        $safeError = WhatsAppChannelAdapter::sanitizeErrorMessage($e->getMessage());
                        Log::error("[WhatsAppWebhookController] Error procesando mensaje de WhatsApp: {$safeError}", [
                            'external_message_id_hash' => $this->hashExternalMessageId($msg['id'] ?? null),
                            'channel_id' => $channel->id,
                            'processing_ms' => (int) round((hrtime(true) - $processingStartedAt) / 1_000_000),
                        ]);
                    }
                }
            }
        }

        if ($processingFailed) {
            // Acknowledge only a fully processed batch. Successfully persisted messages
            // are idempotent when Meta retries the same webhook after this response.
            return response()->json(['status' => 'retry'], 500);
        }

        return response()->json(['status' => 'success'], 200);
    }

    private function hashExternalMessageId(mixed $messageId): ?string
    {
        if (! is_string($messageId) || $messageId === '') {
            return null;
        }

        return substr(hash('sha256', $messageId), 0, 16);
    }

    /**
     * Handles WhatsApp message delivery and read status updates.
     */
    public function handleStatusUpdate(array $statusData, ?Channel $channel = null): void
    {
        $wamid = $statusData['id'] ?? null;
        $statusStr = $statusData['status'] ?? null;

        if (! $wamid || ! $statusStr) {
            return;
        }

        // Locate message by external_message_id or metadata->meta_message_id / metadata->wamid
        $query = Message::query();
        if ($channel) {
            $query->whereHas('conversation', fn ($q) => $q->where('channel_id', $channel->id));
        }

        $message = (clone $query)->where('external_message_id', $wamid)->first();
        if (! $message) {
            $message = (clone $query)
                ->where(function ($q) use ($wamid) {
                    $q->where('metadata->meta_message_id', $wamid)
                        ->orWhere('metadata->wamid', $wamid);
                })
                ->first();
        }

        if (! $message) {
            $message = Message::where('external_message_id', $wamid)
                ->orWhere('metadata->meta_message_id', $wamid)
                ->orWhere('metadata->wamid', $wamid)
                ->first();
        }

        if (! $message) {
            Log::info('whatsapp.delivery_status.unmatched', [
                'channel_id' => $channel?->id,
                'external_message_id_hash' => $this->hashExternalMessageId($wamid),
                'delivery_status' => $statusStr,
            ]);

            return;
        }

        $hierarchy = [
            'pending' => 1,
            'sent' => 2,
            'delivered' => 3,
            'read' => 4,
        ];

        $meta = $message->metadata ?? [];
        $currentDeliveryStatus = $meta['delivery_status'] ?? null;
        $currentStatusStr = $currentDeliveryStatus ?: (is_object($message->status) ? $message->status->value : (string) ($message->status ?? 'sent'));
        $currentWeight = $hierarchy[$currentStatusStr] ?? 0;

        if (! empty($statusData['timestamp'])) {
            $meta["{$statusStr}_at"] = date('c', (int) $statusData['timestamp']);
        }

        if ($statusStr === 'failed') {
            $errors = is_array($statusData['errors'] ?? null) ? $statusData['errors'] : [];
            $firstErr = is_array($errors[0] ?? null) ? $errors[0] : [];
            $errorData = is_array($firstErr['error_data'] ?? null) ? $firstErr['error_data'] : [];

            $rawDetails = is_string($errorData['details'] ?? null) && trim($errorData['details']) !== ''
                ? trim($errorData['details'])
                : null;
            $rawGenericMessage = is_string($firstErr['message'] ?? null) && trim($firstErr['message']) !== ''
                ? trim($firstErr['message'])
                : null;

            // Prioritize specific error_data.details over the generic title/message text
            $resolvedMessage = $rawDetails ?: ($rawGenericMessage ?: 'Mensaje no pudo ser entregado por WhatsApp');
            $sanitizedMessage = WhatsAppChannelAdapter::sanitizeErrorMessage($resolvedMessage);

            $errorCode = $firstErr['code'] ?? 'DELIVERY_FAILED';
            $errorTitle = is_string($firstErr['title'] ?? null) && trim($firstErr['title']) !== ''
                ? WhatsAppChannelAdapter::sanitizeErrorMessage(trim($firstErr['title']))
                : 'Error de entrega';

            $fbtraceId = $firstErr['fbtrace_id']
                ?? ($errorData['fbtrace_id'] ?? ($statusData['fbtrace_id'] ?? null));

            $deliveryError = [
                'code' => $errorCode,
                'title' => $errorTitle,
                'message' => $sanitizedMessage,
            ];

            if ($rawDetails !== null) {
                $deliveryError['details'] = WhatsAppChannelAdapter::sanitizeErrorMessage($rawDetails);
            }
            if ($rawGenericMessage !== null && $rawGenericMessage !== $resolvedMessage) {
                $deliveryError['generic_message'] = WhatsAppChannelAdapter::sanitizeErrorMessage($rawGenericMessage);
            }
            if (! empty($fbtraceId) && is_string($fbtraceId)) {
                $cleanFbtraceId = trim($fbtraceId);
                $deliveryError['fbtrace_id'] = $cleanFbtraceId;
                $meta['fbtrace_id'] = $cleanFbtraceId;
            }
            if (! empty($errorData)) {
                $deliveryError['error_data'] = $errorData;
            }

            $meta['delivery_status'] = 'failed';
            $meta['delivery_error'] = $deliveryError;
            $meta['delivery_error_code'] = (string) $errorCode;
            $meta['delivery_error_title'] = $errorTitle;

            Log::warning('whatsapp.delivery.failed', [
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'channel_id' => $channel?->id,
                'external_message_id_hash' => $this->hashExternalMessageId($wamid),
                'error_code' => (string) $errorCode,
                'error_title' => $errorTitle,
                'error_message' => $sanitizedMessage,
                'fbtrace_id' => is_string($fbtraceId) ? trim($fbtraceId) : null,
            ]);

            $updates = [
                'status' => MessageStatus::FAILED,
                'metadata' => $meta,
            ];
            if (empty($message->external_message_id)) {
                $alreadyUsed = Message::where('conversation_id', $message->conversation_id)
                    ->where('external_message_id', $wamid)
                    ->where('id', '!=', $message->id)
                    ->exists();

                if (! $alreadyUsed) {
                    $updates['external_message_id'] = $wamid;
                }
            }
            $message->update($updates);

            broadcast(new MessageStatusUpdated($message));

            $this->outboxService->processNextOutbound($message->conversation_id);

            return;
        }

        $newWeight = $hierarchy[$statusStr] ?? 0;

        // Never downgrade (e.g. read -> delivered or delivered -> sent)
        if ($currentStatusStr === 'failed' && $newWeight < 3) {
            return;
        }

        if ($newWeight <= $currentWeight) {
            $message->update(['metadata' => $meta]);

            return;
        }

        $meta['delivery_status'] = $statusStr;

        $enumStatus = MessageStatus::tryFrom($statusStr);
        if (! $enumStatus) {
            return;
        }

        $updates = [
            'status' => $enumStatus,
            'metadata' => $meta,
        ];
        if (empty($message->external_message_id)) {
            $alreadyUsed = Message::where('conversation_id', $message->conversation_id)
                ->where('external_message_id', $wamid)
                ->where('id', '!=', $message->id)
                ->exists();

            if (! $alreadyUsed) {
                $updates['external_message_id'] = $wamid;
            }
        }
        $message->update($updates);

        broadcast(new MessageStatusUpdated($message));

        if ($statusStr === 'delivered' || $statusStr === 'read') {
            $this->outboxService->processNextOutbound($message->conversation_id);
        }
    }
}
