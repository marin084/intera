<?php

namespace App\Services;

use App\Enums\ConversationStatus;
use App\Enums\FlowExecutionStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\ConversationClosed;
use App\Events\ConversationStatusChanged;
use App\Events\ConversationUpdated;
use App\Events\MessageCreated;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\FlowExecution;
use App\Models\Message;
use App\Models\User;
use App\Services\Runtime\ExecutionContext;
use App\Services\Runtime\VariableInterpolator;
use Carbon\Carbon;

class ConversationInactivityService
{
    public function __construct(
        protected VariableInterpolator $interpolator
    ) {
    }

    /**
     * Check active conversations for inactivity across all enabled channels.
     *
     * @return array{checked: int, warned: int, closed: int}
     */
    public function checkInactivity(): array
    {
        $enabledChannels = Channel::where('is_active', true)->get()->filter(function (Channel $channel) {
            return (bool) ($channel->getInactivityConfig()['inactivity_enabled'] ?? true);
        });

        if ($enabledChannels->isEmpty()) {
            return ['checked' => 0, 'warned' => 0, 'closed' => 0];
        }

        $channelIds = $enabledChannels->pluck('id')->all();

        $stats = [
            'checked' => 0,
            'warned' => 0,
            'closed' => 0,
        ];

        Conversation::whereIn('channel_id', $channelIds)
            ->whereIn('status', [
                ConversationStatus::BOT,
                ConversationStatus::WAITING_AGENT,
                ConversationStatus::ASSIGNED,
            ])
            ->where('is_simulation', false)
            ->with(['channel', 'contact', 'assignedUser'])
            ->chunkById(100, function ($conversations) use (&$stats) {
                foreach ($conversations as $conversation) {
                    $stats['checked']++;
                    $result = $this->evaluateConversation($conversation);
                    if ($result === 'warned') {
                        $stats['warned']++;
                    } elseif ($result === 'closed') {
                        $stats['closed']++;
                    }
                }
            });

        return $stats;
    }

