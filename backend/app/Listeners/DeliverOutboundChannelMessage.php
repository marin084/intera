<?php

namespace App\Listeners;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Events\MessageCreated;
use App\Events\MessageStatusUpdated;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Runtime\Channels\Adapters\WhatsAppChannelAdapter;
use App\Services\Runtime\Channels\ChannelAdapterRegistry;
use App\Services\Runtime\Channels\WhatsAppOutboxService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DeliverOutboundChannelMessage
{
    protected WhatsAppOutboxService $outboxService;

    public function __construct(
        protected ChannelAdapterRegistry $registry,
        ?WhatsAppOutboxService $outboxService = null
    ) {
        $this->outboxService = $outboxService ?? new WhatsAppOutboxService($this->registry);
    }

    /**
     * Handle the MessageCreated event.
     */
    public function handle(MessageCreated $event): void
    {
        $message = $event->message;

        // Session dividers are inbox-only system markers and must never reach an external channel.
        if (
            data_get($message->metadata, 'event') === 'session_divider'
            || $message->visibility === 'internal'
            || $message->direction !== MessageDirection::OUTBOUND
        ) {
            return;
        }

        // Only provider identifiers or delivery metadata prove that an external
        // provider accepted this message. The local "sent" status can be the model
        // default on newly-created flow messages and must not suppress dispatch.
        $fresh = $message->fresh() ?? $message;
        if (! empty($fresh->metadata['meta_message_id'])
            || ! empty($fresh->metadata['telegram_message_id'])
            || ! empty($fresh->metadata['wamid'])
            || ! empty($fresh->external_message_id)
            || ($fresh->metadata['delivery_status'] ?? '') === 'sent') {
            return;
        }

        $lock = Cache::lock("deliver_channel_message_{$message->id}", 15);
        if (! $lock->get()) {
            return;
        }

        $conversation = $message->conversation ?? Conversation::find($message->conversation_id);
        if (! $conversation) {
            optional($lock)->release();

            return;
        }

        $channel = $conversation->channel ?? Channel::find($conversation->channel_id);
        if (! $channel) {
            optional($lock)->release();

            return;
        }

        // WhatsApp channel: route through ordered outbox queue per conversation
        if ($channel->type === ChannelType::WHATSAPP) {
            try {
                $this->outboxService->enqueueAndProcess($fresh, $channel);
            } finally {
                optional($lock)->release();
            }

            return;
        }

        // Webchat channel: delivered via WebSocket (Reverb) on MessageCreated, no external API dispatch
        if ($channel->type === ChannelType::WEBCHAT) {
            optional($lock)->release();

            return;
        }

        // Meta Channels (Facebook Messenger & Instagram Direct): enforce strict 24-hour messaging window protection
        if ($channel->type === ChannelType::FACEBOOK_MESSENGER || $channel->type === ChannelType::INSTAGRAM) {
            if (! $conversation->is_meta_window_open) {
                $channelName = $channel->type === ChannelType::FACEBOOK_MESSENGER ? 'Facebook Messenger' : 'Instagram Direct';
                $windowError = "La ventana de atención de 24 horas de Meta ({$channelName}) ha expirado. Meta no permite el envío de respuestas de bot o agente fuera de la ventana.";

                Log::warning("[DeliverOutboundChannelMessage] Bloqueado envío fuera de ventana de 24h en [{$channel->type->value}] para mensaje [{$message->id}] en conversación [{$conversation->id}].");

                $meta = $message->fresh()?->metadata ?? $message->metadata ?? [];
                $meta['delivery_status'] = 'failed';
                $meta['delivery_error'] = $windowError;
                $meta['window_expired'] = true;

                $message->update([
                    'status' => MessageStatus::FAILED,
                    'metadata' => $meta,
                ]);

                broadcast(new MessageStatusUpdated($message->fresh() ?? $message));
                optional($lock)->release();

                return;
            }
        }

        try {
            $adapter = $this->registry->forChannel($channel);
            $result = $adapter->sendOutbound($message);

            if ($result === null) {
                optional($lock)->release();

                return;
            }

            if (! empty($result['already_delivered'])) {
                optional($lock)->release();

                return;
            }

            $freshMessage = $message->fresh() ?? $message;

            $externalId = $result['messages'][0]['id']
                ?? ($result['message_id']
                ?? ($result['result']['message_id']
                ?? ($result['id']
                ?? ($freshMessage->external_message_id ?? ($freshMessage->metadata['external_id'] ?? ($freshMessage->metadata['meta_message_id'] ?? null))))));

            // Determine if provider accepted the message:
            // 1) Explicit external message ID returned or persisted
            // 2) Meta Send API returned recipient_id without error
            // 3) Telegram returned ok: true
            // 4) Fresh message was already marked as SENT by adapter
            $isAccepted = ! empty($externalId)
                || (! empty($result['recipient_id']) && empty($result['error']))
                || (! empty($result['ok']) && ! empty($result['result']))
                || ($freshMessage->metadata['delivery_status'] ?? '') === 'sent';

            if ($isAccepted) {
                $meta = is_array($freshMessage->metadata) ? $freshMessage->metadata : [];
                $meta['delivery_status'] = 'sent';

                if ($externalId) {
                    $externalId = (string) $externalId;
                    $meta['external_id'] = $externalId;

                    if ($channel->type === ChannelType::FACEBOOK_MESSENGER || $channel->type === ChannelType::INSTAGRAM) {
                        $meta['meta_message_id'] = $externalId;
                    } elseif ($channel->type === ChannelType::TELEGRAM) {
                        $meta['telegram_message_id'] = $externalId;
                    } else {
                        $meta['wamid'] = $externalId;
                    }
                }

                if (empty($meta['sent_at'])) {
                    $meta['sent_at'] = now()->toIso8601String();
                }

                $updates = [
                    'status' => MessageStatus::SENT,
                    'metadata' => $meta,
                ];

                if ($externalId && empty($freshMessage->external_message_id)) {
                    $alreadyUsed = Message::where('conversation_id', $freshMessage->conversation_id)
                        ->where('external_message_id', $externalId)
                        ->where('id', '!=', $freshMessage->id)
                        ->exists();

                    if (! $alreadyUsed) {
                        $updates['external_message_id'] = $externalId;
                    }
                }

                if ($freshMessage->status !== MessageStatus::SENT
                    || $freshMessage->metadata !== $meta
                    || (isset($updates['external_message_id']) && $freshMessage->external_message_id !== $updates['external_message_id'])) {
                    $freshMessage->update($updates);
                    $freshMessage = $freshMessage->fresh() ?? $freshMessage;
                    broadcast(new MessageStatusUpdated($freshMessage));
                }

                if ($conversation->status === ConversationStatus::CLOSED) {
                    try {
                        $this->outboxService->reopenClosedConversationAfterAcceptedAgentMessage($freshMessage);
                    } catch (\Throwable $e) {
                        Log::error("[DeliverOutboundChannelMessage] Error al reabrir conversación tras envío aceptado en [{$channel->type->value}]: ".WhatsAppChannelAdapter::sanitizeErrorMessage($e->getMessage()));
                    }
                }
            } else {
                $rawError = (string) ($result['error']['message'] ?? ($result['description'] ?? ($result['message'] ?? '')));
                if ($rawError === '') {
                    $rawError = 'No se pudo entregar el mensaje al canal externo: credenciales incompletas o error del proveedor.';
                }

                $sanitizedError = WhatsAppChannelAdapter::sanitizeErrorMessage($rawError);
                $meta = is_array($freshMessage->metadata) ? $freshMessage->metadata : [];
                $meta['delivery_status'] = 'failed';
                $meta['delivery_error'] = $sanitizedError;

                Log::warning("[DeliverOutboundChannelMessage] Entrega fallida en canal externo [{$channel->type->value}]: {$sanitizedError}", [
                    'message_id' => $message->id,
                    'channel_id' => $channel->id,
                ]);

                $freshMessage->update([
                    'status' => MessageStatus::FAILED,
                    'metadata' => $meta,
                ]);

                broadcast(new MessageStatusUpdated($freshMessage->fresh() ?? $freshMessage));
            }
        } catch (\Throwable $e) {
            $safeError = WhatsAppChannelAdapter::sanitizeErrorMessage($e->getMessage());
            Log::error("[DeliverOutboundChannelMessage] Error despachando mensaje a canal externo: {$safeError}", [
                'message_id' => $message->id,
                'channel_id' => $channel->id,
            ]);

            $meta = $message->fresh()?->metadata ?? $message->metadata ?? [];
            $meta['delivery_status'] = 'failed';
            $meta['delivery_error'] = $safeError;

            $message->update([
                'status' => MessageStatus::FAILED,
                'metadata' => $meta,
            ]);

            broadcast(new MessageStatusUpdated($message->fresh() ?? $message));
        } finally {
            optional($lock)->release();
        }
    }
}
