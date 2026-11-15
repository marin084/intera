<?php

namespace App\Services\Media;

use App\Enums\MessageType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AttachmentUploadService
{
    /**
     * Allowed Image MIME types and extensions (Max 5 MB).
     */
    public const ALLOWED_IMAGE_MIMES = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/gif' => ['gif'],
    ];

    /**
     * Allowed Audio MIME types and extensions (Max 16 MB).
     */
    public const ALLOWED_AUDIO_MIMES = [
        'audio/ogg' => ['ogg', 'opus'],
        'application/ogg' => ['ogg', 'opus'],
        'audio/opus' => ['opus', 'ogg'],
        'audio/mpeg' => ['mp3'],
        'audio/mp3' => ['mp3'],
        'audio/mp4' => ['m4a', 'mp4'],
        'audio/x-m4a' => ['m4a'],
        'audio/aac' => ['aac'],
        'audio/wav' => ['wav'],
        'audio/x-wav' => ['wav'],
        'audio/webm' => ['webm'],
        'video/webm' => ['webm'],
        'audio/amr' => ['amr'],
    ];

    /**
     * Allowed Document MIME types and extensions (Max 10 MB).
     */
    public const ALLOWED_DOCUMENT_MIMES = [
        'application/pdf' => ['pdf'],
        'text/plain' => ['txt'],
        'text/csv' => ['csv'],
        'application/msword' => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.ms-powerpoint' => ['ppt'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
    ];

    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;    // 5 MB

    public const MAX_AUDIO_BYTES = 16 * 1024 * 1024;  // 16 MB

    public const MAX_DOCUMENT_BYTES = 10 * 1024 * 1024; // 10 MB

    /**
     * Forbidden MIME types or substrings that can never be accepted (Anti-XSS / Anti-RCE).
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

    protected const FORBIDDEN_EXTENSIONS = [
        'svg', 'html', 'htm', 'phtml', 'php', 'phar', 'exe', 'sh', 'bat', 'cmd', 'js', 'jar',
    ];

    /**
     * Validates and securely stores an uploaded file.
     *
     * @return array{message_type: MessageType, attachment: array{url: string, file_name: string, mime_type: string, size_bytes: int, disk_path: string}}
     *
     * @throws ValidationException
     */
    public function store(UploadedFile $file, string $directory = 'attachments'): array
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'attachment' => ['El archivo subido no es válido o está corrupto.'],
            ]);
        }

        $size = $file->getSize();
        $clientExtension = strtolower($file->getClientOriginalExtension());

        // 1. Strict extension check against blacklisted extensions
        if (in_array($clientExtension, self::FORBIDDEN_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'attachment' => ['Tipo de archivo no permitido por razones de seguridad.'],
            ]);
        }

        // 2. Real MIME type detection using finfo (not client headers)
        $detectedMime = $file->getMimeType() ?: '';
        if (in_array($detectedMime, self::FORBIDDEN_MIMES, true) || str_contains($detectedMime, 'html') || str_contains($detectedMime, 'svg')) {
            throw ValidationException::withMessages([
                'attachment' => ['Formato de archivo peligroso o no soportado.'],
            ]);
        }

        $isImage = array_key_exists($detectedMime, self::ALLOWED_IMAGE_MIMES);
        $isAudio = array_key_exists($detectedMime, self::ALLOWED_AUDIO_MIMES);
        $isDocument = array_key_exists($detectedMime, self::ALLOWED_DOCUMENT_MIMES);

        if (! $isImage && ! $isAudio && ! $isDocument) {
            throw ValidationException::withMessages([
                'attachment' => ['El tipo de archivo no está permitido. Formatos permitidos: JPG, PNG, WEBP, GIF, PDF, TXT, CSV, DOC, DOCX, XLS, XLSX, PPT, PPTX, OGG, OPUS, MP3, M4A, AAC, WAV, WEBM, AMR.'],
            ]);
        }

        // 3. Size limit check
        if ($isImage && $size > self::MAX_IMAGE_BYTES) {
            throw ValidationException::withMessages([
                'attachment' => ['Las imágenes no pueden superar los 5 MB.'],
            ]);
        }

        if ($isAudio && $size > self::MAX_AUDIO_BYTES) {
            throw ValidationException::withMessages([
                'attachment' => ['Los archivos de audio no pueden superar los 16 MB.'],
            ]);
        }

        if ($isDocument && $size > self::MAX_DOCUMENT_BYTES) {
            throw ValidationException::withMessages([
                'attachment' => ['Los documentos no pueden superar los 10 MB.'],
            ]);
        }

        // 4. Validate extension matches the declared MIME
        $allowedExtensions = $isImage
            ? self::ALLOWED_IMAGE_MIMES[$detectedMime]
            : ($isAudio ? self::ALLOWED_AUDIO_MIMES[$detectedMime] : self::ALLOWED_DOCUMENT_MIMES[$detectedMime]);

        $extension = in_array($clientExtension, $allowedExtensions, true)
            ? $clientExtension
            : $allowedExtensions[0];

        // 5. Generate secure, unguessable server-side filename (UUID)
        $secureFileName = (string) Str::uuid().'.'.$extension;
        $cleanOriginalName = self::sanitizeFileName($file->getClientOriginalName() ?: $secureFileName);

        $disk = config('filesystems.media_disk', 'public');
        if (! Storage::disk($disk)) {
            $disk = 'public';
        }

        $folder = trim($directory, '/').'/'.date('Y/m');
        $storedPath = $file->storeAs($folder, $secureFileName, $disk);

        if (! $storedPath) {
            throw new \RuntimeException('No se pudo guardar el archivo en el almacenamiento.');
        }

        $url = Storage::disk($disk)->url($storedPath);

        $normalizedMime = match ($detectedMime) {
            'application/ogg' => 'audio/ogg',
            'video/webm' => 'audio/webm',
            'audio/mp3' => 'audio/mpeg',
            'audio/x-wav' => 'audio/wav',
            default => $detectedMime,
        };

        $messageType = $isImage ? MessageType::IMAGE : ($isAudio ? MessageType::AUDIO : MessageType::FILE);

        return [
            'message_type' => $messageType,
            'attachment' => [
                'url' => $url,
                'file_name' => $cleanOriginalName,
                'mime_type' => $normalizedMime,
                'size_bytes' => $size,
                'disk_path' => $storedPath,
            ],
        ];
    }

    /**
     * Sanitizes original filename to prevent directory traversal and null bytes.
     */
    public static function sanitizeFileName(string $name): string
    {
        // 1. Remove null bytes and control characters
        $clean = str_replace(["\0", "\x00"], '', $name);
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $clean);

        // 2. Remove directory traversal slashes (/ and \)
        $clean = str_replace(['/', '\\'], '', $clean);

        // 3. Remove tags and isolate basename
        $clean = basename(strip_tags($clean));

        // 4. Eliminate consecutive double dots (e.g. ..) to block path traversal
        $clean = preg_replace('/\.\.+/', '.', $clean);

        // 5. Whitelist alphanumeric, dot, hyphen, underscore and spaces
        $clean = preg_replace('/[^\w\.\-\_\s]/u', '', $clean);

        // 6. Trim leading/trailing dots and spaces
        $clean = trim((string) $clean, " .\t\n\r\0\x0B");

        return substr($clean, 0, 200) ?: 'archivo';
    }
}
