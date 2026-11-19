<?php

namespace App\Services;

use App\Enums\ConversationStatus;
use App\Enums\FlowExecutionStatus;
use App\Events\ConversationAssigned;
use App\Events\ConversationClosed;
use App\Events\ConversationStatusChanged;
use App\Exceptions\ConversationConflictException;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\FlowExecution;
use App\Models\User;
use App\Services\Routing\ConversationAssignmentService;
use App\Services\Routing\ConversationQueueService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ConversationLifecycleService
{
    /**
     * Claim an unassigned waiting_agent conversation atomically.
     */
    public function claim(Conversation $conversation, User $user): Conversation
    {
        return DB::transaction(function () use ($conversation, $user) {
            /** @var Conversation $locked */
            $locked = Conversation::where('id', $conversation->id)->lockForUpdate()->firstOrFail();

            $status = $locked->status instanceof ConversationStatus ? $locked->status : ConversationStatus::tryFrom((string) $locked->status);

            if ($status !== ConversationStatus::WAITING_AGENT || $locked->assigned_user_id !== null) {
                throw new ConversationConflictException(
                    message: 'Esta conversación ya fue tomada por otro agente.',
                    errorCode: 'conversation_already_claimed'
                );
            }

            if (! $user->canAccessChannel((string) $locked->channel_id)
                || ! $locked->team_id
                || ! $user->belongsToTeam((string) $locked->team_id)) {
                throw new AccessDeniedHttpException('No perteneces al equipo o no tienes acceso al canal de esta conversación.');
            }

            // A manual claim is an explicit action by the agent. The role capacity
            // controls automatic routing only, so a zero auto-assignment capacity
            // must not prevent the agent from taking a conversation intentionally.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $locked->update([
                'assigned_user_id' => $user->id,
                'status' => ConversationStatus::ASSIGNED,
                'queued_at' => null,
            ]);
            app(ConversationQueueService::class)->leave($locked->id, 'assigned', (int) $user->id);

            ConversationAssignment::create([
                'conversation_id' => $locked->id,
                'assigned_user_id' => $user->id,
                'assigned_by_user_id' => $user->id,
                'assigned_at' => now(),
                'reason' => 'Claimed by agent',
            ]);

            $fresh = $locked->fresh(['assignedUser', 'channel', 'contact', 'assignments']);

            DB::afterCommit(function () use ($fresh, $user) {
                broadcast(new ConversationAssigned($fresh, $user, 'Tomada por el agente'));
                broadcast(new ConversationStatusChanged($fresh));
            });

            AuditLogger::log(
                action: 'conversation.claim',
                entityType: 'Conversation',
                entityId: (string) $locked->id,
                metadata: [
                    'assigned_user_id' => $user->id,
                    'claimed_by' => $user->id,
                ]
            );

            return $fresh;
        });
    }

    /**
     * Manually assign or unassign a conversation.
     */
    public function assign(
        Conversation $conversation,
        ?User $assignedUser,
        User $assignedBy,
        ?string $reason = null
    ): Conversation {
        return DB::transaction(function () use ($conversation, $assignedUser, $assignedBy, $reason) {
            /** @var Conversation $locked */
            $locked = Conversation::where('id', $conversation->id)->lockForUpdate()->firstOrFail();

            $currentStatus = $locked->status instanceof ConversationStatus ? $locked->status : ConversationStatus::tryFrom((string) $locked->status);

            if ($currentStatus === ConversationStatus::CLOSED) {
                throw ValidationException::withMessages([
                    'conversation' => 'No se puede asignar una conversación cerrada.',
                ]);
            }

            $previousAssignedUserId = $locked->assigned_user_id;
            $isReassignment = $previousAssignedUserId !== null && $assignedUser !== null && $previousAssignedUserId !== $assignedUser->id;

            if ($assignedUser) {
                $team = $locked->team_id ? \App\Models\Team::find($locked->team_id) : null;
                $team ??= app(ConversationQueueService::class)->teamForChannel((string) $locked->channel_id);
                if ((string) $locked->team_id !== (string) $team->id) {
                    $locked->team_id = $team->id;
                    $locked->save();
                }

                $lockedAssignedUser = User::query()->whereKey($assignedUser->id)->lockForUpdate()->firstOrFail();
                if (! $lockedAssignedUser->is_active
                    || ! $lockedAssignedUser->canAccessChannel((string) $locked->channel_id)
                    || ! $lockedAssignedUser->belongsToTeam((string) $team->id)) {
                    throw ValidationException::withMessages(['assigned_user_id' => 'La persona seleccionada no pertenece al equipo o no tiene acceso al canal.']);
                }
                if ((int) $previousAssignedUserId !== (int) $assignedUser->id
                    && ! app(ConversationAssignmentService::class)->canAcceptMoreConversations($lockedAssignedUser, (string) $locked->id)) {
                    throw ValidationException::withMessages(['capacity' => 'La persona seleccionada ya alcanzó su capacidad de conversaciones activas.']);
                }
            }

            // Measure human wait from the handoff, not from the bot conversation start.
            if ($currentStatus !== ConversationStatus::WAITING_AGENT && $assignedUser && $locked->assigned_user_id === null) {
                $locked->update(['status' => ConversationStatus::WAITING_AGENT]);
                $locked = app(ConversationQueueService::class)->enter($locked) ?? $locked;
            }

            // Close existing active assignment
            ConversationAssignment::where('conversation_id', $locked->id)
                ->whereNull('unassigned_at')
                ->update(['unassigned_at' => now()]);

            if ($assignedUser) {
                ConversationAssignment::create([
                    'conversation_id' => $locked->id,
                    'assigned_user_id' => $assignedUser->id,
                    'assigned_by_user_id' => $assignedBy->id,
                    'assigned_at' => now(),
                    'reason' => $reason,
                ]);

                $locked->update([
                    'assigned_user_id' => $assignedUser->id,
                    'status' => ConversationStatus::ASSIGNED,
                    'queued_at' => null,
                ]);
                app(ConversationQueueService::class)->leave($locked->id, 'assigned', (int) $assignedUser->id);
            } else {
                $locked->update([
                    'assigned_user_id' => null,
                    'status' => ConversationStatus::WAITING_AGENT,
                ]);
                app(ConversationQueueService::class)->enter($locked);
            }

            $fresh = $locked->fresh(['assignedUser', 'channel', 'contact', 'assignments']);

            DB::afterCommit(function () use ($fresh, $assignedUser, $reason) {
                broadcast(new ConversationAssigned($fresh, $assignedUser, $reason));
                broadcast(new ConversationStatusChanged($fresh));
            });

            AuditLogger::log(
                action: $isReassignment ? 'conversation.reassign' : 'conversation.assign',
                entityType: 'Conversation',
                entityId: (string) $locked->id,
                metadata: [
                    'assigned_user_id' => $assignedUser?->id,
                    'previous_assigned_user_id' => $previousAssignedUserId,
                    'assigned_by' => $assignedBy->id,
                    'reason' => $reason,
                ]
            );

            return $fresh;
        });
    }

    /**
     * Reassign a conversation from one agent to another.
     */
    public function reassign(
        Conversation $conversation,
        User $newAssignedUser,
        User $reassignedBy,
        ?string $reason = null
    ): Conversation {
        return $this->assign($conversation, $newAssignedUser, $reassignedBy, $reason);
    }

    /**
     * Close a conversation manually.
     */
    public function close(Conversation $conversation, User $closedBy): Conversation
    {
        return DB::transaction(function () use ($conversation, $closedBy) {
            /** @var Conversation $locked */
            $locked = Conversation::where('id', $conversation->id)->lockForUpdate()->firstOrFail();

            $status = $locked->status instanceof ConversationStatus ? $locked->status : ConversationStatus::tryFrom((string) $locked->status);

            // Idempotency: if already closed, return cleanly without duplicating events or audits
            if ($status === ConversationStatus::CLOSED) {
                if ($locked->assigned_user_id !== null) {
                    $metadata = $locked->metadata ?? [];
                    $metadata['closing_assignment_captured'] = true;
                    $metadata['last_assigned_user_id'] = (int) $locked->assigned_user_id;

                    $locked->update([
                        'assigned_user_id' => null,
                        'metadata' => $metadata,
                    ]);
                }
                ConversationAssignment::where('conversation_id', $locked->id)
                    ->whereNull('unassigned_at')
                    ->update(['unassigned_at' => now()]);
                app(ConversationQueueService::class)->leave($locked->id, 'closed');

                return $locked->fresh(['assignedUser', 'channel', 'contact', 'assignments']);
            }

            $isSupervisorOrAdmin = $closedBy->hasPermission('conversations.view_all');
            $isAssignedAgent = (int) $locked->assigned_user_id === (int) $closedBy->id;

            // Enforce authorization policy:
            // Agent can only close if assigned to them.
            // Supervisor/Admin can close waiting_agent or assigned.
            if (! $isSupervisorOrAdmin && ! $isAssignedAgent) {
                throw new AccessDeniedHttpException('Forbidden. No tienes permiso para cerrar esta conversación.');
            }

            if ($status === ConversationStatus::WAITING_AGENT && ! $isSupervisorOrAdmin) {
                throw new AccessDeniedHttpException('Forbidden. Solo un supervisor puede cerrar una conversación en espera de agente.');
            }

            if (! in_array($status, [ConversationStatus::ASSIGNED, ConversationStatus::WAITING_AGENT], true)) {
                throw ValidationException::withMessages([
                    'status' => "No se puede cerrar una conversación con estado [{$status?->value}].",
                ]);
            }

            $previousStatus = $status->value;
            $previousAssignedUserId = $locked->assigned_user_id;
            if (! $previousAssignedUserId) {
                $previousAssignedUserId = ConversationAssignment::where('conversation_id', $locked->id)
                    ->whereNull('unassigned_at')
                    ->orderByDesc('assigned_at')
                    ->orderByDesc('id')
                    ->value('assigned_user_id');
            }
            $now = now();

            $metadata = $locked->metadata ?? [];
            $metadata['closing_assignment_captured'] = true;
            $metadata['last_assigned_user_id'] = $previousAssignedUserId ? (int) $previousAssignedUserId : null;

            $locked->update([
                'status' => ConversationStatus::CLOSED,
                'closed_at' => $now,
                'assigned_user_id' => null,
                'queued_at' => null,
                'metadata' => $metadata,
            ]);
            app(ConversationQueueService::class)->leave($locked->id, 'closed');

            app(ConversationSessionDividerService::class)
                ->ensureClosedSessionDivider($locked, 'agent_close', $now);

            // Close any active assignment
            ConversationAssignment::where('conversation_id', $locked->id)
                ->whereNull('unassigned_at')
                ->update(['unassigned_at' => $now]);

            // Cancel any active flow execution (running, waiting_input, waiting_agent)
            FlowExecution::where('conversation_id', $locked->id)
                ->whereIn('status', [
                    FlowExecutionStatus::RUNNING,
                    FlowExecutionStatus::WAITING_INPUT,
                    FlowExecutionStatus::WAITING_AGENT,
                ])
                ->update([
                    'status' => FlowExecutionStatus::CANCELLED,
                    'completed_at' => $now,
                ]);

            $fresh = $locked->fresh(['assignedUser', 'channel', 'contact', 'assignments']);
            $freedAgent = $previousAssignedUserId ? User::find($previousAssignedUserId) : null;

            DB::afterCommit(function () use ($fresh, $freedAgent) {
                broadcast(new ConversationClosed($fresh));
                broadcast(new ConversationStatusChanged($fresh));
                if ($freedAgent) {
                    app(\App\Services\Routing\ConversationAssignmentService::class)->assignWaitingOnQuotaFreed($freedAgent);
                }
            });

            AuditLogger::log(
                action: 'conversation.closed',
                entityType: 'Conversation',
                entityId: (string) $locked->id,
                metadata: [
                    'closed_by' => $closedBy->id,
                    'previous_status' => $previousStatus,
                    'assigned_user_id' => $previousAssignedUserId,
                    'closed_at' => $now->toIso8601String(),
                ]
            );

            return $fresh;
        });
    }
}
