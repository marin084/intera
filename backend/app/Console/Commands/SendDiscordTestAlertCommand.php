<?php

namespace App\Console\Commands;

use App\Services\Monitoring\DiscordAlertService;
use App\Services\Monitoring\DiscordWebhookSettings;
use Illuminate\Console\Command;

class SendDiscordTestAlertCommand extends Command
{
    protected $signature = 'discord:test-alert';

    protected $description = 'Envía una notificación de prueba al webhook Discord configurado';

    public function handle(DiscordWebhookSettings $settings, DiscordAlertService $alerts): int
    {
        if ($settings->get() === null) {
            $this->error('Configura primero el webhook en Ajustes del sistema dentro de Intera.');

            return self::FAILURE;
        }

        $sent = $alerts->send(
            'Prueba de alertas Intera',
            'La conexión entre Intera y Discord funciona correctamente.',
            'success',
        );

        if (! $sent) {
            $this->error('Discord no aceptó la notificación. Revisa la URL del webhook y los logs del backend.');

            return self::FAILURE;
        }

        $this->info('Notificación de prueba enviada a Discord.');

        return self::SUCCESS;
    }
}
