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

class TelegramChannelAdapter implements ChannelAdapter
{
    public function getChannelType(): string
    {
        return ChannelType::TELEGRAM->value;
    }

    public function getCapabilities(): ChannelCapabilities
    {
        return ChannelCapabilities::telegram();
    }

    /**
     * Normalizes a raw inbound Telegram update payload into an InboundMessage DTO.
     *
     * @param  array<string, mixed>  $rawPayload
     */
    public function normalizeInbound(Channel $channel, array $rawPayload): InboundMessage
    {
        $messageData = $rawPayload['message']
            ?? ($rawPayload['edited_message'] ?? null);
        $callbackQuery = $rawPayload['callback_query'] ?? null;

        $chatId = '';
        $fromId = '';
        $displayName = 'Usuario Telegram';
        $username = null;
        $messageId = null;
        $sourceSentAt = null;
        $content = '';
        $normalizedType = MessageType::TEXT;
        $payload = [];

        if ($callbackQuery) {
            $from = $callbackQuery['from'] ?? [];
            $callbackMessage = is_array($callbackQuery['message'] ?? null)
                ? $callbackQuery['message']
                : [];
            $chat = $callbackMessage['chat'] ?? [];
            $chatId = (string) ($chat['id'] ?? ($from['id'] ?? ''));
            $fromId = (string) ($from['id'] ?? $chatId);
            $firstName = (string) ($from['first_name'] ?? '');
            $lastName = (string) ($from['last_name'] ?? '');
            $username = $from['username'] ?? null;
            $displayName = trim("{$firstName} {$lastName}") ?: ($username ? "@{$username}" : "Telegram {$fromId}");

            $cbId = (string) ($callbackQuery['id'] ?? '');
            $messageId = "cb_{$cbId}";
            $cbData = (string) ($callbackQuery['data'] ?? '');
            $buttonTitle = $this->findCallbackButtonTitle($callbackMessage, $cbData);
            $content = $buttonTitle ?? 'Opción seleccionada';

            $payload['interactive_reply'] = [
                'type' => 'button_reply',
                'id' => $cbData,
                'title' => $content,
            ];

            // Acknowledge callback query
            if ($cbId !== '') {
                $this->answerCallbackQuery($channel, $cbId);
            }

            if (isset($callbackQuery['message']['date'])) {
                $sourceSentAt = Carbon::createFromTimestampUTC((int) $callbackQuery['message']['date']);
            }
        } elseif ($messageData) {
            $from = $messageData['from'] ?? [];
            $chat = $messageData['chat'] ?? [];
            $chatId = (string) ($chat['id'] ?? ($from['id'] ?? ''));
            $fromId = (string) ($from['id'] ?? $chatId);
            $firstName = (string) ($from['first_name'] ?? '');
            $lastName = (string) ($from['last_name'] ?? '');
            $username = $from['username'] ?? null;
            $displayName = trim("{$firstName} {$lastName}") ?: ($username ? "@{$username}" : "Telegram {$fromId}");

            $messageId = isset($messageData['message_id']) ? (string) $messageData['message_id'] : null;

            if (isset($messageData['date'])) {
                $sourceSentAt = Carbon::createFromTimestampUTC((int) $messageData['date']);
            }

            if (isset($messageData['text'])) {
                $content = (string) $messageData['text'];
            } elseif (! empty($messageData['photo'])) {
                // Telegram sends multiple sizes; last one is highest resolution
                $photos = $messageData['photo'];
                $largestPhoto = end($photos);
                $caption = isset($messageData['caption']) && trim((string) $messageData['caption']) !== ''
                    ? (string) $messageData['caption']
                    : '[Archivo adjunto recibido: foto]';
                $content = $caption;
                $normalizedType = MessageType::IMAGE;
                $payload['attachment'] = [
                    'file_id' => $largestPhoto['file_id'] ?? null,
                    'type' => 'image',
                    'mime_type' => 'image/jpeg',
                ];
            } elseif (isset($messageData['voice']) || isset($messageData['audio'])) {
                $audio = $messageData['voice'] ?? $messageData['audio'];
                $caption = isset($messageData['caption']) && trim((string) $messageData['caption']) !== ''
                    ? (string) $messageData['caption']
                    : '[Archivo adjunto recibido: audio]';
                $content = $caption;
                $normalizedType = MessageType::AUDIO;
                $payload['attachment'] = [
                    'file_id' => $audio['file_id'] ?? null,
                    'type' => 'audio',
                    'mime_type' => $audio['mime_type'] ?? 'audio/ogg',
                ];
            } elseif (isset($messageData['document'])) {
                $doc = $messageData['document'];
                $caption = isset($messageData['caption']) && trim((string) $messageData['caption']) !== ''
                    ? (string) $messageData['caption']
                    : ('[Archivo adjunto recibido: '.($doc['file_name'] ?? 'documento').']');
                $content = $caption;
                $normalizedType = MessageType::FILE;
                $payload['attachment'] = [
                    'file_id' => $doc['file_id'] ?? null,
                    'file_name' => $doc['file_name'] ?? 'documento',
                    'type' => 'document',
                    'mime_type' => $doc['mime_type'] ?? 'application/octet-stream',
                ];
            }
        }

        $contactPayload = [
            'external_identifier' => $chatId,
            'display_name' => $displayName,
            'metadata' => [
                'channel_type' => ChannelType::TELEGRAM->value,
                'chat_id' => $chatId,
                'from_id' => $fromId,
                'username' => $username,
            ],
        ];

        return InboundMessage::fromPayload(
            channel: $channel,
            contactPayload: $contactPayload,
            content: $content,
            externalMessageId: $messageId,
            metadata: [
                'chat_id' => $chatId,
                'platform' => 'telegram',
            ],
            correlationId: $rawPayload['correlation_id'] ?? null,
            messageType: $normalizedType,
            payload: $payload,
            sourceSentAt: $sourceSentAt
        );
    }

