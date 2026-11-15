<?php

namespace App\Services\Media;

use App\Models\Channel;
use App\Services\Runtime\Channels\Adapters\WhatsAppChannelAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WhatsAppMediaService
{
    /**
     * Allowed MIME types and standard extensions for images (max 5 MB).
     */
    public const ALLOWED_IMAGE_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /**
     * Allowed MIME types and standard extensions for audio (max 16 MB).
     */
    public const ALLOWED_AUDIO_MIMES = [
        'audio/ogg' => 'ogg',
        'application/ogg' => 'ogg',
        'audio/opus' => 'opus',
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/aac' => 'aac',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/webm' => 'webm',
        'video/webm' => 'webm',
        'audio/amr' => 'amr',
    ];

    /**
     * Allowed MIME types and standard extensions for documents (max 10 MB).
     */
    public const ALLOWED_DOCUMENT_MIMES = [
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    ];

    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;    // 5 MB

    public const MAX_AUDIO_BYTES = 16 * 1024 * 1024;  // 16 MB

    public const MAX_DOCUMENT_BYTES = 10 * 1024 * 1024; // 10 MB

    /**
     * Dangerous MIME types that must be rejected immediately.
     */
    public const FORBIDDEN_MIMES = [
        'image/svg+xml',
        'text/html',
        'application/xhtml+xml',
        'application/x-httpd-php',
        'application/x-sh',
        'application/x-msdos-program',
        'application/javascript',
        'text/javascript',
    ];

    /**
     * Allowed domain suffixes for Meta / WhatsApp media downloads.
     */
    public const ALLOWED_META_DOMAINS = [
        'fbsbx.com',
        'fbcdn.net',
        'facebook.com',
        'whatsapp.net',
        'whatsapp.com',
    ];

    /**
     * Strictly validates a media download URL against SSRF before making any HTTP request.
     * Enforces:
     * 1. Mandatory 'https' scheme.
     * 2. Host belongs to official Meta/Facebook/WhatsApp domains.
     * 3. Host does not resolve to private, loopback, or reserved IP addresses.
     */
    public static function isValidMetaDownloadUrl(?string $url): bool
    {
        if (empty($url) || ! is_string($url)) {
            return false;
        }

        $parts = parse_url($url);
        if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https') {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');
        if (empty($host)) {
            return false;
        }

        // Domain validation against allowed official Meta suffixes
        $isAllowed = false;
        foreach (self::ALLOWED_META_DOMAINS as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                $isAllowed = true;
                break;
            }
        }

        if (! $isAllowed) {
            return false;
        }

        // Prevent SSRF to direct IP addresses or non-FQDNs
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Resolve DNS and ensure no resolved IP is in private or reserved ranges
        $ips = @gethostbynamel($host);
        if (empty($ips) || ! is_array($ips)) {
            // In testing environments without internet, allow valid syntax for known Meta domains
            if (app()->environment('testing') && (str_ends_with($host, '.fbsbx.com') || str_ends_with($host, '.whatsapp.net') || str_ends_with($host, '.facebook.com') || str_ends_with($host, '.fbcdn.net'))) {
                return true;
            }

            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Downloads media from Meta Graph API, validates MIME/size, and stores it in public storage.
     *
     * @return array{url: string, file_name: string, mime_type: string, size_bytes: int, media_id: string, disk_path?: string}|null
     */
    public function downloadAndStoreMedia(Channel $channel, string $mediaId, ?string $originalFilename = null): ?array
    {
        $config = $channel->configuration ?? [];
        $accessToken = (string) ($config['access_token'] ?? '');
        $apiVersion = (string) ($config['api_version'] ?? config('services.meta.graph_version', 'v27.0'));

        if (empty($accessToken) || empty($mediaId)) {
            Log::warning("[WhatsAppMediaService] Canal [{$channel->id}] no tiene access_token configurado o mediaId está vacío.");

            return null;
        }

        try {
            // Paso A: Obtener URL temporal en Meta Graph API
            $metaUrl = "https://graph.facebook.com/{$apiVersion}/{$mediaId}";
            $metaResponse = Http::withToken($accessToken)
                ->withOptions(['allow_redirects' => false])
                ->timeout(10)
                ->get($metaUrl);

            if (! $metaResponse->successful()) {
                Log::warning("[WhatsAppMediaService] Error consultando URL de media en Meta: HTTP {$metaResponse->status()}", [
                    'media_id' => $mediaId,
                ]);

                return null;
            }

            $metaData = $metaResponse->json();
            $downloadUrl = $metaData['url'] ?? null;
            $metaMime = $metaData['mime_type'] ?? null;

            if (empty($downloadUrl) || ! self::isValidMetaDownloadUrl($downloadUrl)) {
                Log::warning("[WhatsAppMediaService] Meta Graph API devolvió URL inválida o potencialmente insegura (SSRF bloqueado) para media_id [{$mediaId}]");

                return null;
            }

            // Paso B: Descargar binario enviando Authorization Bearer
            $binaryResponse = Http::withToken($accessToken)
                // Never follow redirects from a Meta-supplied URL: each hop
                // would need DNS/IP validation before the bearer token is sent.
                ->withOptions(['allow_redirects' => false])
                ->timeout(15)
                ->get($downloadUrl);

            if (! $binaryResponse->successful()) {
                Log::warning("[WhatsAppMediaService] Error descargando binario de Meta para media_id [{$mediaId}]: HTTP {$binaryResponse->status()}");

                return null;
            }

            $binary = (string) $binaryResponse->body();
            $size = strlen($binary);

            if ($size === 0) {
                Log::warning("[WhatsAppMediaService] Se recibió binario vacío para media_id [{$mediaId}]");

                return null;
            }

            // Paso C: Validación y Guardado Seguro
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detectedMime = $finfo->buffer($binary) ?: ($metaMime ?: 'application/octet-stream');

            // Seguridad: verificar tipos prohibidos
            if (in_array($detectedMime, self::FORBIDDEN_MIMES, true) || str_contains($detectedMime, 'html') || str_contains($detectedMime, 'svg')) {
                Log::warning("[WhatsAppMediaService] Tipo MIME peligroso bloqueado: [{$detectedMime}] para media_id [{$mediaId}]");

                return null;
            }

            $isImage = array_key_exists($detectedMime, self::ALLOWED_IMAGE_MIMES);
            $isAudio = array_key_exists($detectedMime, self::ALLOWED_AUDIO_MIMES);
            $isDocument = array_key_exists($detectedMime, self::ALLOWED_DOCUMENT_MIMES);

            // Si finfo detectó un tipo genérico o text/plain para csv, verificar el MIME declarado por Meta
            if (! $isImage && ! $isAudio && ! $isDocument && ! empty($metaMime)) {
                if (array_key_exists($metaMime, self::ALLOWED_IMAGE_MIMES) || array_key_exists($metaMime, self::ALLOWED_AUDIO_MIMES) || array_key_exists($metaMime, self::ALLOWED_DOCUMENT_MIMES)) {
                    $detectedMime = $metaMime;
                    $isImage = array_key_exists($detectedMime, self::ALLOWED_IMAGE_MIMES);
                    $isAudio = array_key_exists($detectedMime, self::ALLOWED_AUDIO_MIMES);
                    $isDocument = array_key_exists($detectedMime, self::ALLOWED_DOCUMENT_MIMES);
                }
            }

            if (! $isImage && ! $isAudio && ! $isDocument) {
                Log::warning("[WhatsAppMediaService] Tipo MIME no permitido: [{$detectedMime}] para media_id [{$mediaId}]");

                return null;
            }

            if ($isImage && $size > self::MAX_IMAGE_BYTES) {
                Log::warning("[WhatsAppMediaService] Imagen supera límite de 5 MB ({$size} bytes) para media_id [{$mediaId}]");

                return null;
            }

            if ($isAudio && $size > self::MAX_AUDIO_BYTES) {
                Log::warning("[WhatsAppMediaService] Audio supera límite de 16 MB ({$size} bytes) para media_id [{$mediaId}]");

                return null;
            }

            if ($isDocument && $size > self::MAX_DOCUMENT_BYTES) {
                Log::warning("[WhatsAppMediaService] Documento supera límite de 10 MB ({$size} bytes) para media_id [{$mediaId}]");

                return null;
            }

            // Determinar extensión física derivada exclusivamente del MIME real validado
            $ext = $isImage
                ? (self::ALLOWED_IMAGE_MIMES[$detectedMime] ?? 'jpg')
                : ($isAudio
                    ? (self::ALLOWED_AUDIO_MIMES[$detectedMime] ?? 'ogg')
                    : (self::ALLOWED_DOCUMENT_MIMES[$detectedMime] ?? 'pdf'));

            $uuid = (string) Str::uuid();
            $path = "attachments/whatsapp/{$uuid}.{$ext}";

            $defaultPrefix = $isImage ? 'imagen' : ($isAudio ? 'audio' : 'documento');
            $sanitizedFilename = AttachmentUploadService::sanitizeFileName(
                $originalFilename ?: "{$defaultPrefix}_{$mediaId}.{$ext}"
            );

            Storage::disk('public')->put($path, $binary);
            $url = Storage::disk('public')->url($path);

            $normalizedMime = match ($detectedMime) {
                'application/ogg' => 'audio/ogg',
                'video/webm' => 'audio/webm',
                'audio/mp3' => 'audio/mpeg',
                'audio/x-wav' => 'audio/wav',
                default => $detectedMime,
            };

            return [
                'url' => $url,
                'file_name' => $sanitizedFilename,
                'mime_type' => $normalizedMime,
                'size_bytes' => $size,
                'media_id' => $mediaId,
                'disk_path' => $path,
            ];
        } catch (\Throwable $e) {
            $safeError = WhatsAppChannelAdapter::sanitizeErrorMessage($e->getMessage());
            Log::warning("[WhatsAppMediaService] Excepción al procesar media [{$mediaId}]: {$safeError}");

            return null;
        }
    }
}
