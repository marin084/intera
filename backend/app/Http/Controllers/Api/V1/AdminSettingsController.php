<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Monitoring\DiscordAlertService;
use App\Services\Monitoring\DiscordWebhookSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    public function show(Request $request, DiscordWebhookSettings $settings): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'data' => ['discord_webhook_configured' => $settings->isConfigured()],
        ]);
    }

    public function updateDiscordWebhook(Request $request, DiscordWebhookSettings $settings): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'webhook_url' => ['required', 'string', 'max:512', 'url'],
        ]);

        $webhookUrl = trim($validated['webhook_url']);
        if (! DiscordWebhookSettings::isValidWebhookUrl($webhookUrl)) {
            return response()->json([
                'message' => 'La URL debe ser un webhook HTTPS válido de discord.com o discordapp.com.',
                'errors' => ['webhook_url' => ['Pega la URL completa copiada desde Integraciones → Webhooks en Discord.']],
            ], 422);
        }

        $settings->save($webhookUrl);

        AuditLogger::log(
            action: 'settings.discord_webhook_updated',
            entityType: 'SystemSetting',
            metadata: ['configured' => true],
        );

        return response()->json([
            'message' => 'Webhook de Discord guardado de forma cifrada.',
            'data' => ['discord_webhook_configured' => true],
        ]);
    }

    public function clearDiscordWebhook(Request $request, DiscordWebhookSettings $settings): JsonResponse
    {
        $this->authorizeAdmin($request);
        $settings->clear();

        AuditLogger::log(
            action: 'settings.discord_webhook_removed',
            entityType: 'SystemSetting',
            metadata: ['configured' => false],
        );

        return response()->json([
            'message' => 'Webhook de Discord eliminado.',
            'data' => ['discord_webhook_configured' => false],
        ]);
    }

    public function testDiscordWebhook(Request $request, DiscordWebhookSettings $settings, DiscordAlertService $alerts): JsonResponse
    {
        $this->authorizeAdmin($request);

        if ($settings->get() === null) {
            return response()->json(['message' => 'Primero guarda una URL de webhook de Discord.'], 422);
        }

        if (! $alerts->send(
            'Prueba de alertas Intera',
            'La conexión entre Intera y Discord funciona correctamente.',
            'success',
        )) {
            return response()->json([
                'message' => 'No se pudo enviar la prueba. Revisa que el webhook siga activo en Discord.',
            ], 502);
        }

        return response()->json(['message' => 'Notificación de prueba enviada a Discord.']);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole(['super_admin', 'admin']), 403, 'Solo administradores pueden modificar estos ajustes.');
    }
}
