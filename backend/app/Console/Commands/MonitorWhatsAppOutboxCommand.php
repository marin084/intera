<?php

namespace App\Console\Commands;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MonitorWhatsAppOutboxCommand extends Command
{
    protected $signature = 'whatsapp:monitor-outbox {--threshold= : Override stuck-message threshold in seconds}';

    protected $description = 'Emite alertas operativas sobre mensajes de WhatsApp que llevan demasiado tiempo pendientes';

    public function handle(): int
    {
        $configuredThreshold = (int) config('whatsapp.outbox_stuck_after_seconds', 120);
        $thresholdSeconds = max(60, (int) ($this->option('threshold') ?: $configuredThreshold));
        $cutoff = now()->subSeconds($thresholdSeconds);

        $stuckMessages = Message::query()
            ->where('direction', MessageDirection::OUTBOUND)
            ->where('status', MessageStatus::PENDING)
            ->where('created_at', '<=', $cutoff)
            ->whereNull('external_message_id')
            ->where(function ($query) {
                $query->whereNull('metadata->wamid')
                    ->whereNull('metadata->meta_message_id');
            })
            ->where(function ($query) {
                $query->whereNull('metadata->delivery_status')
                    ->orWhere('metadata->delivery_status', 'pending');
            })
            ->where(function ($query) {
                $query->whereNull('visibility')
                    ->orWhereIn('visibility', ['public', 'external']);
            })
            ->whereHas('conversation.channel', fn ($query) => $query->where('type', ChannelType::WHATSAPP))
            ->orderBy('created_at')
            ->limit(100)
            ->get(['id', 'conversation_id', 'message_type', 'created_at']);

        $alertsEmitted = 0;
        foreach ($stuckMessages as $message) {
            $alertKey = "whatsapp_outbox_stuck_alert:{$message->id}";
            if (! Cache::add($alertKey, true, now()->addMinutes(15))) {
                continue;
            }

            $alertsEmitted++;
            Log::critical('whatsapp.outbox.message_stuck', [
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'message_type' => $message->message_type instanceof \BackedEnum
                    ? $message->message_type->value
                    : $message->message_type,
                'age_seconds' => max(0, now()->diffInSeconds($message->created_at, true)),
                'threshold_seconds' => $thresholdSeconds,
            ]);
        }

        $this->info("Mensajes pendientes atascados detectados: {$stuckMessages->count()}; alertas nuevas: {$alertsEmitted}.");

        return self::SUCCESS;
    }
}
