<?php

namespace App\Services\Runtime\Channels\Adapters;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Events\MessageStatusUpdated;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Services\Media\WhatsAppMediaPrewarmService;
use App\Services\Media\WhatsAppMediaService;
use App\Services\Presence\AgentAvailabilityService;
use App\Services\Runtime\Channels\ChannelCapabilities;
use App\Services\Runtime\Channels\Contracts\ChannelAdapter;
use App\Services\Runtime\Channels\InboundMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppChannelAdapter implements ChannelAdapter
{
    protected WhatsAppMediaService $mediaService;

    protected WhatsAppMediaPrewarmService $prewarmService;

    public function __construct(
        ?WhatsAppMediaService $mediaService = null,
        ?WhatsAppMediaPrewarmService $prewarmService = null
    ) {
        $this->mediaService = $mediaService ?? app(WhatsAppMediaService::class);
        $this->prewarmService = $prewarmService ?? app(WhatsAppMediaPrewarmService::class);
    }

    public function getChannelType(): string
    {
        return ChannelType::WHATSAPP->value;
    }

    public function getCapabilities(): ChannelCapabilities
    {
        return ChannelCapabilities::whatsapp();
    }

    /**
     * Normalizes a raw inbound WhatsApp webhook payload into an InboundMessage DTO.
     *
     * Supports:
     * - Text messages (type === 'text', text.body)
     * - Interactive button replies (type === 'interactive', interactive.type === 'button_reply')
     * - Interactive list replies (type === 'interactive', interactive.type === 'list_reply')
     *
     * @param  array<string, mixed>  $rawPayload
     */
    public function normalizeInbound(Channel $channel, array $rawPayload): InboundMessage
    {
        // Check if rawPayload contains an envelope or a single message item
        $messageData = $rawPayload['message'] ?? $rawPayload;
        $contactProfile = $rawPayload['contact_profile'] ?? null;
        $contactWaId = $rawPayload['contact_wa_id'] ?? null;

        // If the entire Meta entry envelope was passed
        if (isset($rawPayload['entry'][0]['changes'][0]['value'])) {
            $val = $rawPayload['entry'][0]['changes'][0]['value'];
            $messageData = $val['messages'][0] ?? [];
            $contactProfile = $val['contacts'][0]['profile']['name'] ?? null;
            $contactWaId = $val['contacts'][0]['wa_id'] ?? null;
        }

        $messageId = $messageData['id'] ?? ($rawPayload['client_message_id'] ?? null);
        $from = (string) ($messageData['from'] ?? ($contactWaId ?? ($rawPayload['from'] ?? '')));
        $profileName = $contactProfile ?: ($rawPayload['profile_name'] ?? ($rawPayload['name'] ?? null));
        $sourceSentAt = null;
        $sourceTimestamp = $messageData['timestamp'] ?? null;
        if ((is_string($sourceTimestamp) || is_int($sourceTimestamp))
            && preg_match('/^\d{1,12}$/', (string) $sourceTimestamp) === 1) {
            $sourceTimestamp = (int) $sourceTimestamp;
            if ($sourceTimestamp > 0 && $sourceTimestamp <= now()->addMinutes(5)->timestamp) {
                $sourceSentAt = Carbon::createFromTimestampUTC($sourceTimestamp);
            }
        }

        $msgType = $messageData['type'] ?? 'text';
        $content = '';
        $normalizedType = 'text';
        $payload = [];
        $interactiveReply = null;
        $buttonReplyId = null;
        $buttonReplyTitle = null;
        $listReplyId = null;
        $listReplyTitle = null;

        if ($msgType === 'text') {
            $content = (string) ($messageData['text']['body'] ?? ($messageData['content'] ?? ''));
        } elseif ($msgType === 'image') {
            $img = $messageData['image'] ?? [];
            $caption = $img['caption'] ?? '';
            $content = (string) ($caption ?: 'Imagen recibida');
            $normalizedType = 'image';
            $mediaId = $img['id'] ?? null;
            $defaultFilename = 'imagen_'.($mediaId ?? 'wa').'.jpg';

            $attachment = array_filter([
                'media_id' => $mediaId,
                'mime_type' => $img['mime_type'] ?? 'image/jpeg',
                'sha256' => $img['sha256'] ?? null,
                'file_name' => $defaultFilename,
                'status' => 'pending_download',
                'url' => null,
                'disk_path' => null,
            ], fn ($val) => $val !== null);

            $payload['attachment'] = $attachment;
        } elseif ($msgType === 'document') {
            $doc = $messageData['document'] ?? [];
            $caption = $doc['caption'] ?? '';
            $filename = $doc['filename'] ?? 'documento.pdf';
            $content = (string) ($caption ?: $filename);
            $normalizedType = 'file';
            $mediaId = $doc['id'] ?? null;

            $attachment = array_filter([
                'media_id' => $mediaId,
                'file_name' => $filename,
                'mime_type' => $doc['mime_type'] ?? 'application/pdf',
                'sha256' => $doc['sha256'] ?? null,
                'status' => 'pending_download',
                'url' => null,
                'disk_path' => null,
            ], fn ($val) => $val !== null);

            $payload['attachment'] = $attachment;
        } elseif ($msgType === 'audio') {
            $audio = $messageData['audio'] ?? [];
            $mediaId = $audio['id'] ?? null;
            $isVoice = ! empty($audio['voice']);
            $mimeType = $audio['mime_type'] ?? 'audio/ogg';
            $defaultFilename = $isVoice ? 'voice-note.ogg' : 'audio_'.($mediaId ?? 'wa').'.mp3';
            $content = (string) ($isVoice ? '🎵 Nota de voz' : '🎵 Audio');
            $normalizedType = 'audio';

            $attachment = array_filter([
                'media_id' => $mediaId,
                'file_name' => $defaultFilename,
                'mime_type' => $mimeType,
                'sha256' => $audio['sha256'] ?? null,
                'is_voice_note' => $isVoice,
                'status' => 'pending_download',
                'url' => null,
                'disk_path' => null,
            ], fn ($val) => $val !== null);

            $payload['attachment'] = $attachment;
        } elseif ($msgType === 'interactive' && isset($messageData['interactive'])) {
            $interactive = $messageData['interactive'];
            $intType = $interactive['type'] ?? '';

            if ($intType === 'button_reply' && isset($interactive['button_reply'])) {
                $interactiveReply = $interactive['button_reply'];
                $buttonReplyId = $interactiveReply['id'] ?? null;
                $buttonReplyTitle = $interactiveReply['title'] ?? null;
                // Expose text/title as content for human readability and fallback matching
                $content = (string) ($buttonReplyTitle ?: ($buttonReplyId ?: ''));
            } elseif ($intType === 'list_reply' && isset($interactive['list_reply'])) {
                $interactiveReply = $interactive['list_reply'];
                $listReplyId = $interactiveReply['id'] ?? null;
                $listReplyTitle = $interactiveReply['title'] ?? null;
                $content = (string) ($listReplyTitle ?: ($listReplyId ?: ''));
            }
        } else {
            // Fallback for direct content or custom payload format
            $content = (string) ($messageData['content'] ?? '');
        }

        $contactMetadata = array_filter([
            'wa_id' => $from,
            'profile_name' => $profileName,
            'channel_type' => 'whatsapp',
        ]);

        $messageMetadata = array_filter([
            'whatsapp_message_id' => $messageId,
            'whatsapp_type' => $msgType,
            'interactive_reply' => $interactiveReply,
            'button_reply_id' => $buttonReplyId,
            'button_reply_title' => $buttonReplyTitle,
            'list_reply_id' => $listReplyId,
            'list_reply_title' => $listReplyTitle,
        ], fn ($val) => $val !== null);

        return InboundMessage::fromPayload(
            channel: $channel,
            contactPayload: [
                'external_identifier' => $from,
                'display_name' => $profileName ?: $from,
                'phone' => $from,
                'metadata' => $contactMetadata,
            ],
            content: $content,
            externalMessageId: $messageId,
            metadata: $messageMetadata,
            correlationId: $rawPayload['correlation_id'] ?? null,
            messageType: $normalizedType,
            payload: $payload,
            sourceSentAt: $sourceSentAt
        );
    }

    /**
     * Formats an outbound Message model for delivery to Meta Graph API.
     *
     * @return array<string, mixed>
     */
    public function formatOutbound(Message $message): array
    {
        $conversation = $message->conversation;
        $channel = $conversation?->channel;
        $contact = $conversation?->contact;
        $to = $this->resolveRecipientPhone($contact);

        $type = $message->message_type instanceof \BackedEnum
            ? $message->message_type->value
            : (string) ($message->message_type ?? '');
        $attachment = $message->payload['attachment'] ?? $message->metadata['attachment'] ?? null;

        // 1. Outbound Image Attachment
        if ($type === 'image' && is_array($attachment)) {
            $this->validateOutboundMediaUrl($attachment['url'] ?? null);
            $cachedMediaId = ($channel && ! empty($attachment['url']))
                ? $this->prewarmService->getValidMediaId($channel, (string) $attachment['url'])
                : null;

            $imagePayload = [];
            if ($cachedMediaId) {
                $imagePayload['id'] = $cachedMediaId;
            } else {
                $imagePayload['link'] = $attachment['url'] ?? '';
            }
            if (! empty($message->content)) {
                $imagePayload['caption'] = $message->content;
            }

            return [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'image',
                'image' => $imagePayload,
            ];
        }

        // Outbound Audio / Voice Note Attachment
        if ($type === 'audio' && is_array($attachment)) {
            $this->validateOutboundMediaUrl($attachment['url'] ?? null);
            $cachedMediaId = ($channel && ! empty($attachment['url']))
                ? $this->prewarmService->getValidMediaId($channel, (string) $attachment['url'])
                : null;

            $audioPayload = [];
            if ($cachedMediaId) {
                $audioPayload['id'] = $cachedMediaId;
            } else {
                $audioPayload['link'] = $attachment['url'] ?? '';
            }

            return [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'audio',
                'audio' => $audioPayload,
            ];
        }

        // 2. Outbound Document / File Attachment
        if (($type === 'file' || $type === 'document') && is_array($attachment)) {
            $this->validateOutboundMediaUrl($attachment['url'] ?? null);
            $rawFilename = $attachment['file_name'] ?? null;
            if (! is_string($rawFilename) || trim($rawFilename) === '') {
                $rawFilename = ! empty($attachment['url']) ? basename(parse_url($attachment['url'], PHP_URL_PATH) ?: '') : '';
            }
            if (empty($rawFilename)) {
                $rawFilename = 'documento.pdf';
            }

            // Ensure filename has an appropriate extension if filename lacks extension
            if (! str_contains($rawFilename, '.')) {
                $mime = $attachment['mime_type'] ?? null;
                $ext = match ($mime) {
                    'application/pdf' => 'pdf',
                    'text/plain' => 'txt',
                    'text/csv' => 'csv',
                    'application/msword' => 'doc',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                    'application/vnd.ms-excel' => 'xls',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                    default => 'pdf',
                };
                $rawFilename .= ".{$ext}";
            }

            $cachedMediaId = ($channel && ! empty($attachment['url']))
                ? $this->prewarmService->getValidMediaId($channel, (string) $attachment['url'])
                : null;

            $docPayload = [
                'filename' => $rawFilename,
            ];
            if ($cachedMediaId) {
                $docPayload['id'] = $cachedMediaId;
            } else {
                $docPayload['link'] = $attachment['url'] ?? '';
            }

            if (! empty($message->content)) {
                $docPayload['caption'] = $message->content;
            } elseif (! empty($attachment['caption'])) {
                $docPayload['caption'] = $attachment['caption'];
            }

            return [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'document',
                'document' => $docPayload,
            ];
        }

        // 3. Outbound WhatsApp Approved Template
        if ($type === 'template') {
            $templateData = $message->payload['template'] ?? [];
            $templatePayload = [
                'name' => $templateData['name'] ?? '',
                'language' => [
                    'code' => $templateData['language']['code'] ?? 'es',
                ],
            ];
            if (! empty($templateData['components'])) {
                $templatePayload['components'] = $templateData['components'];
            }

            return [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'template',
                'template' => $templatePayload,
            ];
        }

        $payload = $message->payload ?? [];
        $metadata = $message->metadata ?? [];
        $options = $payload['options'] ?? ($metadata['options'] ?? ($metadata['choices'] ?? []));
        if (is_array($options)) {
            $options = array_values(array_filter($options, function ($opt) {
                if (is_array($opt)) {
                    $isHidden = isset($opt['is_hidden'])
                        ? (bool) $opt['is_hidden']
                        : (isset($opt['visible']) ? ! (bool) $opt['visible'] : false);

                    if (! empty($opt['hide_if_no_agents']) && ! AgentAvailabilityService::hasOnlineAgents()) {
                        $isHidden = true;
                    }

                    return ! $isHidden;
                }

                return true;
            }));
        }
        $hasOptions = is_array($options) && count($options) > 0;
        $isInteractive = ($type === 'interactive')
            || (bool) ($metadata['is_menu'] ?? false)
            || ($hasOptions && ((bool) ($metadata['is_validation_retry'] ?? false) || ! empty($metadata['choices'])));

        // Check if message is interactive menu, question with choices, or retry with buttons
        if ($isInteractive && $hasOptions) {
            $optCount = count($options);
            $normalizedOptions = array_values($options);

            // 1. Reply Buttons (1 to 3 options)
            if ($optCount <= 3) {
                return [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                    'type' => 'interactive',
                    'interactive' => [
                        'type' => 'button',
                        'body' => [
                            'text' => $message->content,
                        ],
                        'action' => [
                            'buttons' => array_map(function ($opt, $idx) {
                                $id = is_array($opt) ? (string) ($opt['id'] ?? $idx + 1) : (string) ($idx + 1);
                                $text = is_array($opt) ? (string) ($opt['title'] ?? ($opt['text'] ?? '')) : (string) $opt;
                                // Meta constraint: title max 20 chars
                                $title = mb_substr(trim($text), 0, 20);

                                return [
                                    'type' => 'reply',
                                    'reply' => [
                                        'id' => $id,
                                        'title' => $title,
                                    ],
                                ];
                            }, $normalizedOptions, array_keys($normalizedOptions)),
                        ],
                    ],
                ];
            }

            // 2. Interactive List (4 to 10 options)
            if ($optCount <= 10) {
                return [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                    'type' => 'interactive',
                    'interactive' => [
                        'type' => 'list',
                        'body' => [
                            'text' => $message->content,
                        ],
                        'action' => [
                            'button' => 'Ver opciones',
                            'sections' => [
                                [
                                    'title' => 'Opciones',
                                    'rows' => array_map(function ($opt, $idx) {
                                        $id = is_array($opt) ? (string) ($opt['id'] ?? $idx + 1) : (string) ($idx + 1);
                                        $text = is_array($opt) ? (string) ($opt['title'] ?? ($opt['text'] ?? '')) : (string) $opt;
                                        // Meta constraint: title max 24 chars
                                        $title = mb_substr(trim($text), 0, 24);

                                        return [
                                            'id' => $id,
                                            'title' => $title,
                                        ];
                                    }, $normalizedOptions, array_keys($normalizedOptions)),
                                ],
                            ],
                        ],
                    ],
                ];
            }

            // 3. Fallback for > 10 options: Clean numbered text list
            $fallbackText = $message->content."\n";
            foreach ($normalizedOptions as $idx => $opt) {
                $text = is_array($opt) ? ($opt['title'] ?? ($opt['text'] ?? '')) : (string) $opt;
                $num = $idx + 1;
                $fallbackText .= "\n{$num}. {$text}";
            }

            return [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'text',
                'text' => [
                    'body' => $fallbackText,
                ],
            ];
        }

        // Standard text message
        return [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'body' => $message->content,
            ],
        ];
    }

    /**
     * Dispatches an outbound message to Meta Graph API.
     *
     * @return array<string, mixed>|null
     */
    public function sendOutbound(Message $message): ?array
    {
        // Rule: Internal notes are NEVER dispatched to Meta
        if ($message->visibility === 'internal' || $message->direction !== MessageDirection::OUTBOUND) {
            return null;
        }

        // Idempotency: Prevent double dispatch if already sent or external_message_id is assigned
        $fresh = $message->fresh() ?? $message;
        $meta = is_array($fresh->metadata) ? $fresh->metadata : [];
        if (! empty($meta['meta_message_id']) || ! empty($meta['wamid']) || ! empty($fresh->external_message_id) || ($meta['delivery_status'] ?? '') === 'sent') {
            $existingWamid = $meta['wamid'] ?? ($fresh->external_message_id ?? ($meta['meta_message_id'] ?? 'wamid.already_sent'));
            Log::info('whatsapp.outbox.duplicate_dispatch_prevented', [
                'message_id' => $message->id,
                'conversation_id' => $fresh->conversation_id,
                'external_message_id_hash' => substr(hash('sha256', (string) $existingWamid), 0, 16),
            ]);

            return [
                'messages' => [['id' => $existingWamid]],
                'already_delivered' => true,
            ];
        }

        $conversation = $message->conversation;
        if (! $conversation) {
            return null;
        }

        $channel = $conversation->channel;
        if (! $channel || $channel->type !== ChannelType::WHATSAPP) {
            return null;
        }

        $config = $channel->configuration ?? [];
        $accessToken = (string) ($config['access_token'] ?? '');
        $phoneNumberId = (string) ($config['phone_number_id'] ?? '');
        $apiVersion = (string) ($config['api_version'] ?? config('services.meta.graph_version', 'v27.0'));

        if (empty($accessToken) || empty($phoneNumberId)) {
            Log::warning("[WhatsAppChannelAdapter] Canal [{$channel->id}] no tiene credenciales de WhatsApp configuradas.");

            return null;
        }

        $payload = $this->formatOutbound($message);
        $url = "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages";

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post($url, $payload);

            // If Meta unequivocally rejected the media ID with an explicit 4xx error indicating
            // that the media ID is invalid, does not exist, or expired, retry once with fallback URL link.
            // NEVER retry on network exception, timeout, 5xx server error, 131051 (unsupported message type),
            // or general file/type errors to prevent duplicate delivery or invalid retries.
            if (! $response->successful() && $response->clientError()) {
                $usedMediaId = $payload['image']['id'] ?? ($payload['document']['id'] ?? ($payload['audio']['id'] ?? null));
                $attachment = $message->payload['attachment'] ?? $message->metadata['attachment'] ?? null;
                $fallbackUrl = is_array($attachment) ? ($attachment['url'] ?? null) : null;
                $errorData = $response->json('error') ?? [];
                $errCode = (int) ($errorData['code'] ?? 0);
                $errMsg = strtolower((string) ($errorData['message'] ?? ''));
                $errDetails = strtolower((string) ($errorData['error_data']['details'] ?? ''));
                $combinedDesc = $errMsg.' '.$errDetails;
                $mentionsMediaId = str_contains($combinedDesc, 'media id') || str_contains($combinedDesc, 'media_id');
                $indicatesInvalidOrExpiredMediaId = str_contains($combinedDesc, 'media does not exist')
                    || ($mentionsMediaId && (
                        str_contains($combinedDesc, 'not found')
                        || str_contains($combinedDesc, 'expired')
                        || str_contains($combinedDesc, 'invalid')
                        || str_contains($combinedDesc, 'does not exist')
                    ));

                // Explicitly check that description indicates media ID is invalid, not found, or expired.
                // Exclude 131051 (unsupported message type) and general unsupported media/file errors.
                $isExplicitMediaIdRejection = $errCode !== 131051
                    && ! str_contains($combinedDesc, 'unsupported message type')
                    && ! str_contains($combinedDesc, 'unsupported media')
                    && $indicatesInvalidOrExpiredMediaId;

                if ($usedMediaId && $fallbackUrl && $isExplicitMediaIdRejection) {
                    Log::warning("[WhatsAppChannelAdapter] Meta rechazó explícitamente el media ID por expiración o inexistencia (código {$errCode}). Invalidando caché y reintentando con fallback link.");
                    $this->prewarmService->invalidateMediaId($channel, (string) $usedMediaId);

                    $fallbackPayload = $payload;
                    foreach (['image', 'document', 'audio'] as $mKey) {
                        if (isset($fallbackPayload[$mKey]['id'])) {
                            unset($fallbackPayload[$mKey]['id']);
                            $fallbackPayload[$mKey]['link'] = $fallbackUrl;
                        }
                    }

                    $retryResponse = Http::withToken($accessToken)
                        ->timeout(12)
                        ->post($url, $fallbackPayload);

                    if ($retryResponse->successful()) {
                        Log::info('[WhatsAppChannelAdapter] Reintento de envío con fallback link exitoso tras rechazo de media ID.');
                    }
                    $response = $retryResponse;
                }
            }

            if ($response->successful()) {
                $data = $response->json();
                $metaMsgId = $data['messages'][0]['id'] ?? null;

                $meta = $message->metadata ?? [];
                $updates = ['status' => MessageStatus::SENT];

                if ($metaMsgId) {
                    $meta['meta_message_id'] = $metaMsgId;
                    $meta['wamid'] = $metaMsgId;
                    $meta['delivery_status'] = 'sent';
                    if (empty($meta['sent_at'])) {
                        $meta['sent_at'] = now()->toIso8601String();
                    }

                    $alreadyUsed = Message::where('conversation_id', $message->conversation_id)
                        ->where('external_message_id', $metaMsgId)
                        ->where('id', '!=', $message->id)
                        ->exists();

                    if (! $alreadyUsed) {
                        $updates['external_message_id'] = $metaMsgId;
                    }
                }
                $updates['metadata'] = $meta;

                $message->update($updates);

                broadcast(new MessageStatusUpdated($message));

                return $data;
            }

            $safePayload = $this->redactOutboundPayload($payload);
            $safeResponse = $this->redactResponseBody($response->json() ?? ['raw' => $response->body()]);

            Log::error("[WhatsAppChannelAdapter] Error al enviar mensaje a WhatsApp Graph API: HTTP {$response->status()}", [
                'endpoint' => "graph.facebook.com/{$apiVersion}/[PHONE_NUMBER_ID]/messages",
                'payload' => $safePayload,
                'response' => $safeResponse,
            ]);

            $errorData = $response->json() ?? [];
            $meta = $message->metadata ?? [];
            $meta['delivery_status'] = 'failed';
            $meta['delivery_error'] = [
                'code' => $errorData['error']['code'] ?? $response->status(),
                'title' => self::sanitizeErrorMessage((string) ($errorData['error']['title'] ?? 'Error de entrega')),
                'message' => self::sanitizeErrorMessage((string) ($errorData['error']['message'] ?? 'Error al enviar a WhatsApp')),
            ];
            $message->update([
                'status' => MessageStatus::FAILED,
                'metadata' => $meta,
            ]);

            broadcast(new MessageStatusUpdated($message));

            return $response->json();
        } catch (\Throwable $e) {
            $safeError = self::sanitizeErrorMessage($e->getMessage());
            Log::error("[WhatsAppChannelAdapter] Excepción de red al enviar mensaje a WhatsApp: {$safeError}", [
                'endpoint' => "graph.facebook.com/{$apiVersion}/[PHONE_NUMBER_ID]/messages",
                'message_id' => $message->id,
            ]);

            $meta = $message->metadata ?? [];
            $meta['delivery_status'] = 'failed';
            $meta['delivery_error'] = [
                'code' => 'EXCEPTION',
                'title' => 'Error de conexión',
                'message' => $safeError,
            ];
            $message->update([
                'status' => MessageStatus::FAILED,
                'metadata' => $meta,
            ]);

            broadcast(new MessageStatusUpdated($message));

            return null;
        }
    }

    /**
     * Query approved Meta message templates for the channel directly from Graph API.
     * Only returns templates that Meta explicitly marks with status APPROVED.
     * Never returns fictitious defaults.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getApprovedTemplates(Channel $channel): array
    {
        $config = $channel->configuration ?? [];
        $businessAccountId = (string) ($config['business_account_id'] ?? '');
        $accessToken = (string) ($config['access_token'] ?? '');
        $apiVersion = (string) ($config['api_version'] ?? config('services.meta.graph_version', 'v27.0'));

        if (empty($businessAccountId) || empty($accessToken)) {
            return [];
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(8)
                ->get("https://graph.facebook.com/{$apiVersion}/{$businessAccountId}/message_templates", [
                    'status' => 'APPROVED',
                    'limit' => 100,
                ]);

            if ($response->successful()) {
                $items = $response->json('data') ?? [];
                if (! is_array($items) || empty($items)) {
                    return [];
                }

                // Strictly filter only APPROVED status from Meta
                $approvedItems = array_filter($items, function ($item) {
                    return strtoupper((string) ($item['status'] ?? '')) === 'APPROVED';
                });

                return array_values(array_map(function ($item) {
                    $bodyComponent = collect($item['components'] ?? [])->firstWhere('type', 'BODY');

                    return [
                        'name' => (string) ($item['name'] ?? ''),
                        'status' => 'APPROVED',
                        'category' => (string) ($item['category'] ?? 'UTILITY'),
                        'language' => (string) ($item['language'] ?? 'es'),
                        'components' => $item['components'] ?? [],
                        'text' => (string) ($bodyComponent['text'] ?? ''),
                    ];
                }, $approvedItems));
            }

            Log::warning('[WhatsAppChannelAdapter] Meta Graph API devolvió error al consultar plantillas: HTTP '.$response->status());
        } catch (\Throwable $e) {
            Log::warning('[WhatsAppChannelAdapter] Fallo al consultar plantillas de Graph API: '.self::sanitizeErrorMessage($e->getMessage()));
        }

        return [];
    }

    /**
     * Sanitizes error messages by completely stripping URLs, domains, paths, phone numbers, tokens, and signatures.
     */
    public static function sanitizeErrorMessage(string $message): string
    {
        // 1. Redact full URLs entirely (including scheme, host, path, and query parameters)
        $sanitized = (string) preg_replace('/https?:\/\/[^\s"\'<>]+/i', '[REDACTED_URL]', $message);

        // 2. Redact domain names / hosts like lookaside.fbsbx.com or graph.facebook.com if left bare without scheme
        $sanitized = (string) preg_replace('/(?<![A-Za-z0-9])(?:lookaside\.fbsbx\.com|graph\.facebook\.com|fbcdn\.net|whatsapp\.net|localhost)(?:\/[^\s"\'<>]*)?/i', '[REDACTED_URL]', $sanitized);

        // 3. Redact tokens, secrets, signatures, and credentials
        $sanitized = (string) preg_replace('/(access_token|token|secret|signature|app_secret)=[^&\s]+/i', '$1=[REDACTED]', $sanitized);
        $sanitized = (string) preg_replace('/Bearer\s+[A-Za-z0-9\-_\.]+/i', 'Bearer [REDACTED]', $sanitized);
        $sanitized = (string) preg_replace('/EAAG[A-Za-z0-9]+/i', '[REDACTED_META_TOKEN]', $sanitized);
        $sanitized = (string) preg_replace('/(\?|&)(mid|oh|oe|ea|token|sig|bytestart|byteend)=[^&\s]+/i', '$1$2=[REDACTED]', $sanitized);

        // 4. Redact phone numbers (9 to 15 digits with optional leading +)
        $sanitized = (string) preg_replace('/(?<![A-Za-z0-9])\+?[0-9]{9,15}(?![A-Za-z0-9])/i', '[REDACTED_PHONE]', $sanitized);

        return $sanitized;
    }

    /**
     * Redacts PII and message content from outbound payloads before logging.
     */
    protected function redactOutboundPayload(array $payload): array
    {
        $safe = $payload;

        if (isset($safe['to'])) {
            $to = (string) $safe['to'];
            $safe['to'] = strlen($to) > 4 ? substr($to, 0, 3).'****'.substr($to, -4) : '****';
        }

        if (isset($safe['text']['body'])) {
            $safe['text']['body'] = '[REDACTED_MESSAGE_CONTENT]';
        }

        if (isset($safe['template']['components'])) {
            $safe['template']['components'] = '[REDACTED_TEMPLATE_PARAMS]';
        }

        if (isset($safe['image']['link'])) {
            $safe['image']['link'] = '[REDACTED_MEDIA_URL]';
        }
        if (isset($safe['image']['id'])) {
            $safe['image']['id'] = '[MEDIA_ID]';
        }

        if (isset($safe['document']['link'])) {
            $safe['document']['link'] = '[REDACTED_MEDIA_URL]';
        }
        if (isset($safe['document']['id'])) {
            $safe['document']['id'] = '[MEDIA_ID]';
        }

        if (isset($safe['audio']['link'])) {
            $safe['audio']['link'] = '[REDACTED_MEDIA_URL]';
        }
        if (isset($safe['audio']['id'])) {
            $safe['audio']['id'] = '[MEDIA_ID]';
        }

        return $safe;
    }

    /**
     * Redacts response body from Graph API before logging.
     */
    protected function redactResponseBody(mixed $body): array
    {
        if (! is_array($body)) {
            return ['status' => 'non_array_response'];
        }

        $rawMsg = isset($body['error']['message']) ? (string) $body['error']['message'] : 'Meta error';

        return [
            'error_code' => $body['error']['code'] ?? null,
            'error_subcode' => $body['error']['error_subcode'] ?? null,
            'error_type' => $body['error']['type'] ?? null,
            'error_message' => self::sanitizeErrorMessage($rawMsg),
        ];
    }

    /**
     * Validates whether outbound media URL is public HTTPS and warns if local/insecure without leaking the URL.
     */
    public function validateOutboundMediaUrl(?string $url): array
    {
        if (empty($url)) {
            return ['valid' => false, 'warning' => 'URL vacía'];
        }

        if (str_starts_with($url, 'http://') || str_contains($url, 'localhost') || str_contains($url, '127.0.0.1')) {
            Log::warning('[WhatsAppChannelAdapter] Advertencia: La URL del adjunto saliente no es HTTPS pública ([REDACTED_MEDIA_URL]). Meta requiere URLs HTTPS accesibles desde internet para descargar archivos multimedia.');

            return ['valid' => true, 'is_public_https' => false, 'warning' => 'URL no es HTTPS pública'];
        }

        return ['valid' => true, 'is_public_https' => true];
    }

    /**
     * Resolves the phone number for WhatsApp recipient, removing formatting characters.
     */
    protected function resolveRecipientPhone(?Contact $contact): string
    {
        if (! $contact) {
            return '';
        }

        $raw = $contact->phone ?: ($contact->external_identifier ?: ($contact->metadata['wa_id'] ?? ''));

        // Strip non-numeric characters (except leading numbers)
        return (string) preg_replace('/[^0-9]/', '', (string) $raw);
    }
}
