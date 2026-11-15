<?php

namespace App\Services\Runtime\Channels\Adapters;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\MessageStatusUpdated;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Services\Runtime\Channels\ChannelCapabilities;
use App\Services\Runtime\Channels\Contracts\ChannelAdapter;
use App\Services\Runtime\Channels\InboundMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramChannelAdapter implements ChannelAdapter
{
    public function getChannelType(): string
    {
        return ChannelType::INSTAGRAM->value;
    }

    public function getCapabilities(): ChannelCapabilities
    {
        return ChannelCapabilities::instagram();
    }

    /**
     * Normalizes a raw inbound Instagram Direct webhook payload into an InboundMessage DTO.
     * Strictly processes Direct Messages (DMs) only; rejects or ignores comments/feed/likes.
     *
     * @param  array<string, mixed>  $rawPayload
     */
    public function normalizeInbound(Channel $channel, array $rawPayload): InboundMessage
    {
        $messaging = $rawPayload['messaging'][0]
            ?? ($rawPayload['entry'][0]['messaging'][0] ?? $rawPayload);

        $senderId = (string) ($messaging['sender']['id'] ?? ($rawPayload['from'] ?? ''));
        $messageData = $messaging['message'] ?? null;
        $postbackData = $messaging['postback'] ?? null;

        $messageId = $messageData['mid']
            ?? ($rawPayload['client_message_id']
            ?? ($rawPayload['external_message_id'] ?? null));

        $sourceSentAt = null;
        $sourceTimestamp = $messaging['timestamp'] ?? ($rawPayload['timestamp'] ?? null);
        if ($sourceTimestamp && is_numeric($sourceTimestamp)) {
            $ts = (int) $sourceTimestamp;
            if ($ts > 9999999999) {
                $ts = (int) ($ts / 1000);
            }
            $receivedAt = now();
            if ($ts > 0 && $ts <= $receivedAt->copy()->addMinutes(5)->timestamp) {
                // Never let a provider timestamp in the future extend Meta's service window.
                $sourceSentAt = Carbon::createFromTimestampUTC(min($ts, $receivedAt->timestamp));
            }
        }

        $content = '';
        $normalizedType = MessageType::TEXT;
        $payload = [];

        if ($postbackData) {
            $postbackTitle = (string) ($postbackData['title'] ?? '');
            $postbackPayload = (string) ($postbackData['payload'] ?? '');
            $content = $postbackTitle !== '' ? $postbackTitle : $postbackPayload;
            $messageId = $messageId ?: ('ig_pb_'.($sourceTimestamp ?? time()).'_'.substr(md5($postbackPayload), 0, 10));

            $payload['interactive_reply'] = [
                'type' => 'button_reply',
                'id' => $postbackPayload,
                'title' => $postbackTitle ?: $postbackPayload,
            ];
        } elseif ($messageData) {
            $quickReply = $messageData['quick_reply'] ?? null;
            if ($quickReply && isset($quickReply['payload'])) {
                $qrPayload = (string) $quickReply['payload'];
                $qrTitle = (string) ($messageData['text'] ?? $qrPayload);
                $content = $qrTitle;
                $payload['interactive_reply'] = [
                    'type' => 'button_reply',
                    'id' => $qrPayload,
                    'title' => $qrTitle,
                ];
            } elseif (isset($messageData['text'])) {
                $content = (string) $messageData['text'];
            } elseif (! empty($messageData['attachments'])) {
                $attachment = $messageData['attachments'][0] ?? [];
                $attType = $attachment['type'] ?? 'file';
                $attUrl = $attachment['payload']['url'] ?? null;

                $normalizedType = match ($attType) {
                    'image' => MessageType::IMAGE,
                    'audio' => MessageType::AUDIO,
                    default => MessageType::FILE,
                };

                $content = match ($attType) {
                    'image' => '[Archivo adjunto recibido: imagen]',
                    'audio' => '[Archivo adjunto recibido: audio]',
                    default => '[Archivo adjunto recibido: archivo]',
                };

                $payload['attachment'] = [
                    'url' => $attUrl,
                    'type' => $attType,
                    'mime_type' => match ($attType) {
                        'image' => 'image/jpeg',
                        'audio' => 'audio/mpeg',
                        default => 'application/octet-stream',
                    },
                ];
            }
        }

        $contactPayload = [
            'external_identifier' => $senderId,
            'display_name' => "Instagram User {$senderId}",
            'metadata' => [
                'channel_type' => ChannelType::INSTAGRAM->value,
                'igsid' => $senderId,
            ],
        ];

        return InboundMessage::fromPayload(
            channel: $channel,
            contactPayload: $contactPayload,
            content: $content,
            externalMessageId: $messageId,
            metadata: [
                'sender_id' => $senderId,
                'platform' => 'instagram_direct',
            ],
            correlationId: $rawPayload['correlation_id'] ?? null,
            messageType: $normalizedType,
            payload: $payload,
            sourceSentAt: $sourceSentAt
        );
    }

    /**
     * Formats an outbound Message model for Instagram Send API.
     *
     * @return array<string, mixed>
     */
    public function formatOutbound(Message $message): array
    {
        $conversation = $message->conversation;
        $contact = $conversation?->contact;
        $recipientIgsid = $this->resolveRecipientIgsid($contact);

        $type = $message->message_type instanceof \BackedEnum
            ? $message->message_type->value
            : (string) ($message->message_type ?? 'text');

        $attachment = $message->payload['attachment'] ?? $message->metadata['attachment'] ?? null;

        // 1. Media attachment (image, audio)
        if (($type === 'image' || $type === 'audio') && is_array($attachment) && ! empty($attachment['url'])) {
            $igType = $type === 'image' ? 'image' : 'audio';

            return [
                'recipient' => ['id' => $recipientIgsid],
                'message' => [
                    'attachment' => [
                        'type' => $igType,
                        'payload' => [
                            'url' => $attachment['url'],
                        ],
                    ],
                ],
            ];
        }

        // 2. Interactive options / quick replies (max 13, max 20 chars per label)
        $payload = $message->payload ?? [];
        $metadata = $message->metadata ?? [];
        $options = $payload['options'] ?? ($metadata['options'] ?? ($metadata['choices'] ?? []));
        if (is_array($options)) {
            $options = array_values(array_filter($options, fn ($opt) => ! is_array($opt) || empty($opt['is_hidden'])));
        }

        $hasOptions = is_array($options) && count($options) > 0;
        $isInteractive = ($type === 'interactive')
            || (bool) ($metadata['is_menu'] ?? false)
            || ($hasOptions && ((bool) ($metadata['is_validation_retry'] ?? false) || ! empty($metadata['choices'])));

        if ($isInteractive && $hasOptions) {
            $optCount = count($options);

            if ($optCount <= 13) {
                $quickReplies = [];
                foreach ($options as $idx => $opt) {
                    $id = is_array($opt) ? (string) ($opt['id'] ?? $idx + 1) : (string) ($idx + 1);
                    $text = is_array($opt) ? (string) ($opt['title'] ?? ($opt['text'] ?? '')) : (string) $opt;
                    $quickReplies[] = [
                        'content_type' => 'text',
                        'title' => mb_substr(trim($text), 0, 20),
                        'payload' => mb_substr($id, 0, 1000),
                    ];
                }

                return [
                    'recipient' => ['id' => $recipientIgsid],
                    'message' => [
                        'text' => mb_substr($message->content ?: 'Selecciona una opción:', 0, 1000),
                        'quick_replies' => $quickReplies,
                    ],
                ];
            }

            // Fallback for > 13 options: graceful numbered text list
            $listText = $message->content ? $message->content."\n\n" : '';
            foreach ($options as $idx => $opt) {
                $text = is_array($opt) ? (string) ($opt['title'] ?? ($opt['text'] ?? '')) : (string) $opt;
                $listText .= ($idx + 1).'. '.trim($text)."\n";
            }

            return [
                'recipient' => ['id' => $recipientIgsid],
                'message' => [
                    'text' => mb_substr(trim($listText), 0, 1000),
                ],
            ];
        }

        // Standard text message (Instagram limit: 1000 chars)
        $textContent = mb_substr($message->content ?? '', 0, 1000);

        return [
            'recipient' => ['id' => $recipientIgsid],
            'message' => [
                'text' => $textContent,
            ],
        ];
    }

    /**
     * Dispatches an outbound message to Meta Graph Send API for Instagram Direct.
     *
     * @return array<string, mixed>|null
     */
    public function sendOutbound(Message $message): ?array
    {
        if ($message->visibility === 'internal' || $message->direction !== MessageDirection::OUTBOUND) {
            return null;
        }

        $fresh = $message->fresh() ?? $message;
        $meta = is_array($fresh->metadata) ? $fresh->metadata : [];
        if (! empty($meta['meta_message_id']) || ! empty($fresh->external_message_id) || ($meta['delivery_status'] ?? '') === 'sent') {
            return [
                'message_id' => $meta['meta_message_id'] ?? $fresh->external_message_id,
                'already_delivered' => true,
            ];
        }

        $conversation = $message->conversation;
        $channel = $conversation?->channel;
        if (! $channel || $channel->type !== ChannelType::INSTAGRAM) {
            return null;
        }

        $config = $channel->configuration ?? [];
        $accessToken = (string) ($config['access_token'] ?? ($config['page_access_token'] ?? ''));
        $apiVersion = (string) ($config['api_version'] ?? config('services.meta.graph_version', 'v27.0'));
        $pageId = (string) ($config['page_id'] ?? '');

        if (empty($pageId)) {
            Log::warning("[InstagramChannelAdapter] Canal [{$channel->id}] no tiene Facebook Page ID (page_id) configurado.");

            return [
                'error' => [
                    'message' => 'Canal de Instagram Direct sin Facebook Page ID vinculado configurado.',
                    'code' => 'MISSING_PAGE_ID',
                ],
            ];
        }

        if (empty($accessToken)) {
            Log::warning("[InstagramChannelAdapter] Canal [{$channel->id}] no tiene access_token configurado.");

            return [
                'error' => [
                    'message' => 'Canal de Instagram Direct sin token de acceso configurado.',
                    'code' => 'MISSING_ACCESS_TOKEN',
                ],
            ];
        }

        $recipientIgsid = $this->resolveRecipientIgsid($conversation?->contact);
        if (empty($recipientIgsid)) {
            Log::warning("[InstagramChannelAdapter] Contacto no tiene IGSID válido para canal [{$channel->id}].");

            return [
                'error' => [
                    'message' => 'Contacto no tiene identificador de Instagram (IGSID) válido.',
                    'code' => 'MISSING_RECIPIENT_ID',
                ],
            ];
        }

        $payload = $this->formatOutbound($message);
        $url = "https://graph.facebook.com/{$apiVersion}/{$pageId}/messages";

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post($url, $payload);

            if ($response->successful()) {
                $data = $response->json() ?? [];
                $mid = $data['message_id'] ?? ($data['id'] ?? null);

                $metaUpdates = $message->fresh()?->metadata ?? $message->metadata ?? [];
                $metaUpdates['delivery_status'] = 'sent';
                if ($mid) {
                    $metaUpdates['meta_message_id'] = (string) $mid;
                    $metaUpdates['external_id'] = (string) $mid;
                }
                if (empty($metaUpdates['sent_at'])) {
                    $metaUpdates['sent_at'] = now()->toIso8601String();
                }

                $updates = [
                    'status' => MessageStatus::SENT,
                    'metadata' => $metaUpdates,
                ];

                if ($mid && empty($message->external_message_id)) {
                    $alreadyUsed = Message::where('conversation_id', $message->conversation_id)
                        ->where('external_message_id', (string) $mid)
                        ->where('id', '!=', $message->id)
                        ->exists();

                    if (! $alreadyUsed) {
                        $updates['external_message_id'] = (string) $mid;
                    }
                }

                $message->update($updates);

                broadcast(new MessageStatusUpdated($message->fresh() ?? $message));

                return $data;
            }

            $errorData = $response->json() ?? [];
            $safeError = FacebookMessengerChannelAdapter::sanitizeErrorMessage(
                (string) ($errorData['error']['message'] ?? 'Error al enviar a Instagram Direct')
            );

            Log::error("[InstagramChannelAdapter] Error HTTP {$response->status()} en Meta Instagram Send API", [
                'channel_id' => $channel->id,
                'message_id' => $message->id,
                'error' => $safeError,
            ]);

            $metaUpdates = $message->metadata ?? [];
            $metaUpdates['delivery_status'] = 'failed';
            $metaUpdates['delivery_error'] = [
                'code' => $errorData['error']['code'] ?? $response->status(),
                'message' => $safeError,
            ];

            $message->update([
                'status' => MessageStatus::FAILED,
                'metadata' => $metaUpdates,
            ]);

            broadcast(new MessageStatusUpdated($message));

            return $response->json();
        } catch (\Throwable $e) {
            $safeError = FacebookMessengerChannelAdapter::sanitizeErrorMessage($e->getMessage());
            Log::error("[InstagramChannelAdapter] Excepción de red al enviar mensaje a Instagram: {$safeError}", [
                'channel_id' => $channel->id,
                'message_id' => $message->id,
            ]);

            $metaUpdates = $message->metadata ?? [];
            $metaUpdates['delivery_status'] = 'failed';
            $metaUpdates['delivery_error'] = [
                'code' => 'EXCEPTION',
                'message' => $safeError,
            ];

            $message->update([
                'status' => MessageStatus::FAILED,
                'metadata' => $metaUpdates,
            ]);

            broadcast(new MessageStatusUpdated($message));

            return null;
        }
    }

    protected function resolveRecipientIgsid(?Contact $contact): string
    {
        if (! $contact) {
            return '';
        }

        $meta = $contact->metadata ?? [];
        if (! empty($meta['igsid'])) {
            return (string) $meta['igsid'];
        }

        return (string) ($contact->external_identifier ?? ($contact->phone ?? ''));
    }
}
