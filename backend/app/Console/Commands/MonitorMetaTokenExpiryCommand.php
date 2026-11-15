<?php

namespace App\Console\Commands;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Services\Monitoring\DiscordAlertService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MonitorMetaTokenExpiryCommand extends Command
{
    protected $signature = 'whatsapp:monitor-token-expiry';

    protected $description = 'Consulta con Meta la vigencia de los tokens WhatsApp y avisa antes de que expiren';

    public function handle(DiscordAlertService $alerts): int
    {
        $channels = Channel::query()
            ->where('type', ChannelType::WHATSAPP)
            ->where('is_active', true)
            ->get();

        foreach ($channels as $channel) {
            $this->inspectChannel($channel, $alerts);
        }

        $this->info("Canales WhatsApp revisados: {$channels->count()}.");

        return self::SUCCESS;
    }

    private function inspectChannel(Channel $channel, DiscordAlertService $alerts): void
    {
        $configuration = $channel->configuration ?? [];
        $channelLabel = 'canal WhatsApp '.substr((string) $channel->id, 0, 8);
        $appId = trim((string) ($configuration['app_id'] ?? ''));
        $appSecret = trim((string) ($configuration['app_secret'] ?? ''));
        $accessToken = trim((string) ($configuration['access_token'] ?? ''));
        $graphVersion = trim((string) ($configuration['api_version'] ?? config('services.meta.graph_version', 'v27.0')));

        $missing = [];
        foreach (['app_id' => $appId, 'app_secret' => $appSecret, 'access_token' => $accessToken] as $key => $value) {
            if ($value === '') {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            Log::notice('meta.token_monitor.configuration_incomplete', [
                'channel_id' => $channel->id,
                'missing_fields' => $missing,
            ]);
            $alerts->send(
                'Falta configurar el monitor de token de Meta',
                "El canal {$channelLabel} no se puede revisar todavía. Completa los datos indicados en la configuración WhatsApp del canal.",
                'warning',
                [['name' => 'Campos pendientes', 'value' => implode(', ', $missing)]],
                'meta-token-config:'.$channel->id,
                86400,
            );

            return;
        }

        if (preg_match('/^v\d+\.\d+$/', $graphVersion) !== 1) {
            $this->notifyCheckFailure($channel, $channelLabel, 'La versión de Graph API configurada no tiene el formato esperado.', null, $alerts);

            return;
        }

        try {
            $response = Http::acceptJson()
                ->withoutRedirecting()
                ->withToken($appId.'|'.$appSecret)
                ->timeout(max(1, (int) config('monitoring.meta_token.timeout_seconds', 10)))
                ->get("https://graph.facebook.com/{$graphVersion}/debug_token", [
                    'input_token' => $accessToken,
                ]);
        } catch (Throwable $exception) {
            $this->notifyCheckFailure($channel, $channelLabel, 'No se pudo conectar con Meta para consultar el token.', null, $alerts, class_basename($exception));

            return;
        }

        if (! $response->successful()) {
            $metaError = $response->json('error', []);
            $errorCode = is_array($metaError) && isset($metaError['code']) ? (string) $metaError['code'] : null;
            $this->notifyCheckFailure($channel, $channelLabel, 'Meta rechazó la consulta de vigencia del token.', $response->status(), $alerts, $errorCode);

            return;
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            $this->notifyCheckFailure($channel, $channelLabel, 'Meta respondió sin los datos de vigencia esperados.', $response->status(), $alerts);

            return;
        }

        if (isset($data['app_id']) && (string) $data['app_id'] !== $appId) {
            $alerts->send(
                'El token de WhatsApp pertenece a otra app de Meta',
                "El token configurado para {$channelLabel} no coincide con el App ID indicado en ese canal. Revisa que App ID, App Secret y Access Token sean de la misma app.",
                'critical',
                [
                    ['name' => 'App ID configurado', 'value' => $appId],
                    ['name' => 'App ID reportado por Meta', 'value' => (string) $data['app_id']],
                ],
                'meta-token-app-mismatch:'.$channel->id,
                21600,
            );

            return;
        }

        if (! ($data['is_valid'] ?? false)) {
            $alerts->send(
                'Token de WhatsApp inválido',
                "Meta indica que el token del canal {$channelLabel} ya no es válido. Actualiza el token en la configuración del canal.",
                'critical',
                [],
                'meta-token-invalid:'.$channel->id,
                21600,
            );
            Log::warning('meta.token_monitor.invalid', ['channel_id' => $channel->id]);

            return;
        }

        $this->clearFailureCooldowns($channel);
        $deadlines = [];
        foreach ([
            'expires_at' => 'Vencimiento del token',
            'data_access_expires_at' => 'Vencimiento del acceso a datos',
        ] as $key => $label) {
            $timestamp = (int) ($data[$key] ?? 0);
            if ($timestamp > 0) {
                $deadlines[$key] = ['label' => $label, 'timestamp' => $timestamp];
            }
        }

        if ($deadlines === []) {
            $alerts->send(
                'Meta no reporta vencimiento automático para el token',
                "Meta confirmó que el token del canal {$channelLabel} está vigente y no reportó una fecha de vencimiento automática.",
                'info',
                [['name' => 'Tipo de token', 'value' => (string) ($data['type'] ?? 'No informado')]],
                'meta-token-no-expiry:'.$channel->id,
                2592000,
            );

            return;
        }

        foreach ($deadlines as $deadlineKey => $deadline) {
            $this->notifyDeadline($channel, $channelLabel, $deadlineKey, $deadline['label'], $deadline['timestamp'], (string) ($data['type'] ?? 'No informado'), $alerts);
        }
    }

    private function notifyDeadline(
        Channel $channel,
        string $channelLabel,
        string $deadlineKey,
        string $deadlineLabel,
        int $timestamp,
        string $tokenType,
        DiscordAlertService $alerts,
    ): void {
        $expiresAt = CarbonImmutable::createFromTimestamp($timestamp)->timezone(config('monitoring.meta_token.timezone', 'America/Costa_Rica'));
        $secondsRemaining = $timestamp - now()->timestamp;
        $expiryIdentity = "{$channel->id}:{$deadlineKey}:{$timestamp}";

        if ($secondsRemaining <= 0) {
            $alerts->send(
                'Token de Meta vencido',
                "{$deadlineLabel} del canal {$channelLabel} ya pasó. Reemplaza el token en la configuración WhatsApp.",
                'critical',
                [['name' => 'Fecha reportada', 'value' => $expiresAt->format('Y-m-d H:i T')]],
                'meta-token-expired:'.$expiryIdentity,
                86400,
            );

            return;
        }

        $warningHours = config('monitoring.meta_token.warning_hours', [24, 1]);
        $firstWarningSeconds = max(0, (int) max($warningHours) * 3600);
        if ($secondsRemaining > $firstWarningSeconds) {
            $alerts->send(
                'Vigencia del token de Meta confirmada',
                "Meta confirma que el token del {$channelLabel} sigue válido. Esta es la fecha que reporta Meta; Intera volverá a revisar el estado automáticamente.",
                'info',
                [
                    ['name' => $deadlineLabel, 'value' => $expiresAt->format('Y-m-d H:i T')],
                    ['name' => 'Tiempo restante', 'value' => $this->formatRemaining($secondsRemaining)],
                    ['name' => 'Tipo de token', 'value' => $tokenType],
                ],
                'meta-token-expiry-known:'.$expiryIdentity,
                31536000,
            );

            return;
        }

        foreach ($warningHours as $hours) {
            $hours = (int) $hours;
            if ($hours <= 0 || $secondsRemaining > $hours * 3600) {
                continue;
            }

            $alerts->send(
                $hours <= 1 ? 'Token de Meta vence pronto' : 'Próximo vencimiento de token de Meta',
                "{$deadlineLabel} del canal {$channelLabel} está cerca. Actualiza el token antes de esa hora para evitar interrupciones.",
                $hours <= 1 ? 'critical' : 'warning',
                [
                    ['name' => 'Vence', 'value' => $expiresAt->format('Y-m-d H:i T')],
                    ['name' => 'Tiempo restante', 'value' => $this->formatRemaining($secondsRemaining)],
                    ['name' => 'Tipo de token', 'value' => $tokenType],
                ],
                "meta-token-expiry:{$hours}:{$expiryIdentity}",
                31536000,
            );
        }
    }

    private function formatRemaining(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));
        if ($minutes < 60) {
            return "{$minutes} min";
        }

        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            return sprintf('%dh %02dm', $hours, $minutes % 60);
        }

        return sprintf('%dd %dh', intdiv($hours, 24), $hours % 24);
    }

    private function notifyCheckFailure(
        Channel $channel,
        string $channelLabel,
        string $description,
        ?int $httpStatus,
        DiscordAlertService $alerts,
        ?string $errorCode = null,
    ): void {
        $fields = [];
        if ($httpStatus !== null) {
            $fields[] = ['name' => 'HTTP', 'value' => (string) $httpStatus, 'inline' => true];
        }
        if ($errorCode !== null && $errorCode !== '') {
            $fields[] = ['name' => 'Código Meta / error', 'value' => mb_substr($errorCode, 0, 100), 'inline' => true];
        }

        Log::warning('meta.token_monitor.check_failed', [
            'channel_id' => $channel->id,
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
        ]);
        $alerts->send(
            'No se pudo verificar el token de WhatsApp',
            "Intera no pudo consultar a Meta el vencimiento del token del canal {$channelLabel}. El fallo de consulta no confirma que el token haya expirado.",
            'warning',
            $fields,
            'meta-token-check-failed:'.$channel->id,
            3600,
        );
    }

    private function clearFailureCooldowns(Channel $channel): void
    {
        foreach (['meta-token-invalid:', 'meta-token-check-failed:', 'meta-token-app-mismatch:'] as $prefix) {
            try {
                Cache::forget('discord_alert:'.hash('sha256', $prefix.$channel->id));
            } catch (Throwable) {
                // Cache state is only used to suppress duplicate Discord notices.
            }
        }
    }
}
