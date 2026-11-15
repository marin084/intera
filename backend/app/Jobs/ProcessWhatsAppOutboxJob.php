<?php

namespace App\Jobs;

use App\Services\Runtime\Channels\WhatsAppOutboxService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessWhatsAppOutboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 30;

    public function __construct(
        public string $conversationId
    ) {}

    public function handle(WhatsAppOutboxService $outboxService): void
    {
        $outboxService->processNextOutbound($this->conversationId);
    }
}
