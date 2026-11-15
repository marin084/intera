<?php

namespace App\Console\Commands;

use App\Models\WebchatSession;
use Illuminate\Console\Command;

class PruneWebchatSessionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'webchat:sessions:prune {--days= : Override expired threshold in days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune or delete expired webchat sessions';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = $this->option('days');
        $query = WebchatSession::query();

        if ($days !== null) {
            $threshold = now()->subDays((int) $days);
            $query->where('expires_at', '<', $threshold);
        } else {
            $query->where('expires_at', '<', now());
        }

        $count = $query->delete();

        $this->info("Se eliminaron {$count} sesiones de webchat expiradas.");

        return Command::SUCCESS;
    }
}
