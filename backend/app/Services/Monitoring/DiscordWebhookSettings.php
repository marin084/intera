<?php

namespace App\Services\Monitoring;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Log;
use Throwable;

class DiscordWebhookSettings
{
    private const KEY = 'discord_alert_webhook_url';

    public function isConfigured(): bool
    {
        return SystemSetting::query()->where('key', self::KEY)->exists();
    }

    public function get(): ?string
    {
        try {
            $value = SystemSetting::query()->where('key', self::KEY)->first()?->value;

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        } catch (Throwable $exception) {
            // A database or APP_KEY problem must not leak the encrypted webhook URL into logs.
            Log::warning('discord.settings.read_failed', ['exception' => class_basename($exception)]);

            return null;
        }
    }

    public function save(string $webhookUrl): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => trim($webhookUrl)],
        );
    }

    public function clear(): void
    {
        SystemSetting::query()->where('key', self::KEY)->delete();
    }

    public static function isValidWebhookUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! in_array(strtolower((string) ($parts['host'] ?? '')), ['discord.com', 'discordapp.com'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return false;
        }

        return preg_match('#^/api/webhooks/\d+/[^/]+$#', (string) ($parts['path'] ?? '')) === 1;
    }
}
