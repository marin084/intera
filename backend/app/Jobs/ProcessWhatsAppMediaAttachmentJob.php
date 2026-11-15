<?php

namespace App\Jobs;

use App\Events\ConversationUpdated;
use App\Events\MessageStatusUpdated;
use App\Models\Channel;
use App\Models\Message;
use App\Services\Media\WhatsAppMediaService;
use App\Services\Runtime\Channels\Adapters\WhatsAppChannelAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppMediaAttachmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public array $backoff = [5, 15, 60];

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 60;

    public function __construct(
        public string $messageId,
        public string $channelId
    ) {}

    public function handle(WhatsAppMediaService $mediaService): void
    {
        $message = Message::find($this->messageId);
        if (! $message) {
            Log::warning("[ProcessWhatsAppMediaAttachmentJob] Mensaje [{$this->messageId}] no encontrado. Abortando job.");

            return;
        }

        $payload = $message->payload ?? [];
        $metadata = $message->metadata ?? [];
        $attachment = $payload['attachment'] ?? $metadata['attachment'] ?? null;

        if (! is_array($attachment) || empty($attachment['media_id'])) {
            return;
        }

        // Idempotency: if attachment already has status 'ready' and valid URL, no re-download needed
        if (($attachment['status'] ?? '') === 'ready' && ! empty($attachment['url'])) {
            Log::info("[ProcessWhatsAppMediaAttachmentJob] Adjunto de mensaje [{$this->messageId}] ya fue procesado previamente.");

            return;
        }

        $channel = Channel::find($this->channelId);
        if (! $channel) {
            Log::warning("[ProcessWhatsAppMediaAttachmentJob] Canal [{$this->channelId}] no encontrado para mensaje [{$this->messageId}].");

            return;
        }

        $mediaId = (string) $attachment['media_id'];
        $fileName = (string) ($attachment['file_name'] ?? ('media_' . $mediaId));

        try {
            $downloaded = $mediaService->downloadAndStoreMedia($channel, $mediaId, $fileName);
            if ($downloaded) {
                $mergedAttachment = array_merge($attachment, $downloaded, [
                    'status' => 'ready',
                    'processed_at' => now()->toIso8601String(),
                ]);

                $payload['attachment'] = $mergedAttachment;
                $metadata['attachment'] = $mergedAttachment;

                $message->update([
                    'payload' => $payload,
                    'metadata' => $metadata,
                ]);

                broadcast(new MessageStatusUpdated($message));
                if ($message->conversation) {
                    broadcast(new ConversationUpdated($message->conversation));
                }

                Log::info("[ProcessWhatsAppMediaAttachmentJob] Archivo multimedia [{$mediaId}] procesado exitosamente para mensaje [{$this->messageId}].");
            } else {
                throw new \RuntimeException("Fallo al descargar medio [{$mediaId}] desde Meta Graph API.");
            }
        } catch (\Throwable $e) {
            $safeError = WhatsAppChannelAdapter::sanitizeErrorMessage($e->getMessage());
            Log::warning("[ProcessWhatsAppMediaAttachmentJob] Error procesando medio [{$mediaId}] (intento {$this->attempts()}): {$safeError}");

            if ($this->attempts() >= $this->tries) {
                $failedAttachment = array_merge($attachment, [
                    'status' => 'failed',
                    'download_error' => 'No se pudo descargar el archivo de WhatsApp después de múltiples intentos.',
                    'failed_at' => now()->toIso8601String(),
                ]);

                $payload['attachment'] = $failedAttachment;
                $metadata['attachment'] = $failedAttachment;

                $message->update([
                    'payload' => $payload,
                    'metadata' => $metadata,
                ]);

                broadcast(new MessageStatusUpdated($message));
                if ($message->conversation) {
                    broadcast(new ConversationUpdated($message->conversation));
                }
            }

            // Throw sanitized exception so queue worker logs and failed_jobs do NOT store raw signed URLs or tokens
            throw new \RuntimeException("[ProcessWhatsAppMediaAttachmentJob] Error descargando medio [{$mediaId}]: {$safeError}");
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $safeMsg = $exception ? WhatsAppChannelAdapter::sanitizeErrorMessage($exception->getMessage()) : 'Unknown error';
        Log::error("[ProcessWhatsAppMediaAttachmentJob] Fallo definitivo en descarga de medio para mensaje [{$this->messageId}]: {$safeMsg}");
    }
}
