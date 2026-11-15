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

class FacebookMessengerChannelAdapter implements ChannelAdapter
{
    public function getChannelType(): string
    {
        return ChannelType::FACEBOOK_MESSENGER->value;
    }

    public function getCapabilities(): ChannelCapabilities
    {
        return ChannelCapabilities::facebookMessenger();
    }

    /**
     * Normalizes a raw inbound Facebook Messenger webhook payload into an InboundMessage DTO.
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
            // Meta timestamps are in milliseconds
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
            $messageId = $messageId ?: ('pb_'.($sourceTimestamp ?? time()).'_'.substr(md5($postbackPayload), 0, 10));

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

        // Contact payload for Facebook Messenger PSID
        $contactPayload = [
            'external_identifier' => $senderId,
            'display_name' => "Facebook User {$senderId}",
            'metadata' => [
                'channel_type' => ChannelType::FACEBOOK_MESSENGER->value,
                'psid' => $senderId,
            ],
        ];

        return InboundMessage::fromPayload(
            channel: $channel,
            contactPayload: $contactPayload,
            content: $content,
            externalMessageId: $messageId,
            metadata: [
                'sender_id' => $senderId,
                'platform' => 'facebook_messenger',
            ],
            correlationId: $rawPayload['correlation_id'] ?? null,
            messageType: $normalizedType,
            payload: $payload,
            sourceSentAt: $sourceSentAt
        );
    }

    /**
     * Formats an outbound Message model for Facebook Messenger Send API.
     *
     * @return array<string, mixed>
     */
    public function formatOutbound(Message $message): array
    {
        $conversation = $message->conversation;
        $contact = $conversation?->contact;
        $recipientPsid = $this->resolveRecipientPsid($contact);

        $type = $message->message_type instanceof \BackedEnum
            ? $message->message_type->value
            : (string) ($message->message_type ?? 'text');

        $attachment = $message->payload['attachment'] ?? $message->metadata['attachment'] ?? null;

        // 1. Media attachment (image, audio, file)
        if (($type === 'image' || $type === 'audio' || $type === 'file' || $type === 'document') && is_array($attachment) && ! empty($attachment['url'])) {
            $fbType = match ($type) {
                'image' => 'image',
                'audio' => 'audio',
                default => 'file',
            };

            return [
                'recipient' => ['id' => $recipientPsid],
                'messaging_type' => 'RESPONSE',
                'message' => [
                    'attachment' => [
                        'type' => $fbType,
                        'payload' => [
                            'url' => $attachment['url'],
                            'is_reusable' => true,
                        ],
                    ],
                ],
            ];
        }

        // 2. Interactive options: buttons / menus
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

            // Up to 3 options: use Facebook Button Template
            if ($optCount <= 3) {
                $buttons = [];
                foreach ($options as $idx => $opt) {
                    $id = is_array($opt) ? (string) ($opt['id'] ?? $idx + 1) : (string) ($idx + 1);
                    $text = is_array($opt) ? (string) ($opt['title'] ?? ($opt['text'] ?? '')) : (string) $opt;
                    // Facebook constraint: title max 20 chars, payload max 1000 chars
                    $buttons[] = [
                        'type' => 'postback',
                        'title' => mb_substr(trim($text), 0, 20),
                        'payload' => mb_substr($id, 0, 1000),
                    ];
                }

                $text = mb_substr($message->content ?: 'Selecciona una opción:', 0, 640);

                return [
                    'recipient' => ['id' => $recipientPsid],
                    'messaging_type' => 'RESPONSE',
                    'message' => [
                        'attachment' => [
                            'type' => 'template',
                            'payload' => [
                                'template_type' => 'button',
                                'text' => $text,
                                'buttons' => $buttons,
                            ],
                        ],
                    ],
                ];
            }

            // Up to 13 options: use Facebook Quick Replies
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
                    'recipient' => ['id' => $recipientPsid],
                    'messaging_type' => 'RESPONSE',
                    'message' => [
                        'text' => mb_substr($message->content ?: 'Selecciona una opción:', 0, 2000),
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
                'recipient' => ['id' => $recipientPsid],
                'messaging_type' => 'RESPONSE',
                'message' => [
                    'text' => mb_substr(trim($listText), 0, 2000),
                ],
            ];
        }

        // Standard text message (max 2000 chars)
        $textContent = mb_substr($message->content ?? '', 0, 2000);

        return [
            'recipient' => ['id' => $recipientPsid],
            'messaging_type' => 'RESPONSE',
            'message' => [
                'text' => $textContent,
            ],
        ];
    }

    /**
     * Dispatches an outbound message to Meta Graph Send API for Facebook Messenger.
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
        if (! $channel || $channel->type !== ChannelType::FACEBOOK_MESSENGER) {
            return null;
        }

        $config = $channel->configuration ?? [];
        $pageId = (string) ($config['page_id'] ?? '');
        $accessToken = (string) ($config['access_token'] ?? ($config['page_access_token'] ?? ''));
        $apiVersion = (string) ($config['api_version'] ?? config('services.meta.graph_version', 'v27.0'));

        if (empty($pageId)) {
            Log::warning("[FacebookMessengerChannelAdapter] Canal [{$channel->id}] no tiene page_id configurado.");

            return [
                'error' => [
                    'message' => 'Canal de Facebook Messenger sin Page ID configurado.',
                    'code' => 'MISSING_PAGE_ID',
                ],
            ];
        }

        if (empty($accessToken)) {
            Log::warning("[FacebookMessengerChannelAdapter] Canal [{$channel->id}] no tiene access_token configurado.");

            return [
                'error' => [
                    'message' => 'Canal de Facebook Messenger sin token de acceso configurado.',
                    'code' => 'MISSING_ACCESS_TOKEN',
                ],
            ];
        }

        $recipientPsid = $this->resolveRecipientPsid($conversation?->contact);
        if (empty($recipientPsid)) {
            Log::warning("[FacebookMessengerChannelAdapter] Contacto no tiene PSID válido para canal [{$channel->id}].");

            return [
                'error' => [
                    'message' => 'Contacto no tiene identificador de Facebook Messenger (PSID) válido.',
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

                $updates['metadata'] = $metaUpdates;
                $message->update($updates);

                broadcast(new MessageStatusUpdated($message->fresh() ?? $message));

                return $data;
            }

            $errorData = $response->json() ?? [];
            $safeError = self::sanitizeErrorMessage((string) ($errorData['error']['message'] ?? 'Error al enviar a Facebook Messenger'));

            Log::error("[FacebookMessengerChannelAdapter] Error HTTP {$response->status()} en Meta Send API", [
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
            $safeError = self::sanitizeErrorMessage($e->getMessage());
            Log::error("[FacebookMessengerChannelAdapter] Excepción de red al enviar mensaje: {$safeError}", [
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

    protected function resolveRecipientPsid(?Contact $contact): string
    {
        if (! $contact) {
            return '';
        }

        $meta = $contact->metadata ?? [];
        if (! empty($meta['psid'])) {
            return (string) $meta['psid'];
        }

        return (string) ($contact->external_identifier ?? ($contact->phone ?? ''));
    }

    public static function sanitizeErrorMessage(string $message): string
    {
        return preg_replace([
            '/access_token=[a-zA-Z0-9_\-\.]+/',
            '/EAA[a-zA-Z0-9]+/',
            '/Bearer\s+[a-zA-Z0-9_\-\.]+/',
        ], [
            'access_token=***REDACTED***',
            '***REDACTED_META_TOKEN***',
            'Bearer ***REDACTED***',
        ], $message) ?? 'Error en canal de mensajería';
    }
}