    /**
     * Formats an outbound Message model for Telegram Bot API.
     *
     * @return array{method: string, payload: array<string, mixed>}
     */
    public function formatOutbound(Message $message): array
    {
        $conversation = $message->conversation;
        $contact = $conversation?->contact;
        $chatId = $this->resolveChatId($contact);

        $type = $message->message_type instanceof \BackedEnum
            ? $message->message_type->value
            : (string) ($message->message_type ?? 'text');

        $attachment = $message->payload['attachment'] ?? $message->metadata['attachment'] ?? null;

        // 1. Photo / Image Attachment
        if ($type === 'image' && is_array($attachment) && ! empty($attachment['url'])) {
            $caption = mb_substr($message->content ?? '', 0, 1024);

            return [
                'method' => 'sendPhoto',
                'payload' => [
                    'chat_id' => $chatId,
                    'photo' => $attachment['url'],
                    'caption' => $caption,
                ],
            ];
        }

        // 2. Audio Attachment
        if ($type === 'audio' && is_array($attachment) && ! empty($attachment['url'])) {
            return [
                'method' => 'sendAudio',
                'payload' => [
                    'chat_id' => $chatId,
                    'audio' => $attachment['url'],
                    'caption' => mb_substr($message->content ?? '', 0, 1024),
                ],
            ];
        }

        // 3. Document / File Attachment
        if (($type === 'file' || $type === 'document') && is_array($attachment) && ! empty($attachment['url'])) {
            return [
                'method' => 'sendDocument',
                'payload' => [
                    'chat_id' => $chatId,
                    'document' => $attachment['url'],
                    'caption' => mb_substr($message->content ?? '', 0, 1024),
                ],
            ];
        }

        // 4. Interactive options (Inline Keyboard)
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

        $replyMarkup = null;
        if ($isInteractive && $hasOptions) {
            $keyboard = [];
            foreach ($options as $idx => $opt) {
                $id = is_array($opt) ? (string) ($opt['id'] ?? $idx + 1) : (string) ($idx + 1);
                $text = is_array($opt) ? (string) ($opt['title'] ?? ($opt['text'] ?? '')) : (string) $opt;
                // Telegram callback_data limit: 1-64 bytes
                $cbData = mb_substr(trim($id), 0, 64);

                $keyboard[] = [
                    [
                        'text' => mb_substr(trim($text), 0, 64),
                        'callback_data' => $cbData,
                    ],
                ];
            }

            $replyMarkup = ['inline_keyboard' => $keyboard];
        }

        $textContent = mb_substr($message->content ?? '', 0, 4096);

        $outPayload = [
            'chat_id' => $chatId,
            'text' => $textContent !== '' ? $textContent : '...',
        ];

        if ($replyMarkup) {
            $outPayload['reply_markup'] = $replyMarkup;
        }

        return [
            'method' => 'sendMessage',
            'payload' => $outPayload,
        ];
    }

