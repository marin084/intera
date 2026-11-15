<?php

namespace App\Http\Controllers\Api\V1;

use App\Casts\EncryptedChannelConfiguration;
use App\Enums\ChannelType;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Flow;
use App\Models\WebchatChannelSettings;
use App\Services\AuditLogger;
use App\Services\Runtime\Channels\Adapters\TelegramChannelAdapter;
use App\Services\Runtime\Channels\ChannelAdapterRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChannelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Channel::with(['entryFlow.activeVersion', 'webchatSettings']);
        if (! $user->hasRole('super_admin')) {
            $query->whereIn('channels.id', $user->channels()->select('channels.id'));
        }
        $channels = $query
            ->orderBy('created_at', 'desc')
            ->get();

        if (! $user->hasRole('super_admin') && ! $user->hasAnyPermission(['channels.view', 'channels.manage'])) {
            return response()->json([
                'data' => $channels->map(fn (Channel $channel) => [
                    'id' => (string) $channel->id,
                    'name' => $channel->name,
                    'type' => $channel->type->value,
                    'is_active' => $channel->is_active,
                ]),
            ]);
        }

        // Ensure webchat channels have settings initialized
        foreach ($channels as $channel) {
            if ($channel->type === ChannelType::WEBCHAT && ! $channel->webchatSettings) {
                $channel->setRelation('webchatSettings', $this->createDefaultWebchatSettings($channel));
            }
        }

        return response()->json([
            'data' => $channels->map(fn ($c) => $this->formatChannel($c)),
        ]);
    }

    public function accessOptions(): JsonResponse
    {
        return response()->json([
            'data' => Channel::query()->orderBy('name')->get(['id', 'name', 'type', 'is_active'])
                ->map(fn (Channel $channel) => [
                    'id' => (string) $channel->id,
                    'name' => $channel->name,
                    'type' => $channel->type->value,
                    'is_active' => $channel->is_active,
                ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->sanitizeAllowedOrigins($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(ChannelType::supportedTypes())],
            'is_active' => ['boolean'],
            'entry_flow_id' => ['nullable', 'uuid', 'exists:flows,id'],
            'configuration' => ['nullable', 'array'],
            'configuration.phone_number_id' => ['nullable', 'string', 'max:100'],
            'configuration.business_account_id' => ['nullable', 'string', 'max:100'],
            'configuration.page_id' => ['nullable', 'string', 'regex:/^\d+$/', 'max:100'],
            'configuration.instagram_account_id' => ['nullable', 'string', 'regex:/^\d+$/', 'max:100'],
            'configuration.bot_token' => ['nullable', 'string', 'max:255'],
            'configuration.bot_username' => ['nullable', 'string', 'max:100'],
            'configuration.webhook_secret_token' => ['nullable', 'string', 'max:255'],
            'configuration.app_id' => ['nullable', 'string', 'regex:/^\d+$/', 'max:100'],
            'configuration.access_token' => ['nullable', 'string', 'max:1000'],
            'configuration.page_access_token' => ['nullable', 'string', 'max:1000'],
            'configuration.verify_token' => ['nullable', 'string', 'max:255'],
            'configuration.app_secret' => ['nullable', 'string', 'max:255'],
            'configuration.api_version' => ['nullable', 'string', 'max:20'],
            'configuration.inactivity_enabled' => ['nullable', 'boolean'],
            'configuration.inactivity_warning_enabled' => ['nullable', 'boolean'],
            'configuration.inactivity_warning_minutes' => ['nullable', 'integer', 'min:1'],
            'configuration.inactivity_warning_message' => ['nullable', 'string', 'max:2000'],
            'configuration.inactivity_close_minutes' => ['nullable', 'integer', 'min:1'],
            'configuration.inactivity_close_message' => ['nullable', 'string', 'max:2000'],
            'webchat_settings' => ['nullable', 'array'],
            'webchat_settings.display_name' => ['nullable', 'string', 'max:100'],
            'webchat_settings.allowed_origins' => ['nullable', 'array'],
            'webchat_settings.allowed_origins.*' => ['string', 'regex:/^https?:\/\/[a-zA-Z0-9\-\.]+(:\d+)?$/'],
            'webchat_settings.accent_color' => ['nullable', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'webchat_settings.launcher_position' => ['nullable', 'string', Rule::in(['bottom-right', 'bottom-left'])],
        ], [
            'type.in' => 'Canal no soportado. Tipos válidos: '.implode(', ', ChannelType::supportedTypes()).'.',
            'webchat_settings.allowed_origins.*.regex' => 'Cada origen permitido debe ser un origen web válido sin barra final (ej: https://example.com o http://localhost:3000).',
            'webchat_settings.accent_color.regex' => 'El color de acento debe ser un código hexadecimal válido (#RRGGBB o #RGB).',
        ]);

        if (! empty($validated['entry_flow_id'])) {
            $entryFlow = Flow::find($validated['entry_flow_id']);
            if ($entryFlow) {
                $applicability = $entryFlow->channel_applicability ?? ['webchat'];
                if (! in_array($validated['type'], $applicability, true)) {
                    return response()->json([
                        'message' => "El flujo '{$entryFlow->name}' no es compatible con canales de tipo '{$validated['type']}'. Canales compatibles con este flujo: ".implode(', ', $applicability).'.',
                        'errors' => [
                            'entry_flow_id' => ['El flujo seleccionado solo es aplicable a: '.implode(', ', $applicability).'.'],
                        ],
                    ], 422);
                }
            }
        }

        $channelType = ChannelType::from($validated['type']);
        $this->validateChannelCredentials(
            $channelType,
            $validated['configuration'] ?? [],
            isCreate: true,
            webchatSettingsData: $validated['webchat_settings'] ?? null
        );

        $channel = Channel::create([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'is_active' => $validated['is_active'] ?? true,
            'entry_flow_id' => $validated['entry_flow_id'] ?? null,
            'configuration' => $validated['configuration'] ?? [],
        ]);

        if (! $request->user()->hasRole('super_admin')) {
            $request->user()->channels()->syncWithoutDetaching([$channel->id]);
        }

        if ($channel->type === ChannelType::WEBCHAT) {
            $wsData = $validated['webchat_settings'] ?? [];
            $settings = WebchatChannelSettings::create([
                'channel_id' => $channel->id,
                'public_key' => WebchatChannelSettings::generatePublicKey(),
                'display_name' => $wsData['display_name'] ?? $channel->name,
                'allowed_origins' => array_values(array_unique($wsData['allowed_origins'] ?? ['http://localhost:3000', 'http://localhost:5173'])),
                'accent_color' => $wsData['accent_color'] ?? '#2563EB',
                'launcher_position' => $wsData['launcher_position'] ?? 'bottom-right',
            ]);
            $channel->setRelation('webchatSettings', $settings);
        }

        $channel->load(['entryFlow.activeVersion', 'webchatSettings']);

        AuditLogger::log(
            action: 'channel.create',
            entityType: 'Channel',
            entityId: (string) $channel->id,
            metadata: [
                'name' => $channel->name,
                'type' => $channel->type->value,
                'entry_flow_id' => $channel->entry_flow_id,
            ]
        );

        return response()->json([
            'message' => 'Canal creado exitosamente.',
            'data' => $this->formatChannel($channel),
        ], 201);
    }

    public function show(Channel $channel): JsonResponse
    {
        abort_unless(request()->user()->canAccessChannel((string) $channel->id), 403);
        $channel->loadMissing(['entryFlow.activeVersion', 'webchatSettings']);

        if ($channel->type === ChannelType::WEBCHAT && ! $channel->webchatSettings) {
            $channel->setRelation('webchatSettings', $this->createDefaultWebchatSettings($channel));
        }

        return response()->json([
            'data' => $this->formatChannel($channel),
        ]);
    }

    public function whatsappTemplates(Channel $channel): JsonResponse
    {
        abort_unless(request()->user()->canAccessChannel((string) $channel->id), 403);
        if ($channel->type !== ChannelType::WHATSAPP) {
            return response()->json([
                'message' => 'Este canal no es de tipo WhatsApp.',
            ], 422);
        }

        $adapter = app(ChannelAdapterRegistry::class)->forChannel($channel);
        $templates = method_exists($adapter, 'getApprovedTemplates')
            ? $adapter->getApprovedTemplates($channel)
            : [];

        return response()->json([
            'data' => $templates,
        ]);
    }

    public function setupTelegramWebhook(Channel $channel, Request $request): JsonResponse
    {
        abort_unless($request->user()->canAccessChannel((string) $channel->id), 403);
        if ($channel->type !== ChannelType::TELEGRAM) {
            return response()->json([
                'message' => 'Este canal no es de tipo Telegram.',
            ], 422);
        }

        $botToken = (string) ($channel->configuration['bot_token'] ?? '');
        if (empty($botToken)) {
            return response()->json([
                'message' => 'El canal no tiene configurado un Bot Token de Telegram.',
            ], 422);
        }

        $secretToken = (string) ($channel->configuration['webhook_secret_token']
            ?? ($channel->configuration['verify_token'] ?? ''));
        if (empty($secretToken)) {
            return response()->json([
                'message' => 'El canal no tiene configurado un Webhook Secret Token requerido.',
            ], 422);
        }

        $appUrl = rtrim((string) config('app.url', ''), '/');
        if (! str_starts_with(strtolower($appUrl), 'https://')) {
            return response()->json([
                'message' => 'APP_URL debe utilizar el protocolo HTTPS obligatorio para registrar webhooks en Telegram.',
            ], 422);
        }

        // Webhook URL is strictly pinned to the configured HTTPS host of APP_URL without arbitrary override
        $webhookUrl = "{$appUrl}/api/v1/public/webhooks/telegram/{$channel->id}";

        $adapter = app(ChannelAdapterRegistry::class)->forChannel($channel);
        if ($adapter instanceof TelegramChannelAdapter) {
            $result = $adapter->setWebhook($channel, $webhookUrl, $secretToken);
            if (! empty($result['ok'])) {
                AuditLogger::log(
                    action: 'channel.telegram_webhook_configured',
                    entityType: 'Channel',
                    entityId: (string) $channel->id,
                    metadata: ['url' => $webhookUrl]
                );

                return response()->json([
                    'message' => 'Webhook de Telegram configurado exitosamente.',
                    'data' => [
                        'url' => $webhookUrl,
                        'has_secret' => true,
                    ],
                ]);
            }

            $rawDesc = (string) ($result['description'] ?? 'Error desconocido');
            $safeDesc = TelegramChannelAdapter::sanitizeErrorMessage($rawDesc);

            return response()->json([
                'message' => 'Error configurando webhook en Telegram: '.$safeDesc,
                'data' => ['description' => $safeDesc],
            ], 422);
        }

        return response()->json(['message' => 'Adaptador de Telegram no disponible.'], 500);
    }

    public function getTelegramWebhookInfo(Channel $channel): JsonResponse
    {
        abort_unless(request()->user()->canAccessChannel((string) $channel->id), 403);
        if ($channel->type !== ChannelType::TELEGRAM) {
            return response()->json([
                'message' => 'Este canal no es de tipo Telegram.',
            ], 422);
        }

        $adapter = app(ChannelAdapterRegistry::class)->forChannel($channel);
        if ($adapter instanceof TelegramChannelAdapter) {
            $info = $adapter->getWebhookInfo($channel);
            if (isset($info['description'])) {
                $info['description'] = TelegramChannelAdapter::sanitizeErrorMessage((string) $info['description']);
            }

            return response()->json(['data' => $info]);
        }

        return response()->json(['message' => 'Adaptador de Telegram no disponible.'], 500);
    }

    public function update(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($request->user()->canAccessChannel((string) $channel->id), 403);
        $this->sanitizeAllowedOrigins($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'entry_flow_id' => ['nullable', 'uuid', 'exists:flows,id'],
            'configuration' => ['nullable', 'array'],
            'configuration.phone_number_id' => ['nullable', 'string', 'max:100'],
            'configuration.business_account_id' => ['nullable', 'string', 'max:100'],
            'configuration.page_id' => ['nullable', 'string', 'regex:/^\d+$/', 'max:100'],
            'configuration.instagram_account_id' => ['nullable', 'string', 'regex:/^\d+$/', 'max:100'],
            'configuration.bot_token' => ['nullable', 'string', 'max:255'],
            'configuration.bot_username' => ['nullable', 'string', 'max:100'],
            'configuration.webhook_secret_token' => ['nullable', 'string', 'max:255'],
            'configuration.app_id' => ['nullable', 'string', 'regex:/^\d+$/', 'max:100'],
            'configuration.access_token' => ['nullable', 'string', 'max:1000'],
            'configuration.page_access_token' => ['nullable', 'string', 'max:1000'],
            'configuration.verify_token' => ['nullable', 'string', 'max:255'],
            'configuration.app_secret' => ['nullable', 'string', 'max:255'],
            'configuration.api_version' => ['nullable', 'string', 'max:20'],
            'configuration.inactivity_enabled' => ['nullable', 'boolean'],
            'configuration.inactivity_warning_enabled' => ['nullable', 'boolean'],
            'configuration.inactivity_warning_minutes' => ['nullable', 'integer', 'min:1'],
            'configuration.inactivity_warning_message' => ['nullable', 'string', 'max:2000'],
            'configuration.inactivity_close_minutes' => ['nullable', 'integer', 'min:1'],
            'configuration.inactivity_close_message' => ['nullable', 'string', 'max:2000'],
            'webchat_settings' => ['nullable', 'array'],
            'webchat_settings.display_name' => ['nullable', 'string', 'max:100'],
            'webchat_settings.allowed_origins' => ['nullable', 'array'],
            'webchat_settings.allowed_origins.*' => ['string', 'regex:/^https?:\/\/[a-zA-Z0-9\-\.]+(:\d+)?$/'],
            'webchat_settings.accent_color' => ['nullable', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'webchat_settings.launcher_position' => ['nullable', 'string', Rule::in(['bottom-right', 'bottom-left'])],
        ], [
            'webchat_settings.allowed_origins.*.regex' => 'Cada origen permitido debe ser un origen web válido sin barra final (ej: https://example.com o http://localhost:3000).',
            'webchat_settings.accent_color.regex' => 'El color de acento debe ser un código hexadecimal válido (#RRGGBB o #RGB).',
        ]);

        if (array_key_exists('entry_flow_id', $validated) && ! empty($validated['entry_flow_id'])) {
            $entryFlow = Flow::find($validated['entry_flow_id']);
            $channelType = is_object($channel->type) ? $channel->type->value : $channel->type;
            if ($entryFlow) {
                $applicability = $entryFlow->channel_applicability ?? ['webchat'];
                if (! in_array($channelType, $applicability, true)) {
                    return response()->json([
                        'message' => "El flujo '{$entryFlow->name}' no es compatible con canales de tipo '{$channelType}'. Canales compatibles con este flujo: ".implode(', ', $applicability).'.',
                        'errors' => [
                            'entry_flow_id' => ['El flujo seleccionado solo es aplicable a: '.implode(', ', $applicability).'.'],
                        ],
                    ], 422);
                }
            }
        }

        $previousEntryFlowId = $channel->entry_flow_id;

        // Process configuration updates safely: do not overwrite real credentials with masks or blanks
        $updateData = array_intersect_key($validated, array_flip(['name', 'is_active', 'entry_flow_id']));

        if (array_key_exists('configuration', $validated)) {
            $existingConfig = $channel->configuration ?? [];
            $incomingConfig = $validated['configuration'] ?? [];

            $secretFields = EncryptedChannelConfiguration::SECRET_KEYS;
            foreach ($secretFields as $field) {
                if (array_key_exists($field, $incomingConfig)) {
                    $val = $incomingConfig[$field];
                    // Keep existing secret if mask, asterisks, dots or blank provided
                    if ($val === null
                        || $val === ''
                        || $val === '***REDACTED***'
                        || $val === '[REDACTED]'
                        || str_contains((string) $val, '****')
                        || str_contains((string) $val, '••••')
                        || trim((string) $val) === '') {
                        if (array_key_exists($field, $existingConfig) && ! empty($existingConfig[$field])) {
                            $incomingConfig[$field] = $existingConfig[$field];
                        } else {
                            unset($incomingConfig[$field]);
                        }
                    }
                }
            }

            $updateData['configuration'] = array_merge($existingConfig, $incomingConfig);
        }

        // Validate merged configuration against required channel credentials
        $effectiveConfig = $updateData['configuration'] ?? ($channel->configuration ?? []);
        $this->validateChannelCredentials(
            $channel->type,
            $effectiveConfig,
            isCreate: false,
            webchatSettingsData: $validated['webchat_settings'] ?? null
        );

        $channel->update($updateData);

        if ($channel->type === ChannelType::WEBCHAT && isset($validated['webchat_settings'])) {
            $ws = $channel->webchatSettings ?? $this->createDefaultWebchatSettings($channel);
            $wsData = $validated['webchat_settings'];

            $updatePayload = [];
            if (isset($wsData['display_name'])) {
                $updatePayload['display_name'] = $wsData['display_name'];
            }
            if (isset($wsData['allowed_origins'])) {
                $updatePayload['allowed_origins'] = array_values(array_unique($wsData['allowed_origins']));
            }
            if (array_key_exists('accent_color', $wsData)) {
                $updatePayload['accent_color'] = $wsData['accent_color'];
            }
            if (isset($wsData['launcher_position'])) {
                $updatePayload['launcher_position'] = $wsData['launcher_position'];
            }

            if (! empty($updatePayload)) {
                $ws->update($updatePayload);
                AuditLogger::log(
                    action: 'channel.webchat_settings_update',
                    entityType: 'Channel',
                    entityId: (string) $channel->id,
                    metadata: $updatePayload
                );
            }
        }

        $channel->load(['entryFlow.activeVersion', 'webchatSettings']);

        AuditLogger::log(
            action: 'channel.update',
            entityType: 'Channel',
            entityId: (string) $channel->id,
            metadata: [
                'name' => $channel->name,
                'entry_flow_changed' => array_key_exists('entry_flow_id', $validated) && $previousEntryFlowId !== $channel->entry_flow_id,
                'entry_flow_id' => $channel->entry_flow_id,
            ]
        );

        return response()->json([
            'message' => 'Canal actualizado exitosamente.',
            'data' => $this->formatChannel($channel),
        ]);
    }

    public function updateStatus(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($request->user()->canAccessChannel((string) $channel->id), 403);
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $channel->update(['is_active' => $validated['is_active']]);
        $channel->loadMissing(['entryFlow.activeVersion', 'webchatSettings']);

        AuditLogger::log(
            action: 'channel.status_change',
            entityType: 'Channel',
            entityId: (string) $channel->id,
            metadata: ['is_active' => $channel->is_active]
        );

        return response()->json([
            'message' => 'Estado del canal actualizado correctamente.',
            'data' => $this->formatChannel($channel),
        ]);
    }

    public function regeneratePublicKey(Channel $channel): JsonResponse
    {
        abort_unless(request()->user()->canAccessChannel((string) $channel->id), 403);
        if ($channel->type !== ChannelType::WEBCHAT) {
            return response()->json([
                'message' => 'Solo canales de tipo Webchat pueden regenerar identificador público.',
            ], 422);
        }

        $settings = $channel->webchatSettings ?? $this->createDefaultWebchatSettings($channel);
        $oldKey = $settings->public_key;
        $newKey = WebchatChannelSettings::generatePublicKey();

        $settings->update([
            'public_key' => $newKey,
        ]);

        AuditLogger::log(
            action: 'channel.webchat_key_regenerate',
            entityType: 'Channel',
            entityId: (string) $channel->id,
            metadata: [
                'old_public_key' => $oldKey,
                'new_public_key' => $newKey,
            ]
        );

        $channel->load(['entryFlow.activeVersion', 'webchatSettings']);

        return response()->json([
            'message' => 'Identificador público del widget regenerado exitosamente.',
            'data' => $this->formatChannel($channel),
        ]);
    }

    public function destroy(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($request->user()->canAccessChannel((string) $channel->id), 403);
        // 1. Canal Activo Bloqueado
        if ($channel->is_active) {
            return response()->json([
                'message' => 'No se puede eliminar un canal activo. Desactívalo primero.',
            ], 422);
        }

        // 2. Integridad Referencial: comprobar si tiene conversaciones asociadas
        $conversationsCount = $channel->conversations()->count();
        $force = filter_var($request->input('force', false), FILTER_VALIDATE_BOOLEAN);

        if ($conversationsCount > 0 && ! $force) {
            return response()->json([
                'message' => "El canal tiene {$conversationsCount} conversación(es) asociada(s). Confirma la eliminación forzada.",
                'requires_force' => true,
                'conversations_count' => $conversationsCount,
            ], 422);
        }

        $channelName = $channel->name;
        $channelType = $channel->type instanceof ChannelType ? $channel->type->value : (string) $channel->type;
        $channelId = (string) $channel->id;

        // 3. Limpieza de Recursos y eliminación atómica dentro de transacción
        DB::transaction(function () use ($channel, $force) {
            // Remueve de forma atómica sus webchat_channel_settings
            $channel->webchatSettings()?->delete();

            // Remueve sesiones huérfanas asociadas
            $channel->webchatSessions()->delete();

            // Si force: true, eliminar conversaciones asociadas
            if ($force) {
                $channel->conversations()->delete();
            }

            // Eliminar canal
            $channel->delete();
        });

        // 4. Auditoría
        AuditLogger::log(
            action: 'channel.deleted',
            entityType: 'Channel',
            entityId: $channelId,
            metadata: [
                'name' => $channelName,
                'type' => $channelType,
                'conversations_count' => $conversationsCount,
                'forced' => $force,
            ]
        );

        return response()->json([
            'message' => 'Canal eliminado exitosamente.',
        ]);
    }

    private function createDefaultWebchatSettings(Channel $channel): WebchatChannelSettings
    {
        return WebchatChannelSettings::create([
            'channel_id' => $channel->id,
            'public_key' => WebchatChannelSettings::generatePublicKey(),
            'display_name' => $channel->name,
            'allowed_origins' => ['http://localhost:3000', 'http://localhost:5173'],
            'accent_color' => '#2563EB',
            'launcher_position' => 'bottom-right',
        ]);
    }

    private function formatChannel(Channel $channel): array
    {
        // Sanitize configuration to prevent sensitive credentials exposure
        $config = $channel->configuration ?? [];
        $safeConfig = AuditLogger::sanitize($config);

        // Ensure channel secrets are masked with ***REDACTED*** and expose presence flags
        foreach (EncryptedChannelConfiguration::SECRET_KEYS as $secretKey) {
            if (! empty($config[$secretKey])) {
                $safeConfig[$secretKey] = '***REDACTED***';
                $safeConfig['has_'.$secretKey] = true;
            } else {
                $safeConfig['has_'.$secretKey] = false;
            }
        }

        $appUrl = rtrim(config('app.url', url('/')), '/');
        $channelTypeEnum = $channel->type instanceof ChannelType ? $channel->type : ChannelType::tryFrom((string) $channel->type);
        $webhookUrl = match ($channelTypeEnum) {
            ChannelType::WHATSAPP => "{$appUrl}/api/v1/public/webhooks/whatsapp/{$channel->id}",
            ChannelType::FACEBOOK_MESSENGER => "{$appUrl}/api/v1/public/webhooks/messenger/{$channel->id}",
            ChannelType::INSTAGRAM => "{$appUrl}/api/v1/public/webhooks/instagram/{$channel->id}",
            ChannelType::TELEGRAM => "{$appUrl}/api/v1/public/webhooks/telegram/{$channel->id}",
            default => null,
        };
        $safeConfig['webhook_url'] = $webhookUrl;

        $inactivityDefaults = $channel->getInactivityConfig();
        foreach ($inactivityDefaults as $key => $defaultVal) {
            if (! array_key_exists($key, $safeConfig) || $safeConfig[$key] === null) {
                $safeConfig[$key] = $defaultVal;
            }
        }

        $webchatSettings = null;
        if ($channel->webchatSettings) {
            $webchatSettings = [
                'id' => $channel->webchatSettings->id,
                'public_key' => $channel->webchatSettings->public_key,
                'display_name' => $channel->webchatSettings->display_name,
                'allowed_origins' => $channel->webchatSettings->allowed_origins ?? [],
                'accent_color' => $channel->webchatSettings->accent_color,
                'launcher_position' => $channel->webchatSettings->launcher_position,
                'updated_at' => $channel->webchatSettings->updated_at?->toIso8601String(),
            ];
        }

        return [
            'id' => $channel->id,
            'name' => $channel->name,
            'type' => $channel->type->value,
            'is_active' => $channel->is_active,
            'entry_flow_id' => $channel->entry_flow_id,
            'entry_flow' => $channel->entryFlow ? [
                'id' => $channel->entryFlow->id,
                'name' => $channel->entryFlow->name,
                'status' => $channel->entryFlow->status->value,
                'active_version_id' => $channel->entryFlow->active_version_id,
                'active_version_number' => $channel->entryFlow->activeVersion?->version_number,
            ] : null,
            'configuration' => $safeConfig,
            'webchat_settings' => $webchatSettings,
            'created_at' => $channel->created_at->toIso8601String(),
            'updated_at' => $channel->updated_at->toIso8601String(),
        ];
    }

    /**
     * Normalize origins to protocol://host[:port] stripping any subpaths or trailing slashes.
     */
    protected function sanitizeAllowedOrigins(Request $request): void
    {
        if ($request->has('webchat_settings.allowed_origins') && is_array($request->input('webchat_settings.allowed_origins'))) {
            $cleaned = array_map(function ($origin) {
                $origin = trim((string) $origin);
                $parsed = parse_url($origin);
                if (isset($parsed['scheme']) && isset($parsed['host'])) {
                    $res = strtolower($parsed['scheme']).'://'.strtolower($parsed['host']);
                    if (isset($parsed['port'])) {
                        $res .= ':'.$parsed['port'];
                    }

                    return $res;
                }

                return rtrim($origin, '/');
            }, $request->input('webchat_settings.allowed_origins'));

            $ws = $request->input('webchat_settings');
            $ws['allowed_origins'] = array_values(array_unique(array_filter($cleaned)));
            $request->merge(['webchat_settings' => $ws]);
        }
    }

    /**
     * Validate channel configuration credentials conditionally based on channel type.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>|null  $webchatSettingsData
     *
     * @throws ValidationException
     */
    protected function validateChannelCredentials(
        ChannelType $type,
        array $config,
        bool $isCreate = false,
        ?array $webchatSettingsData = null
    ): void {
        $errors = [];

        switch ($type) {
            case ChannelType::WHATSAPP:
                if (empty($config['phone_number_id'])) {
                    $errors['configuration.phone_number_id'] = ['El Phone Number ID es obligatorio para WhatsApp.'];
                } elseif (! preg_match('/^\d+$/', (string) $config['phone_number_id'])) {
                    $errors['configuration.phone_number_id'] = ['El Phone Number ID debe ser numérico.'];
                }

                if (empty($config['app_id'])) {
                    $errors['configuration.app_id'] = ['El App ID de Meta es obligatorio para WhatsApp.'];
                } elseif (! preg_match('/^\d+$/', (string) $config['app_id'])) {
                    $errors['configuration.app_id'] = ['El App ID debe ser numérico.'];
                }

                if (empty($config['access_token'])) {
                    $errors['configuration.access_token'] = ['El System User Access Token es obligatorio para WhatsApp.'];
                }

                if (empty($config['verify_token'])) {
                    $errors['configuration.verify_token'] = ['El Webhook Verify Token es obligatorio para WhatsApp.'];
                }

                if (empty($config['app_secret'])) {
                    $errors['configuration.app_secret'] = ['El App Secret de Meta es obligatorio para WhatsApp.'];
                }
                break;

            case ChannelType::FACEBOOK_MESSENGER:
                if (empty($config['page_id'])) {
                    $errors['configuration.page_id'] = ['El Page ID es obligatorio para Facebook Messenger.'];
                }

                if (empty($config['access_token']) && empty($config['page_access_token'])) {
                    $errors['configuration.access_token'] = ['El Page Access Token es obligatorio para Facebook Messenger.'];
                }

                if (empty($config['verify_token'])) {
                    $errors['configuration.verify_token'] = ['El Webhook Verify Token es obligatorio para Facebook Messenger.'];
                }

                if (empty($config['app_secret'])) {
                    $errors['configuration.app_secret'] = ['El App Secret de Meta es obligatorio para Facebook Messenger.'];
                }
                break;

            case ChannelType::INSTAGRAM:
                if (empty($config['page_id'])) {
                    $errors['configuration.page_id'] = ['El Facebook Page ID vinculado es obligatorio para Instagram Direct.'];
                }

                if (empty($config['instagram_account_id'])) {
                    $errors['configuration.instagram_account_id'] = ['El Instagram Account ID es obligatorio.'];
                }

                if (empty($config['access_token']) && empty($config['page_access_token'])) {
                    $errors['configuration.access_token'] = ['El Access Token de Meta es obligatorio para Instagram.'];
                }

                if (empty($config['verify_token'])) {
                    $errors['configuration.verify_token'] = ['El Webhook Verify Token es obligatorio para Instagram.'];
                }

                if (empty($config['app_secret'])) {
                    $errors['configuration.app_secret'] = ['El App Secret de Meta es obligatorio para Instagram.'];
                }
                break;

            case ChannelType::TELEGRAM:
                if (empty($config['bot_token'])) {
                    $errors['configuration.bot_token'] = ['El Bot Token es obligatorio para Telegram.'];
                } elseif (! preg_match('/^\d+:[A-Za-z0-9_-]+$/', (string) $config['bot_token'])) {
                    $errors['configuration.bot_token'] = ['El formato del Bot Token no es válido (esperado: <id>:<token> emitido por BotFather).'];
                }

                if (empty($config['webhook_secret_token'])) {
                    $errors['configuration.webhook_secret_token'] = ['El Webhook Secret Token es obligatorio para Telegram.'];
                } elseif (strlen((string) $config['webhook_secret_token']) < 8 || strlen((string) $config['webhook_secret_token']) > 256 || ! preg_match('/^[A-Za-z0-9_-]+$/', (string) $config['webhook_secret_token'])) {
                    $errors['configuration.webhook_secret_token'] = ['El Webhook Secret Token debe contener entre 8 y 256 caracteres alfanuméricos, guiones o guiones bajos (A-Z, a-z, 0-9, _, -).'];
                }
                break;

            case ChannelType::WEBCHAT:
                if ($webchatSettingsData !== null && isset($webchatSettingsData['allowed_origins'])) {
                    if (empty($webchatSettingsData['allowed_origins'])) {
                        $errors['webchat_settings.allowed_origins'] = ['Debe especificar al menos un origen permitido para el Webchat.'];
                    }
                }
                break;
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }
}
