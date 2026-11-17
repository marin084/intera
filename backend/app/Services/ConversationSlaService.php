<?php

namespace App\Services;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Models\ConversationQueueEntry;
use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ConversationSlaService
{
    /** Add live queue and first-response clocks to the current inbox page in a bounded number of queries. */
    public function decorateInbox(Collection $conversations): void
    {
        if ($conversations->isEmpty()) {
            return;
        }

        $ids = $conversations->pluck('id')->all();
        $entries = ConversationQueueEntry::query()
            ->with('team:id,name,queue_wait_target_seconds,first_response_target_seconds')
            ->whereIn('conversation_id', $ids)
            ->orderByDesc('entered_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('conversation_id')
            ->map(fn (Collection $rows) => $rows->first());

        $entryRows = $entries->filter(fn ($entry) => $entry instanceof ConversationQueueEntry)->values();
        $agentMessages = collect();
        if ($entryRows->isNotEmpty()) {
            $agentMessages = Message::query()
                ->whereIn('sender_type', [MessageSenderType::AGENT->value])
                ->where('direction', MessageDirection::OUTBOUND->value)
                ->whereIn('visibility', ['public', 'external'])
                ->where(function ($query) use ($entryRows) {
                    foreach ($entryRows as $entry) {
                        $query->orWhere(fn ($interval) => $interval
                            ->where('conversation_id', $entry->conversation_id)
                            ->where('sent_at', '>=', $entry->entered_at));
                    }
                })
                ->orderBy('sent_at')
                ->get(['conversation_id', 'sent_at'])
                ->groupBy('conversation_id');
        }

        $now = now();
        foreach ($conversations as $conversation) {
            $entry = $entries->get($conversation->id);
            if (! $entry) {
                $conversation->setAttribute('sla', null);

                continue;
            }

            $team = $entry->team;
            $queueTarget = (int) ($team?->queue_wait_target_seconds ?? config('teams.service_targets.queue_wait_seconds', 120));
            $firstResponseTarget = (int) ($team?->first_response_target_seconds ?? config('teams.service_targets.first_response_seconds', 300));
            $startedAt = Carbon::parse($entry->entered_at);
            $firstHumanResponse = $agentMessages->get($conversation->id, collect())
                ->first(fn ($message) => Carbon::parse($message->sent_at)->greaterThanOrEqualTo($startedAt));
            $responsePending = ! $firstHumanResponse && in_array($conversation->status, [ConversationStatus::WAITING_AGENT, ConversationStatus::ASSIGNED], true);
            $elapsed = max(0, (int) $startedAt->diffInSeconds($firstHumanResponse ? Carbon::parse($firstHumanResponse->sent_at) : $now, false));
            $queueElapsed = $conversation->status === ConversationStatus::WAITING_AGENT
                ? max(0, (int) $startedAt->diffInSeconds($now, false))
                : null;

            $conversation->setAttribute('sla', [
                'team_id' => $entry->team_id,
                'team_name' => $team?->name,
                'queue_wait_seconds' => $queueElapsed,
                'queue_wait_started_at' => $queueElapsed !== null ? $startedAt->toIso8601String() : null,
                'queue_wait_target_seconds' => $queueTarget,
                'queue_wait_overdue' => $queueElapsed !== null && $queueElapsed > $queueTarget,
                'queue_deadline_at' => $startedAt->copy()->addSeconds($queueTarget)->toIso8601String(),
                'first_response_pending' => $responsePending,
                'first_response_seconds' => $firstHumanResponse ? $elapsed : ($responsePending ? $elapsed : null),
                'first_response_started_at' => $responsePending ? $startedAt->toIso8601String() : null,
                'first_response_target_seconds' => $firstResponseTarget,
                'first_response_overdue' => $responsePending && $startedAt->copy()->addSeconds($firstResponseTarget)->lessThan($now),
                'first_response_deadline_at' => $startedAt->copy()->addSeconds($firstResponseTarget)->toIso8601String(),
            ]);
        }
    }
}
