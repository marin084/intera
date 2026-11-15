<?php

namespace App\Services\Media;

use App\Enums\ChannelType;
use App\Exceptions\PermanentWhatsAppMediaPrewarmException;
use App\Exceptions\TransientWhatsAppMediaPrewarmException;
use App\Exceptions\WhatsAppMediaPrewarmException;
use App\Jobs\PrewarmWhatsAppMediaJob;
use App\Models\Channel;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\WhatsAppMediaCache;
use App\Services\Runtime\Channels\Adapters\WhatsAppChannelAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WhatsAppMediaPrewarmService
{
    /**
     * Max upload sizes aligned with Meta Cloud API specifications.
     */
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;     // 5 MB

    public const MAX_AUDIO_BYTES = 16 * 1024 * 1024;    // 16 MB

    public const MAX_DOCUMENT_BYTES = 100 * 1024 * 1024; // 100 MB

    /**
     * Meta Cloud API media expiration duration in days.
     * Meta retains uploaded media for 30 days. We use 29 days to renew before hard expiration.
     */
    public const MEDIA_TTL_DAYS = 29;

    /**
     * Generate a fast, stable SHA-256 fingerprint for an asset.
     * Uses O(1) filesystem metadata (size + mtime) for local files to avoid reading file content synchronously;
     * normalizes scheme, host, port, path, and deterministically preserves semantic query parameters
     * while discarding volatile authentication, signature, and expiration query parameters.
     */
    public function generateFingerprint(string $url, ?string $content = null): string
    {
        if ($content !== null && $content !== '') {
            return hash('sha256', $content);
        }

        $relativePath = $this->resolveStoragePathFromUrl($url);
        if ($relativePath && Storage::disk('public')->exists($relativePath)) {
            $fullPath = Storage::disk('public')->path($relativePath);
            if (file_exists($fullPath) && is_readable($fullPath)) {
                $size = @filesize($fullPath) ?: 0;
                $mtime = @filemtime($fullPath) ?: 0;

                return hash('sha256', "local:{$relativePath}:{$size}:{$mtime}");
            }
        }

        $canonical = $this->canonicalizeMediaUrl($url);

        return hash('sha256', 'url:'.$canonical);
    }

    /**
     * Canonicalizes an external media URL by preserving and sorting semantic query parameters
     * while discarding volatile authentication, signature, and expiration parameters
     * specific to AWS S3, Google Cloud Storage, or Azure Blob SAS.
     * Preserves exact casing of semantic keys and supports repeated query parameters.
     * Never stores or logs raw URLs or parameter values.
     */
    public function canonicalizeMediaUrl(string $url): string
    {
        $parsed = parse_url(trim($url));
        if (! isset($parsed['scheme']) || ! isset($parsed['host'])) {
            return trim($url);
        }

        $scheme = strtolower($parsed['scheme']);
        $host = strtolower($parsed['host']);

        $canonical = $scheme.'://'.$host;
        if (isset($parsed['port'])) {
            $canonical .= ':'.$parsed['port'];
        }

        $canonical .= $parsed['path'] ?? '';

        if (empty($parsed['query'])) {
            return $canonical;
        }

        $pairs = explode('&', $parsed['query']);
        $rawParsed = [];
        $lowerKeysMap = [];

        foreach ($pairs as $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $key = urldecode($parts[0]);
            $val = isset($parts[1]) ? urldecode($parts[1]) : '';
            $rawParsed[] = [$key, $val];
            $lowerKeysMap[strtolower(trim($key))] = true;
        }

        if (empty($rawParsed)) {
            return $canonical;
        }

        // Strict DNS host or suffix validation with leading dot to prevent lookalike domains (e.g. storage.googleapis.com.evil.example)
        $isAzureBlobHost = $host === 'blob.core.windows.net'
            || str_ends_with($host, '.blob.core.windows.net')
            || str_ends_with($host, '.blob.core.chinacloudapi.cn')
            || str_ends_with($host, '.blob.core.usgovcloudapi.net');

        $isGcsHost = $host === 'storage.googleapis.com'
            || str_ends_with($host, '.storage.googleapis.com')
            || $host === 'storage.cloud.google.com'
            || str_ends_with($host, '.storage.cloud.google.com');

        $isAwsS3Host = preg_match('/(^|\\.)s3([.-][a-z0-9-]+)*\\.amazonaws\\.com(\\.cn)?$/', $host) === 1;

        // Short Azure SAS and legacy cloud-signature keys are volatile only when the URL
        // has the provider context and a complete signature tuple. Avoid dropping semantic
        // parameters such as `Expires` from ordinary URLs.
        $hasAzureSasSignature = $isAzureBlobHost && isset($lowerKeysMap['sig']);
        $hasLegacyS3Signature = $isAwsS3Host
            && isset($lowerKeysMap['awsaccesskeyid'], $lowerKeysMap['expires'], $lowerKeysMap['signature']);
        $hasLegacyGcsSignature = $isGcsHost
            && isset($lowerKeysMap['googleaccessid'], $lowerKeysMap['expires'], $lowerKeysMap['signature']);

        $azureSasVolatile = ['sp', 'se', 'sv', 'sr', 'sig', 'spr', 'st', 'skoid', 'sktid', 'skt', 'ske', 'sks', 'skv'];
        $awsSignatureParams = [
            'x-amz-algorithm', 'x-amz-credential', 'x-amz-date', 'x-amz-expires',
            'x-amz-signedheaders', 'x-amz-signature', 'x-amz-security-token',
        ];
        $gcsSignatureParams = [
            'x-goog-algorithm', 'x-goog-credential', 'x-goog-date', 'x-goog-expires',
            'x-goog-signedheaders', 'x-goog-signature',
        ];

        $retained = [];

        foreach ($rawParsed as [$key, $val]) {
            $kLower = strtolower(trim($key));

            // AWS Signature V4 params. Keep other x-amz-* keys such as x-amz-version-id,
            // because they may select a different semantic resource.
            if (in_array($kLower, $awsSignatureParams, true)) {
                continue;
            }

            // Google Cloud Storage signed params. Keep other x-goog-* keys, which may
            // affect the resource returned by the URL.
            if (in_array($kLower, $gcsSignatureParams, true)) {
                continue;
            }

            if ($hasLegacyS3Signature && in_array($kLower, ['awsaccesskeyid', 'expires', 'signature'], true)) {
                continue;
            }

            if ($hasLegacyGcsSignature && in_array($kLower, ['googleaccessid', 'expires', 'signature'], true)) {
                continue;
            }

            // Azure SAS short keys are only volatile on Azure Blob hosts when `sig` is present.
            if ($hasAzureSasSignature && in_array($kLower, $azureSasVolatile, true)) {
                continue;
            }

            // Retain parameter preserving EXACT key casing and value
            $retained[] = [$key, $val];
        }

        if (empty($retained)) {
            return $canonical;
        }

        // Deterministically sort parameters: binary comparison by key, then by value
        usort($retained, function ($a, $b) {
            $cmp = strcmp($a[0], $b[0]);

            return $cmp !== 0 ? $cmp : strcmp($a[1], $b[1]);
        });

        $queryParts = array_map(
            fn ($item) => rawurlencode($item[0]).'='.rawurlencode($item[1]),
            $retained
        );

        return $canonical.'?'.implode('&', $queryParts);
    }

    /**
     * Look up a valid, unexpired Meta media ID for the given channel and URL.
     * Returns null on cache miss or expiration (triggering link fallback).
     */
    public function getValidMediaId(Channel $channel, string $url): ?string
    {
        if (empty($url) || $channel->type !== ChannelType::WHATSAPP) {
            return null;
        }

        $fingerprint = $this->generateFingerprint($url);

        /** @var WhatsAppMediaCache|null $cached */
        $cached = WhatsAppMediaCache::validFor($channel->id, $fingerprint)->first();

        if ($cached && $cached->isValid()) {
            Log::info('[WhatsAppMediaPrewarmService] Cache HIT para adjunto WhatsApp', [
                'channel_id' => $channel->id,
                'fingerprint' => substr($fingerprint, 0, 12),
                'expires_at' => $cached->expires_at?->toIso8601String(),
            ]);

            return $cached->media_id;
        }

        Log::info('[WhatsAppMediaPrewarmService] Cache MISS para adjunto WhatsApp', [
            'channel_id' => $channel->id,
            'fingerprint' => substr($fingerprint, 0, 12),
        ]);

        return null;
    }

    /**
     * Upload an asset directly to Meta Graph API and cache the returned media ID.
     * Enforces concurrency locking, channel isolation, and safe logging.
     * Throws TransientWhatsAppMediaPrewarmException on transient failures (network/5xx/429) to enable queue retries.
     * Throws PermanentWhatsAppMediaPrewarmException on non-retryable errors (validation/4xx client errors).
     */
    public function uploadToMeta(
        Channel $channel,
        string $url,
        ?string $filename = null,
        ?string $mimeType = null,
        array $context = []
    ): ?WhatsAppMediaCache {
        if ($channel->type !== ChannelType::WHATSAPP) {
            Log::warning("[WhatsAppMediaPrewarmService] El canal [{$channel->id}] no es de tipo WhatsApp.");

            throw new PermanentWhatsAppMediaPrewarmException("El canal [{$channel->id}] no es de tipo WhatsApp.");
        }

        $config = $channel->configuration ?? [];
        $accessToken = (string) ($config['access_token'] ?? '');
        $phoneNumberId = (string) ($config['phone_number_id'] ?? '');
        $apiVersion = (string) ($config['api_version'] ?? config('services.meta.graph_version', 'v27.0'));

        if (empty($accessToken) || empty($phoneNumberId)) {
            Log::warning("[WhatsAppMediaPrewarmService] Canal [{$channel->id}] carece de credenciales Meta (access_token o phone_number_id).");

            throw new PermanentWhatsAppMediaPrewarmException("Canal [{$channel->id}] carece de credenciales Meta.");
        }

        $fingerprint = $this->generateFingerprint($url);

        // Concurrency lock to avoid parallel duplicate uploads of the same asset for the same channel
        $lockKey = "whatsapp_media_upload_{$channel->id}_{$fingerprint}";
        $lock = Cache::lock($lockKey, 60);

        if (! $lock->get()) {
            // Another worker is currently uploading; wait briefly and re-check cache
            sleep(1);

            $existing = WhatsAppMediaCache::validFor($channel->id, $fingerprint)->first();
            if ($existing && $existing->isValid()) {
                Log::info('[WhatsAppMediaPrewarmService] Cache HIT tras espera de lock concurrente', [
                    'channel_id' => $channel->id,
                    'fingerprint' => substr($fingerprint, 0, 12),
                ]);

                return $existing;
            }

            // Lock is still held by another worker and cache is not yet populated: throw transient exception to retry via queue backoff
            throw new TransientWhatsAppMediaPrewarmException('Recurso bloqueado por otra carga concurrente; reintentando en cola.');
        }

        try {
            // Re-check cache inside lock (in case another worker just completed)
            $existing = WhatsAppMediaCache::validFor($channel->id, $fingerprint)->first();
            if ($existing && $existing->isValid()) {
                Log::info('[WhatsAppMediaPrewarmService] Cache HIT tras adquirir lock', [
                    'channel_id' => $channel->id,
                    'fingerprint' => substr($fingerprint, 0, 12),
                ]);

                return $existing;
            }

            // Resolve file binary and metadata (throws TransientWhatsAppMediaPrewarmException or PermanentWhatsAppMediaPrewarmException on failure)
            $fileData = $this->getFileBinaryAndMetadata($url, $filename, $mimeType);

            $binary = $fileData['binary'];
            $cleanFilename = $fileData['file_name'];
            $resolvedMime = $fileData['mime_type'];
            $size = $fileData['size'];

            // Validate against Meta upload limits and forbidden types
            if (! $this->validateMediaForUpload($resolvedMime, $size)) {
                throw new PermanentWhatsAppMediaPrewarmException('El adjunto no cumple las restricciones de tipo o tamaño de Meta.');
            }

            $endpoint = "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/media";
            $startTime = microtime(true);

            $response = Http::withToken($accessToken)
                ->timeout(25)
                ->attach('file', $binary, $cleanFilename, ['Content-Type' => $resolvedMime])
                ->post($endpoint, [
                    'messaging_product' => 'whatsapp',
                    'type' => $resolvedMime,
                ]);

            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                $mediaId = (string) $response->json('id');
                if (empty($mediaId)) {
                    Log::error('[WhatsAppMediaPrewarmService] Graph API respondió 200 pero sin campo id de media', [
                        'channel_id' => $channel->id,
                        'endpoint' => "graph.facebook.com/{$apiVersion}/[PHONE_NUMBER_ID]/media",
                    ]);

                    throw new TransientWhatsAppMediaPrewarmException('Graph API respondió 200 pero sin campo id de media.');
                }

                $record = WhatsAppMediaCache::updateOrCreate(
                    [
                        'channel_id' => $channel->id,
                        'file_fingerprint' => $fingerprint,
                    ],
                    [
                        'media_id' => $mediaId,
                        'mime_type' => $resolvedMime,
                        'file_name' => $cleanFilename,
                        'file_size' => $size,
                        'uploaded_at' => now(),
                        'expires_at' => now()->addDays(self::MEDIA_TTL_DAYS),
                        'metadata' => array_merge($context, [
                            'upload_duration_ms' => $durationMs,
                            'uploaded_via' => 'multipart',
                            'api_version' => $apiVersion,
                        ]),
                    ]
                );

                Log::info('[WhatsAppMediaPrewarmService] Adjunto pre-cargado exitosamente en Meta Graph API', [
                    'channel_id' => $channel->id,
                    'fingerprint' => substr($fingerprint, 0, 12),
                    'duration_ms' => $durationMs,
                    'file_size_bytes' => $size,
                ]);

                return $record;
            }

            $status = $response->status();
            $safeResponse = WhatsAppChannelAdapter::sanitizeErrorMessage($response->body());
            Log::error("[WhatsAppMediaPrewarmService] Error al subir media a Meta Graph API: HTTP {$status}", [
                'channel_id' => $channel->id,
                'endpoint' => "graph.facebook.com/{$apiVersion}/[PHONE_NUMBER_ID]/media",
                'duration_ms' => $durationMs,
                'response' => $safeResponse,
            ]);

            // Rate limits (429 or code 130429) and Server errors (5xx) are transient -> trigger queue retry
            if ($status === 429 || (int) $response->json('error.code') === 130429 || $response->serverError()) {
                throw new TransientWhatsAppMediaPrewarmException("Error transitorio al subir media a Meta (HTTP {$status}): {$safeResponse}");
            }

            // Client errors (4xx) are permanent -> abort without retrying
            throw new PermanentWhatsAppMediaPrewarmException("Meta Graph API rechazó el medio (HTTP {$status}): {$safeResponse}");
        } catch (WhatsAppMediaPrewarmException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $safeError = WhatsAppChannelAdapter::sanitizeErrorMessage($e->getMessage());
            Log::error("[WhatsAppMediaPrewarmService] Excepción de red al subir media a Meta: {$safeError}", [
                'channel_id' => $channel->id,
            ]);

            throw new TransientWhatsAppMediaPrewarmException("Fallo de red o timeout al subir media a Meta: {$safeError}", previous: $e);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Pre-warm all media assets contained within a FlowVersion across active WhatsApp channels.
     *
     * @return array{channels_count: int, assets_count: int, processed_count: int, successful_count: int}
     */
    public function prewarmVersion(FlowVersion $version, ?Channel $specificChannel = null, bool $sync = false): array
    {
        $version->loadMissing('nodes');
        $flowId = $version->flow_id;

        $flow = $version->flow ?? Flow::find($flowId);
        $applicability = $flow?->channel_applicability;

        // If flow applicability is explicitly specified and does not include whatsapp, do not prewarm
        if (! empty($applicability) && is_array($applicability) && ! in_array('whatsapp', $applicability, true)) {
            Log::info("[WhatsAppMediaPrewarmService] Flujo [{$flowId}] no tiene aplicabilidad para WhatsApp. Omitiendo prewarm.");

            return [
                'channels_count' => 0,
                'assets_count' => 0,
                'processed_count' => 0,
                'successful_count' => 0,
            ];
        }

        /** @var Collection<int, Channel> $channels */
        if ($specificChannel) {
            $channels = collect([$specificChannel])->filter(
                fn (Channel $c) => $c->type === ChannelType::WHATSAPP
                    && $c->is_active
                    && (string) $c->entry_flow_id === (string) $flowId
            );
        } else {
            // Strictly query active WhatsApp channels whose entry_flow_id is exactly this flow
            $channels = Channel::where('type', ChannelType::WHATSAPP)
                ->where('is_active', true)
                ->where('entry_flow_id', $flowId)
                ->get();
        }

        // If no active WhatsApp channel is linked to this flow, do NOT perform any uploads
        if ($channels->isEmpty()) {
            Log::info("[WhatsAppMediaPrewarmService] No hay canales WhatsApp activos enlazados al flujo [{$flowId}]. Omitiendo uploads.");

            return [
                'channels_count' => 0,
                'assets_count' => 0,
                'processed_count' => 0,
                'successful_count' => 0,
            ];
        }

        // Collect all candidate assets from flow nodes (image and file types)
        $assets = [];
        foreach ($version->nodes as $node) {
            $type = is_object($node->type) ? $node->type->value : (string) $node->type;
            if (! in_array($type, ['image', 'file', 'document', 'audio'], true)) {
                continue;
            }

            $config = $node->configuration ?? [];
            $rawUrl = $config['url'] ?? null;

            if (empty($rawUrl) || ! is_string($rawUrl)) {
                continue;
            }

            // Exclude dynamic variable URLs (e.g. {{user_avatar}}) since they can only be resolved per conversation
            if (str_contains($rawUrl, '{{') && str_contains($rawUrl, '}}')) {
                continue;
            }

            $assets[] = [
                'url' => trim($rawUrl),
                'file_name' => $config['file_name'] ?? null,
                'mime_type' => $config['mime_type'] ?? null,
                'node_key' => $node->node_key,
                'node_type' => $type,
            ];
        }

        $processed = 0;
        $successful = 0;

        foreach ($channels as $channel) {
            foreach ($assets as $asset) {
                $processed++;
                $context = [
                    'source' => 'flow_prewarm',
                    'flow_id' => $flowId,
                    'version_id' => $version->id,
                    'node_key' => $asset['node_key'],
                ];

                if ($sync) {
                    try {
                        $result = $this->uploadToMeta(
                            $channel,
                            $asset['url'],
                            $asset['file_name'],
                            $asset['mime_type'],
                            $context
                        );
                        if ($result !== null) {
                            $successful++;
                        }
                    } catch (WhatsAppMediaPrewarmException $e) {
                        // In sync mode, log sanitized error and continue with remaining assets
                        $safeMsg = WhatsAppChannelAdapter::sanitizeErrorMessage($e->getMessage());
                        Log::warning("[WhatsAppMediaPrewarmService] Fallo en prewarm síncrono para activo: {$safeMsg}");
                    }
                } else {
                    // Enqueue job with only channel/flow/version/node references (no URLs serialized into queue)
                    PrewarmWhatsAppMediaJob::dispatch(
                        $channel->id,
                        $flowId,
                        $version->id,
                        $asset['node_key'],
                        $context
                    );
                    $successful++;
                }
            }
        }

        return [
            'channels_count' => $channels->count(),
            'assets_count' => count($assets),
            'processed_count' => $processed,
            'successful_count' => $successful,
        ];
    }

    /**
     * Invalidate a cached media ID (e.g. when Meta reports it expired or deleted).
     * Logs only channel_id and truncated fingerprint, never the raw media ID.
     */
    public function invalidateMediaId(Channel $channel, string $mediaId): void
    {
        $cached = WhatsAppMediaCache::where('channel_id', $channel->id)
            ->where('media_id', $mediaId)
            ->first();

        $fingerprint = $cached?->file_fingerprint;

        WhatsAppMediaCache::where('channel_id', $channel->id)
            ->where('media_id', $mediaId)
            ->delete();

        Log::info('[WhatsAppMediaPrewarmService] Media cache invalidada y removida', [
            'channel_id' => $channel->id,
            'fingerprint' => $fingerprint ? substr($fingerprint, 0, 12) : null,
        ]);
    }

    /**
     * Invalidate a cached entry by file fingerprint.
     */
    public function invalidateFingerprint(Channel $channel, string $fingerprint): void
    {
        WhatsAppMediaCache::where('channel_id', $channel->id)
            ->where('file_fingerprint', $fingerprint)
            ->delete();
    }

    /**
     * Retrieve cache statistics for telemetry and diagnostics.
     */
    public function getStats(?Channel $channel = null): array
    {
        $query = WhatsAppMediaCache::query();
        if ($channel) {
            $query->where('channel_id', $channel->id);
        }

        $total = (clone $query)->count();
        $valid = (clone $query)->where('expires_at', '>', now())->count();
        $expired = $total - $valid;

        return [
            'total_cached' => $total,
            'valid_cached' => $valid,
            'expired_cached' => $expired,
        ];
    }

    /**
     * Resolves storage disk relative path from a URL if it points to local storage.
     */
    public function resolveStoragePathFromUrl(string $url): ?string
    {
        $parsed = parse_url($url, PHP_URL_PATH);
        $path = $parsed ?: $url;

        if (str_contains($path, '/storage/')) {
            $relative = substr($path, strpos($path, '/storage/') + strlen('/storage/'));

            return ltrim($relative, '/');
        }

        if (str_starts_with($path, 'storage/')) {
            return ltrim(substr($path, strlen('storage/')), '/');
        }

        // Direct path inside public disk
        if (Storage::disk('public')->exists(ltrim($path, '/'))) {
            return ltrim($path, '/');
        }

        return null;
    }

    /**
     * Retrieves binary content, filename, MIME type, and size for an asset URL.
     *
     * @return array{binary: string, file_name: string, mime_type: string, size: int, source: string}
     *
     * @throws TransientWhatsAppMediaPrewarmException
     * @throws PermanentWhatsAppMediaPrewarmException
     */
    protected function getFileBinaryAndMetadata(string $url, ?string $filename, ?string $mimeType): array
    {
        // 1. Try local storage first
        $storageRelPath = $this->resolveStoragePathFromUrl($url);
        if ($storageRelPath && Storage::disk('public')->exists($storageRelPath)) {
            $binary = Storage::disk('public')->get($storageRelPath);
            if ($binary !== null && $binary !== '') {
                $fullPath = Storage::disk('public')->path($storageRelPath);
                $name = $filename ?: basename($storageRelPath);
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $detectedMime = $finfo->file($fullPath) ?: ($mimeType ?: 'application/octet-stream');

                return [
                    'binary' => $binary,
                    'file_name' => AttachmentUploadService::sanitizeFileName($name),
                    'mime_type' => $this->normalizeMimeType($detectedMime),
                    'size' => strlen($binary),
                    'source' => 'local_storage',
                ];
            }

            throw new PermanentWhatsAppMediaPrewarmException('El archivo de almacenamiento local está vacío o no es legible.');
        }

        // 2. HTTP/HTTPS URL
        if (filter_var($url, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//i', $url)) {
            if (! $this->isSafeDownloadUrl($url)) {
                Log::warning('[WhatsAppMediaPrewarmService] Descarga bloqueada por validación de URL o protección SSRF');

                throw new PermanentWhatsAppMediaPrewarmException('URL inválida o bloqueada por protección SSRF.');
            }

            try {
                $response = Http::timeout(15)
                    ->withOptions(['allow_redirects' => false])
                    ->get($url);

                $status = $response->status();

                if (! $response->successful()) {
                    Log::warning("[WhatsAppMediaPrewarmService] Falló descarga de URL remota: HTTP {$status}");

                    if ($status === 429 || $response->serverError()) {
                        throw new TransientWhatsAppMediaPrewarmException("Error transitorio al descargar activo de origen: HTTP {$status}");
                    }

                    // 4xx unrecoverable errors (404 Not Found, 403 Forbidden, 401 Unauthorized, etc.)
                    throw new PermanentWhatsAppMediaPrewarmException("Error permanente al descargar activo de origen: HTTP {$status}");
                }

                $binary = (string) $response->body();
                $size = strlen($binary);
                if ($size === 0) {
                    Log::warning('[WhatsAppMediaPrewarmService] El binario descargado de origen está vacío');

                    throw new PermanentWhatsAppMediaPrewarmException('El archivo de origen descargado está vacío.');
                }

                $name = $filename ?: basename(parse_url($url, PHP_URL_PATH) ?: 'archivo');
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $detectedMime = $finfo->buffer($binary) ?: ($mimeType ?: 'application/octet-stream');

                return [
                    'binary' => $binary,
                    'file_name' => AttachmentUploadService::sanitizeFileName($name),
                    'mime_type' => $this->normalizeMimeType($detectedMime),
                    'size' => $size,
                    'source' => 'remote_url',
                ];
            } catch (WhatsAppMediaPrewarmException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $safeError = WhatsAppChannelAdapter::sanitizeErrorMessage($e->getMessage());
                Log::warning("[WhatsAppMediaPrewarmService] Excepción de red al descargar medio remoto: {$safeError}");

                throw new TransientWhatsAppMediaPrewarmException("Fallo de red o timeout al descargar medio de origen: {$safeError}", previous: $e);
            }
        }

        throw new PermanentWhatsAppMediaPrewarmException('URL no válida o no soportada para descarga.');
    }

    /**
     * Validate MIME type against dangerous types and file size against Meta limits.
     */
    protected function validateMediaForUpload(string $mimeType, int $size): bool
    {
        $forbidden = AttachmentUploadService::FORBIDDEN_MIMES;
        if (in_array($mimeType, $forbidden, true) || str_contains($mimeType, 'html') || str_contains($mimeType, 'svg')) {
            Log::warning("[WhatsAppMediaPrewarmService] Tipo MIME peligroso bloqueado: [{$mimeType}]");

            return false;
        }

        if (str_starts_with($mimeType, 'image/')) {
            if ($size > self::MAX_IMAGE_BYTES) {
                Log::warning("[WhatsAppMediaPrewarmService] Imagen excede límite de 5 MB ({$size} bytes).");

                return false;
            }
        } elseif (str_starts_with($mimeType, 'audio/')) {
            if ($size > self::MAX_AUDIO_BYTES) {
                Log::warning("[WhatsAppMediaPrewarmService] Audio excede límite de 16 MB ({$size} bytes).");

                return false;
            }
        } else {
            if ($size > self::MAX_DOCUMENT_BYTES) {
                Log::warning("[WhatsAppMediaPrewarmService] Documento excede límite de 100 MB ({$size} bytes).");

                return false;
            }
        }

        return true;
    }

    /**
     * Normalize equivalent MIME types for Meta compatibility.
     */
    protected function normalizeMimeType(string $mime): string
    {
        return match ($mime) {
            'application/ogg' => 'audio/ogg',
            'video/webm' => 'audio/webm',
            'audio/mp3' => 'audio/mpeg',
            'audio/x-wav' => 'audio/wav',
            default => $mime,
        };
    }

    /**
     * Validates that an external URL does not resolve to private or loopback IP ranges (SSRF protection).
     */
    public function isSafeDownloadUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || empty($host)) {
            return false;
        }

        // Allow localhost and local app url during development/testing
        if (app()->environment('local', 'testing') && in_array(strtolower($host), ['localhost', '127.0.0.1'], true)) {
            return true;
        }

        $ips = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (empty($ips)) {
            $ip = @gethostbyname($host);
            if ($ip && $ip !== $host) {
                $ips = [['ip' => $ip]];
            }
        }

        if (empty($ips)) {
            return false;
        }

        foreach ($ips as $record) {
            $ip = $record['ip'] ?? ($record['ipv6'] ?? null);
            if (! $ip) {
                continue;
            }

            // Reject private, loopback, link-local, and reserved ranges
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }
}
