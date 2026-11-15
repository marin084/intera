<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class DiscordAlertService
{
    public function __construct(private readonly DiscordWebhookSettings $settings)
    {
    }

    /**
     * Send a privacy-safe operational alert to the configured Discord webhook.
     * A dedupe key is hashed before it is used as a cache key.
     *
     * @param  array<int, array{name: string, value: string, inline?: bool}>  $fields
     */
    public function send(
        string $title,
        string $description,
        string $level = 'warning',
        array $fields = [],
        ?string $dedupeKey = null,
        int $cooldownSeconds = 3600,
    ): bool {
        $webhookUrl = $this->settings->get();
        if ($webhookUrl === null) {
            return false;
        }

        if (! DiscordWebhookSettings::isValidWebhookUrl($webhookUrl)) {
            Log::warning('discord.alert.invalid_stored_webhook_configuration');

            return false;
        }

        if ($dedupeKey !== null && ! $this->claimCooldown($dedupeKey, $cooldownSeconds)) {
            return true;
        }

        $colors = [
            'info' => 3447003,
            'success' => 5763719,
            'warning' => 16763904,
            'critical' => 15548997,
        ];

        $payloadFields = [];
        foreach (array_slice($fields, 0, 25) as $field) {
            if (! isset($field['name'], $field['value'])) {
                continue;
            }

            $payloadFields[] = [
                'name' => mb_substr((string) $field['name'], 0, 256),
                'value' => mb_substr((string) $field['value'], 0, 1024),
                'inline' => (bool) ($field['inline'] ?? false),
            ];
        }

        try {
            $response = Http::timeout(max(1, (int) config('monitoring.discord.timeout_seconds', 5)))
                ->withoutRedirecting()
                ->post($webhookUrl, [
                    'username' => 'Intera Alerts',
                    'allowed_mentions' => ['parse' => []],
                    'embeds' => [[
                        'title' => mb_substr($title, 0, 256),
                        'description' => mb_substr($description, 0, 4096),
                        'color' => $colors[$level] ?? $colors['warning'],
                        'fields' => $payloadFields,
                        'timestamp' => now()->toIso8601String(),
                    ]],
                ]);

            if (! $response->successful()) {
                $this->releaseCooldown($dedupeKey);
                Log::warning('discord.alert.delivery_failed', ['http_status' => $response->status()]);

                return false;
            }

            return true;
        } catch (Throwable $exception) {
            // Do not log exception text: network exceptions can contain the secret webhook URL.
            $this->releaseCooldown($dedupeKey);
            Log::warning('discord.alert.delivery_exception', [
                'exception' => class_basename($exception),
            ]);

            return false;
        }
    }

    private function claimCooldown(string $dedupeKey, int $cooldownSeconds): bool
    {
        $cacheKey = 'discord_alert:'.hash('sha256', $dedupeKey);

        try {
            return Cache::add($cacheKey, true, now()->addSeconds(max(1, $cooldownSeconds)));
        } catch (Throwable $exception) {
            // Alert delivery should still work if Redis/cache is temporarily unavailable.
            Log::warning('discord.alert.dedupe_unavailable', ['exception' => class_basename($exception)]);

            return true;
        }
    }

    private function releaseCooldown(?string $dedupeKey): void
    {
        if ($dedupeKey === null) {
            return;
        }

        try {
            Cache::forget('discord_alert:'.hash('sha256', $dedupeKey));
        } catch (Throwable) {
            // Cache state is only used to suppress duplicate Discord notices.
        }
    }

}
