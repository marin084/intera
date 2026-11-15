<?php

namespace App\Console\Commands;

use App\Services\ConversationInactivityService;
use Illuminate\Console\Command;

class CheckConversationInactivityCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'conversations:check-inactivity';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifica y gestiona el auto-cierre de conversaciones inactivas por canal con advertencia previa';

    /**
     * Execute the console command.
     */
    public function handle(ConversationInactivityService $service): int
    {
        $this->info('Verificando inactividad en conversaciones activas...');

        $stats = $service->checkInactivity();

        $this->info("Proceso completado. Evaluadas: {$stats['checked']}, Advertencias: {$stats['warned']}, Cerradas: {$stats['closed']}.");

        return Command::SUCCESS;
    }
}