    /**
     * Dispatches an outbound message to Telegram Bot API.
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
        if (! empty($meta['telegram_message_id']) || ! empty($fresh->external_message_id) || ($meta['delivery_status'] ?? '') === 'sent') {
            return [
                'message_id' => $meta['telegram_message_id'] ?? $fresh->external_message_id,
                'already_delivered' => true,
            ];
        }

        $conversation = $message->conversation;
        $channel = $conversation?->channel;
        if (! $channel || $channel->type !== ChannelType::TELEGRAM) {
            return null;
        }

        $config = $channel->configuration ?? [];
        $botToken = (string) ($config['bot_token'] ?? ($config['access_token'] ?? ''));

        if (empty($botToken)) {
            Log::warning("[TelegramChannelAdapter] Canal [{$channel->id}] no tiene bot_token configurado.");

            return [
                'ok' => false,
                'description' => 'Canal de Telegram sin bot_token configurado para envío saliente.',
            ];
        }

        $chatId = $this->resolveChatId($conversation?->contact);
        if (empty($chatId)) {
            Log::warning("[TelegramChannelAdapter] Contacto no tiene chat_id de Telegram válido para canal [{$channel->id}].");

            return [
                'ok' => false,
                'description' => 'Contacto no tiene chat_id de Telegram válido.',
            ];
        }

        $formatted = $this->formatOutbound($message);
        $method = $formatted['method'];
        $payload = $formatted['payload'];
        $url = "https://api.telegram.org/bot{$botToken}/{$method}";

        try {
            $response = Http::timeout(10)->post($url, $payload);

            if ($response->successful()) {
                $data = $response->json();
                $telegramMsgId = $data['result']['message_id'] ?? null;

                $metaUpdates = $message->metadata ?? [];
                $updates = ['status' => MessageStatus::SENT];

                if ($telegramMsgId) {
                    $metaUpdates['telegram_message_id'] = (string) $telegramMsgId;
                    $metaUpdates['delivery_status'] = 'sent';
                    if (empty($metaUpdates['sent_at'])) {
                        $metaUpdates['sent_at'] = now()->toIso8601String();
                    }

                    $alreadyUsed = Message::where('conversation_id', $message->conversation_id)
                        ->where('external_message_id', (string) $telegramMsgId)
                        ->where('id', '!=', $message->id)
                        ->exists();

                    if (! $alreadyUsed) {
                        $updates['external_message_id'] = (string) $telegramMsgId;
                    }
                }

                $updates['metadata'] = $metaUpdates;
                $message->update($updates);

                broadcast(new MessageStatusUpdated($message));

                return $data;
            }

            $errorData = $response->json() ?? [];
            $safeError = self::sanitizeErrorMessage((string) ($errorData['description'] ?? 'Error al enviar a Telegram'));

            Log::error("[TelegramChannelAdapter] Error HTTP {$response->status()} en Telegram Bot API: {$safeError}", [
                'channel_id' => $channel->id,
                'message_id' => $message->id,
            ]);

            $metaUpdates = $message->metadata ?? [];
            $metaUpdates['delivery_status'] = 'failed';
            $metaUpdates['delivery_error'] = [
                'code' => $errorData['error_code'] ?? $response->status(),
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
            Log::error("[TelegramChannelAdapter] Excepción de red al enviar mensaje a Telegram: {$safeError}", [
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

    /**
     * Registers or updates a Telegram Bot webhook URL.
     *
     * @return array<string, mixed>
     */
    public function setWebhook(Channel $channel, string $url, ?string $secretToken = null): array
    {
        $config = $channel->configuration ?? [];
        $botToken = (string) ($config['bot_token'] ?? ($config['access_token'] ?? ''));

        if (empty($botToken)) {
            return ['ok' => false, 'description' => 'Bot token no configurado en el canal.'];
        }

        $params = [
            'url' => $url,
            'allowed_updates' => ['message', 'callback_query'],
        ];

        if (! empty($secretToken)) {
            $params['secret_token'] = $secretToken;
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$botToken}/setWebhook", $params);

            $data = $response->json() ?? [];
            if (isset($data['description'])) {
                $data['description'] = self::sanitizeErrorMessage((string) $data['description']);
            }

            return $data ?: ['ok' => false, 'description' => 'Respuesta vacía de Telegram'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'description' => self::sanitizeErrorMessage($e->getMessage())];
        }
    }

    /**
     * Retrieves current Telegram Bot webhook information.
     *
     * @return array<string, mixed>
     */
    public function getWebhookInfo(Channel $channel): array
    {
        $config = $channel->configuration ?? [];
        $botToken = (string) ($config['bot_token'] ?? ($config['access_token'] ?? ''));

        if (empty($botToken)) {
            return ['ok' => false, 'description' => 'Bot token no configurado en el canal.'];
        }

        try {
            $response = Http::timeout(10)->get("https://api.telegram.org/bot{$botToken}/getWebhookInfo");

            $data = $response->json() ?? [];
            if (isset($data['description'])) {
                $data['description'] = self::sanitizeErrorMessage((string) $data['description']);
            }

            return $data ?: ['ok' => false, 'description' => 'Respuesta vacía de Telegram'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'description' => self::sanitizeErrorMessage($e->getMessage())];
        }
    }

    /**
     * Acknowledges an incoming callback_query from an inline button press.
     */
    public function answerCallbackQuery(Channel $channel, string $callbackQueryId): void
    {
        $config = $channel->configuration ?? [];
        $botToken = (string) ($config['bot_token'] ?? ($config['access_token'] ?? ''));

        if (empty($botToken) || empty($callbackQueryId)) {
            return;
        }

        try {
            Http::timeout(5)->post("https://api.telegram.org/bot{$botToken}/answerCallbackQuery", [
                'callback_query_id' => $callbackQueryId,
            ]);
        } catch (\Throwable) {
            // Non-critical background acknowledgement
        }
    }

    /**
     * Telegram callback data is an internal routing value. Resolve the button label
     * from the keyboard attached to the callback message so the inbox stores a
     * human-readable inbound message while retaining callback_data separately.
     *
     * @param  array<string, mixed>  $message
     */
    protected function findCallbackButtonTitle(array $message, string $callbackData): ?string
    {
        if ($callbackData === '') {
            return null;
        }

        $keyboard = $message['reply_markup']['inline_keyboard'] ?? [];
        if (! is_array($keyboard)) {
            return null;
        }

        foreach ($keyboard as $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach ($row as $button) {
                if (! is_array($button) || ! isset($button['callback_data'], $button['text'])) {
                    continue;
                }

                if ((string) $button['callback_data'] === $callbackData) {
                    $title = trim((string) $button['text']);

                    return $title !== '' ? $title : null;
                }
            }
        }

        return null;
    }

    protected function resolveChatId(?Contact $contact): string
    {
        if (! $contact) {
            return '';
        }

        $meta = $contact->metadata ?? [];
        if (! empty($meta['chat_id'])) {
            return (string) $meta['chat_id'];
        }

        return (string) ($contact->external_identifier ?? '');
    }

    public static function sanitizeErrorMessage(string $message): string
    {
        return preg_replace([
            '/bot\d+:[a-zA-Z0-9_\-]+/',
            '/\d{8,12}:[a-zA-Z0-9_\-]{20,}/',
        ], [
            'bot***REDACTED_TELEGRAM_TOKEN***',
            '***REDACTED_TELEGRAM_TOKEN***',
        ], $message) ?? 'Error en Telegram Bot API';
    }
}