    /**
     * Evaluate and apply inactivity rules to a single conversation.
     *
     * @return 'none'|'warned'|'closed'
     */
    public function evaluateConversation(Conversation $conversation): string
    {
        // 1. Status check: must be in an active status
        $status = $conversation->status instanceof ConversationStatus
            ? $conversation->status
            : ConversationStatus::tryFrom((string) $conversation->status);

        if (!in_array($status, [ConversationStatus::BOT, ConversationStatus::WAITING_AGENT, ConversationStatus::ASSIGNED], true)) {
            return 'none';
        }

        // Waiting for a human is not customer inactivity. The conversation can
        // remain in the queue while an agent is unavailable or takes ownership;
        // neither the handoff message nor the queue time should start this timer.
        if ($status === ConversationStatus::WAITING_AGENT) {
            return 'none';
        }

        $channel = $conversation->channel ?? Channel::find($conversation->channel_id);
        if (!$channel) {
            return 'none';
        }

        $config = $channel->getInactivityConfig();
        if (!($config['inactivity_enabled'] ?? true)) {
            return 'none';
        }

        $metadata = $conversation->metadata ?? [];
        $warnedAtRaw = $metadata['inactivity_warned_at'] ?? null;
        $warnedAt = $warnedAtRaw ? Carbon::parse($warnedAtRaw) : null;

        // For an assigned conversation, the timer starts only after the agent
        // has actually replied. Assignment time and the bot handoff message do
        // not count as customer inactivity.
        if ($status === ConversationStatus::ASSIGNED) {
            $activeAssignment = ConversationAssignment::where('conversation_id', $conversation->id)
                ->where('assigned_user_id', $conversation->assigned_user_id)
                ->whereNull('unassigned_at')
                ->orderByDesc('assigned_at')
                ->orderByDesc('id')
                ->first();

            if (!$activeAssignment) {
                return 'none';
            }

            $lastMessage = Message::where('conversation_id', $conversation->id)
                ->where('visibility', '!=', 'internal')
                ->where('direction', MessageDirection::OUTBOUND)
                ->where('sender_type', MessageSenderType::AGENT)
                ->where('sent_at', '>=', $activeAssignment->assigned_at)
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->first();

            if (!$lastMessage) {
                return 'none';
            }

            $inboundAfterAgent = Message::where('conversation_id', $conversation->id)
                ->where('visibility', '!=', 'internal')
                ->where('direction', MessageDirection::INBOUND)
                ->where('sent_at', '>', $lastMessage->sent_at)
                ->exists();

            // The customer has responded after the agent's latest reply. Any
            // previous warning belongs to an older exchange and is reset.
            if ($inboundAfterAgent) {
                if ($warnedAt !== null) {
                    unset($metadata['inactivity_warned_at']);
                    $conversation->update(['metadata' => $metadata]);
                }

                return 'none';
            }

            // A new agent reply starts a fresh inactivity window.
            if ($warnedAt !== null && $lastMessage->sent_at?->gt($warnedAt)) {
                unset($metadata['inactivity_warned_at']);
                $conversation->update(['metadata' => $metadata]);
                $warnedAt = null;
            }
        } else {
            // For bot conversations, only an outbound bot message can start
            // inactivity. An inbound customer message always stops the timer.
            $lastMessage = Message::where('conversation_id', $conversation->id)
                ->where('visibility', '!=', 'internal')
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->first();

            if (!$lastMessage) {
                return 'none';
            }

            $direction = $lastMessage->direction instanceof MessageDirection
                ? $lastMessage->direction
                : MessageDirection::tryFrom((string) $lastMessage->direction);

            if ($direction !== MessageDirection::OUTBOUND) {
                if ($warnedAt !== null && $lastMessage->sent_at?->gt($warnedAt)) {
                    unset($metadata['inactivity_warned_at']);
                    $conversation->update(['metadata' => $metadata]);
                }

                return 'none';
            }

            if ($lastMessage->sender_type !== MessageSenderType::BOT) {
                return 'none';
            }
        }

        $warningEnabled = (bool) ($config['inactivity_warning_enabled'] ?? true);
        $warningMinutes = (int) ($config['inactivity_warning_minutes'] ?? 10);
        $closeMinutes = (int) ($config['inactivity_close_minutes'] ?? 15);

        // If warning phase is disabled, skip Phase 1 completely and evaluate direct Phase 2 close
        if (!$warningEnabled) {
            $elapsedSinceLastOutbound = $lastMessage->sent_at ? abs((int) now()->diffInMinutes($lastMessage->sent_at)) : 0;
            if ($elapsedSinceLastOutbound >= $closeMinutes) {
                $this->executeClose($conversation, $config);
                return 'closed';
            }

            return 'none';
        }

        // FASE 2: Cierre Definitivo (if already warned)
        if ($warnedAt !== null) {
            $priorOutbound = Message::where('conversation_id', $conversation->id)
                ->where('direction', MessageDirection::OUTBOUND)
                ->where('visibility', '!=', 'internal')
                ->where(function ($q) {
                    $q->whereNull('metadata->is_inactivity_warning')
                        ->orWhere('metadata->is_inactivity_warning', false);
                })
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->first();

            $diffMinutes = max(0, $closeMinutes - $warningMinutes);
            $elapsedSinceWarning = abs((int) now()->diffInMinutes($warnedAt));
            $elapsedSincePrior = $priorOutbound && $priorOutbound->sent_at
                ? abs((int) now()->diffInMinutes($priorOutbound->sent_at))
                : null;

            $shouldClose = ($elapsedSinceWarning >= $diffMinutes)
                || ($elapsedSincePrior !== null && $elapsedSincePrior >= $closeMinutes)
                || (abs((int) now()->diffInMinutes($warnedAt)) >= $closeMinutes);

            if ($shouldClose) {
                $this->executeClose($conversation, $config);
                return 'closed';
            }

            return 'none';
        }

        // FASE 1: Advertencia (if not warned yet)
        $elapsedSinceLastOutbound = $lastMessage->sent_at ? abs((int) now()->diffInMinutes($lastMessage->sent_at)) : 0;

        if ($elapsedSinceLastOutbound >= $warningMinutes) {
            $this->executeWarning($conversation, $config);
            return 'warned';
        }

        return 'none';
    }

