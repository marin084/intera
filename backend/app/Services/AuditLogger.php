<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    private static array $sensitiveKeys = [
        'password',
        'password_confirmation',
        'token',
        'access_token',
        'refresh_token',
        'visitor_token',
        'secret',
        'reverb_secret',
        'api_key',
        'app_key',
        'verify_token',
        'app_secret',
        'hub_verify_token',
        'authorization',
        'cookie',
        'session_cookie',
        'csrf_token',
        '_token',
    ];

    public static function log(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        array $metadata = [],
        ?int $userId = null,
        ?string $workspaceId = null
    ): AuditLog {
        $user = auth()->user();
        $effectiveUserId = $userId ?? $user?->id;

        $sanitizedMetadata = self::sanitize($metadata);

        return AuditLog::create([
            'workspace_id' => $workspaceId
                ?? (app()->bound('current_workspace_id') ? app('current_workspace_id') : null),
            'user_id' => $effectiveUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => Request::ip(),
            'user_agent' => substr(Request::userAgent() ?? '', 0, 500),
            'metadata' => $sanitizedMetadata,
            'created_at' => now(),
        ]);
    }

    public static function sanitize(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);

            if (in_array($lowerKey, self::$sensitiveKeys, true)) {
                $sanitized[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = self::sanitize($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