    protected function executeWarning(Conversation $conversation, array $config): void
    {
        $rawMessage = $config['inactivity_warning_message'] ?? Channel::DEFAULT_INACTIVITY_WARNING_MESSAGE;
        $interpolatedText = $this->interpolate($rawMessage, $conversation);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::BOT,
            'sender_id' => null,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'visibility' => 'public',
            'status' => MessageStatus::SENT,
            'content' => $interpolatedText,
            'payload' => [],
            'metadata' => [
                'is_inactivity_warning' => true,
            ],
            'sent_at' => now(),
        ]);

        $metadata = $conversation->metadata ?? [];
        $metadata['inactivity_warned_at'] = now()->toIso8601String();

        $conversation->update([
            'last_message_at' => now(),
            'metadata' => $metadata,
        ]);

        broadcast(new MessageCreated($message));

        AuditLogger::log(
            action: 'conversation.inactivity_warning',
            entityType: 'Conversation',
            entityId: (string) $conversation->id,
            metadata: [
                'message_id' => $message->id,
                'warned_at' => $metadata['inactivity_warned_at'],
            ]
        );
    }

    protected function executeClose(Conversation $conversation, array $config): void
    {
        $rawMessage = $config['inactivity_close_message'] ?? Channel::DEFAULT_INACTIVITY_CLOSE_MESSAGE;
        $interpolatedText = $this->interpolate($rawMessage, $conversation);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::BOT,
            'sender_id' => null,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'visibility' => 'public',
            'status' => MessageStatus::SENT,
            'content' => $interpolatedText,
            'payload' => [],
            'metadata' => [
                'is_inactivity_close' => true,
            ],
            'sent_at' => now(),
        ]);

        broadcast(new MessageCreated($message));

        $now = now();

        // Cancel any active execution in flow_executions
        FlowExecution::where('conversation_id', $conversation->id)
            ->whereIn('status', [
                FlowExecutionStatus::RUNNING,
                FlowExecutionStatus::WAITING_INPUT,
                FlowExecutionStatus::WAITING_AGENT,
            ])
            ->update([
                'status' => FlowExecutionStatus::CANCELLED,
                'completed_at' => $now,
            ]);

        $previousAssignedUserId = $conversation->assigned_user_id;
        if (!$previousAssignedUserId) {
            $previousAssignedUserId = ConversationAssignment::where('conversation_id', $conversation->id)
                ->whereNull('unassigned_at')
                ->orderByDesc('assigned_at')
                ->orderByDesc('id')
                ->value('assigned_user_id');
        }

        $metadata = $conversation->metadata ?? [];
        $metadata['closed_reason'] = 'inactivity_timeout';
        $metadata['closing_assignment_captured'] = true;
        $metadata['last_assigned_user_id'] = $previousAssignedUserId ? (int) $previousAssignedUserId : null;

        $conversation->update([
            'status' => ConversationStatus::CLOSED,
            'closed_at' => $now,
            'last_message_at' => $now,
            'metadata' => $metadata,
            'assigned_user_id' => null,
        ]);

        app(ConversationSessionDividerService::class)
            ->ensureClosedSessionDivider($conversation, 'inactivity_timeout', $now);

        ConversationAssignment::where('conversation_id', $conversation->id)
            ->whereNull('unassigned_at')
            ->update(['unassigned_at' => $now]);

        $fresh = $conversation->fresh(['channel', 'contact', 'assignedUser', 'assignments']);

        broadcast(new ConversationUpdated($fresh));
        broadcast(new ConversationClosed($fresh));
        broadcast(new ConversationStatusChanged($fresh));

        if ($previousAssignedUserId) {
            $freedAgent = User::find($previousAssignedUserId);
            if ($freedAgent) {
                app(\App\Services\Routing\ConversationAssignmentService::class)->assignWaitingOnQuotaFreed($freedAgent);
            }
        }

        AuditLogger::log(
            action: 'conversation.inactivity_close',
            entityType: 'Conversation',
            entityId: (string) $conversation->id,
            metadata: [
                'message_id' => $message->id,
                'closed_reason' => 'inactivity_timeout',
                'closed_at' => $now->toIso8601String(),
            ]
        );
    }

    public function interpolate(string $template, Conversation $conversation): string
    {
        $contact = $conversation->contact;
        $displayName = $contact?->display_name ?: ($contact?->name ?: '');

        $variables = [
            'nombre' => $displayName,
            'name' => $displayName,
            'visitor_name' => $displayName,
            'var_nombre' => $displayName,
        ];

        $context = new ExecutionContext($variables, $contact);
        $interpolated = $this->interpolator->interpolate($template, $context);

        return $interpolated['text'];
    }
}
