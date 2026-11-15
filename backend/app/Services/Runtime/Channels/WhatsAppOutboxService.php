<?php

namespace App\Services\Runtime\Channels;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\FlowExecutionStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageStatus;
use App\Events\ConversationUpdated;
use App\Events\ConversationStatusChanged;
use App\Events\MessageStatusUpdated;
use App\Jobs\ProcessWhatsAppOutboxJob;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\FlowExecution;
use App\Models\Message;
use App\Services\Routing\ConversationAssignmentService;
use App\Services\Runtime\Channels\Adapters\WhatsAppChannelAdapter;
use App\Services\Runtime\FlowResolver;
use App\Services\Runtime\FlowRuntimeEngine;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WhatsAppOutboxService
{
    public function __construct(
        protected ChannelAdapterRegistry $registry
    ) {
    }

    /**
     * Enqueue a newly created message and process the outbox if no message is in-flight.
     */
    public function enqueueAndProcess(Message $message, Channel $channel): void
    {
        $fresh = $message->fresh() ?? $message;
        if (
            $fresh->visibility === 'internal'
            || data_get($fresh->metadata, 'event') === 'session_divider'
        ) {
            return;
        }

        if ($this->isAlreadyDispatched($fresh)) {
            return;
        }

        $lockKey = "whatsapp_outbox_conv_{$fresh->conversation_id}";
        $lock = Cache::lock($lockKey, 15);
        if (!$lock->get()) {
            return;
        }

        try {
            $inFlight = $this->getActiveInFlightMessage($fresh->conversation_id);

            if ($inFlight && $inFlight->id !== $fresh->id) {
                // Another message is currently in-flight waiting for Meta delivered confirmation.
                // Keep this message pending in the conversation outbox.
                if (($fresh->metadata['delivery_status'] ?? '') !== 'pending' || $fresh->status !== MessageStatus::PENDING) {
                    $meta = is_array($fresh->metadata) ? $fresh->metadata : [];
                    $meta['delivery_status'] = 'pending';
                    $fresh->update([
                        'status' => MessageStatus::PENDING,
                        'metadata' => $meta,
                    ]);
                }

                Log::info("[WhatsAppOutboxService] Mensaje [{$fresh->id}] encolado en outbox de conversación [{$fresh->conversation_id}]. Esperando entrega de mensaje en tránsito [{$inFlight->id}].");

                return;
            }

            // No active in-flight message blocking: dispatch this message directly!
            $this->dispatchSingleMessage($fresh, $channel);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Process the outbox for a conversation (triggered by Meta webhook or timeout job).
     */
    public function processNextOutbound(string $conversationId): ?Message
    {
        $conversation = Conversation::find($conversationId);
        if (!$conversation) {
            return null;
        }

        $channel = $conversation->channel ?? Channel::find($conversation->channel_id);
        if (!$channel || $channel->type !== ChannelType::WHATSAPP) {
            return null;
        }

        $lockKey = "whatsapp_outbox_conv_{$conversationId}";
        $lock = Cache::lock($lockKey, 15);
        if (!$lock->get()) {
            return null;
        }

        try {
            return $this->dispatchNextPending($conversationId, $channel);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Dispatches the next pending message if no in-flight message is blocking.
     */
    public function dispatchNextPending(string $conversationId, Channel $channel): ?Message
    {
        $inFlight = $this->getActiveInFlightMessage($conversationId);
        if ($inFlight) {
            Log::info("[WhatsAppOutboxService] Outbox de conversación [{$conversationId}] en espera: mensaje en tránsito [{$inFlight->id}] pendiente de entrega.");

            return null;
        }

        $pending = $this->getNextPendingMessage($conversationId);
        if (!$pending) {
            return null;
        }

        $this->dispatchSingleMessage($pending, $channel);

        return $pending;
    }

    /**
     * Get the currently active in-flight message that has not yet received delivery confirmation.
     * If the message has exceeded the delivery timeout, it is treated as unconfirmed and unblocks the queue.
     *
     * Trade-off: Continuing past timeout prevents the entire conversation from stalling permanently,
     * but relative delivery order on the recipient's device may be compromised if Meta delivers late.
     */
    public function getActiveInFlightMessage(string $conversationId): ?Message
    {
        $timeout = (int) config('whatsapp.delivery_timeout_seconds', 25);

        $candidates = Message::query()
            ->where('conversation_id', $conversationId)
            ->where('direction', MessageDirection::OUTBOUND)
            ->where(function ($q) {
                $q->whereNull('metadata->event')
                    ->orWhere('metadata->event', '!=', 'session_divider');
            })
            ->where(function ($q) {
                $q->whereNull('visibility')->orWhereIn('visibility', ['public', 'external']);
            })
            ->where(function ($q) {
                $q->whereNotNull('external_message_id')
                    ->orWhereNotNull('metadata->wamid')
                    ->orWhereNotNull('metadata->meta_message_id')
                    ->orWhere('metadata->delivery_status', 'sent');
            })
            ->whereNotIn('status', [MessageStatus::DELIVERED, MessageStatus::READ, MessageStatus::FAILED])
            ->where(function ($q) {
                $q->whereNull('metadata->delivery_status')
                    ->orWhereNotIn('metadata->delivery_status', ['delivered', 'read', 'failed']);
            })
            ->orderBy('sent_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($candidates as $candidate) {
            $meta = is_array($candidate->metadata) ? $candidate->metadata : [];
            $sentAtStr = $meta['sent_at'] ?? null;
            $sentAt = $sentAtStr ? Carbon::parse($sentAtStr) : ($candidate->sent_at ?? $candidate->created_at);

            if ($sentAt && Carbon::now()->greaterThanOrEqualTo($sentAt->copy()->addSeconds($timeout))) {
                Log::warning("[WhatsAppOutboxService] Mensaje saliente [{$candidate->id}] no recibió confirmación de entrega (delivered) tras {$timeout}s. Desbloqueando outbox para evitar bloqueo indefinido de la conversación (nota: continuar evita bloqueos pero puede comprometer el orden de entrega en WhatsApp si Meta entrega tardíamente este mensaje).");

                if (empty($meta['delivery_timed_out'])) {
                    $meta['delivery_timed_out'] = true;
                    $meta['order_compromised_by_timeout'] = true;
                    $candidate->update(['metadata' => $meta]);
                }

                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * Retrieve the next pending outbound message in creation order for the conversation.
     */
    public function getNextPendingMessage(string $conversationId): ?Message
    {
        return Message::query()
            ->where('conversation_id', $conversationId)
            ->where('direction', MessageDirection::OUTBOUND)
            ->where(function ($q) {
                $q->whereNull('metadata->event')
                    ->orWhere('metadata->event', '!=', 'session_divider');
            })
            ->where(function ($q) {
                $q->whereNull('visibility')->orWhereIn('visibility', ['public', 'external']);
            })
            ->whereNull('external_message_id')
            ->where(function ($q) {
                $q->whereNull('metadata->wamid')
                    ->whereNull('metadata->meta_message_id');
            })
            ->where(function ($q) {
                $q->whereNull('metadata->delivery_status')
                    ->orWhere('metadata->delivery_status', 'pending');
            })
            ->where('status', '!=', MessageStatus::FAILED)
            ->orderBy('sent_at', 'asc')
            ->orderBy('id', 'asc')
            ->first();
    }

    /**
     * Dispatches a single message to WhatsApp Meta Graph API.
     * Guarantees that any null or error response is explicitly recorded as FAILED and
     * the outbox unblocks so pending messages are never silently stuck.
     */
    public function dispatchSingleMessage(Message $message, Channel $channel): ?array
    {
        $fresh = $message->fresh() ?? $message;
        if ($this->isAlreadyDispatched($fresh)) {
            return null;
        }

        $adapter = $this->registry->forChannel($channel);
        if (!method_exists($adapter, 'sendOutbound')) {
            $this->markAsFailed(
                $fresh,
                'ADAPTER_METHOD_MISSING',
                'Adaptador no soporta sendOutbound',
                'El adaptador configurado para el canal no implementa sendOutbound.'
            );
            $this->dispatchNextPending($fresh->conversation_id, $channel);

            return null;
        }

        try {
            $result = $adapter->sendOutbound($fresh);

            $wamid = $result['messages'][0]['id'] ?? ($result['id'] ?? null);
            if ($result && $wamid) {
                $fresh = $fresh->fresh() ?? $fresh;
                $meta = is_array($fresh->metadata) ? $fresh->metadata : [];
                $meta['delivery_status'] = 'sent';
                $meta['wamid'] = $wamid;
                if (empty($meta['sent_at'])) {
                    $meta['sent_at'] = now()->toIso8601String();
                }

                $updates = [
                    'status' => MessageStatus::SENT,
                    'metadata' => $meta,
                ];
                if (empty($fresh->external_message_id)) {
                    $alreadyUsed = Message::where('conversation_id', $fresh->conversation_id)
                        ->where('external_message_id', $wamid)
                        ->where('id', '!=', $fresh->id)
                        ->exists();

                    if (!$alreadyUsed) {
                        $updates['external_message_id'] = $wamid;
                    }
                }
                $fresh->update($updates);

                // Aceptación de Meta ya quedó registrada: un fallo al reabrir la conversación
                // no debe convertir el mensaje aceptado en FAILED ni habilitar un reintento duplicado.
                try {
                    $this->reopenClosedConversationAfterAcceptedAgentMessage($fresh);
                } catch (\Throwable $e) {
                    Log::error('whatsapp.outbox.reopen_after_acceptance_failed', [
                        'message_id' => $fresh->id,
                        'conversation_id' => $fresh->conversation_id,
                        'error' => WhatsAppChannelAdapter::sanitizeErrorMessage($e->getMessage()),
                    ]);
                }

                Log::info('whatsapp.outbox.dispatched', [
                    'message_id' => $fresh->id,
                    'conversation_id' => $fresh->conversation_id,
                    'external_message_id_hash' => $this->hashExternalMessageId($wamid),
                ]);

                $timeout = (int) config('whatsapp.delivery_timeout_seconds', 25);
                try {
                    ProcessWhatsAppOutboxJob::dispatch($fresh->conversation_id)
                        ->delay(now()->addSeconds($timeout + 1));
                } catch (\Throwable) {
                    // Ignore queue dispatch error in test contexts or offline drivers
                }

                return $result;
            }

            // Case 2: Meta Graph API returned an explicit error structure
            if ($result && isset($result['error'])) {
                $rawError = (string) ($result['error']['message'] ?? 'Error al enviar a WhatsApp');
                $sanitizedError = WhatsAppChannelAdapter::sanitizeErrorMessage($rawError);
                $this->markAsFailed(
                    $fresh,
                    (string) ($result['error']['code'] ?? 'META_API_ERROR'),
                    'Error de entrega en Meta',
                    $sanitizedError
                );

                Log::warning('whatsapp.outbox.dispatch_failed', [
                    'message_id' => $fresh->id,
                    'conversation_id' => $fresh->conversation_id,
                    'error_code' => $result['error']['code'] ?? 'META_API_ERROR',
                    'error_message' => $sanitizedError,
                ]);

                // Unblock outbox for next message in conversation
                $this->dispatchNextPending($fresh->conversation_id, $channel);

                return $result;
            }

            // Case 3: sendOutbound returned null (e.g. missing credentials or rejected by adapter)
            $fresh = $fresh->fresh() ?? $fresh;
            if ($fresh->status !== MessageStatus::FAILED) {
                $config = $channel->configuration ?? [];
                $hasCreds = !empty($config['access_token']) && !empty($config['phone_number_id']);

                $errorCode = !$hasCreds ? 'MISSING_CREDENTIALS' : 'DISPATCH_RETURNED_NULL';
                $errorTitle = !$hasCreds ? 'Credenciales de WhatsApp faltantes' : 'Error de despacho';
                $errorMessage = !$hasCreds
                    ? 'El canal de WhatsApp no tiene configurado el token de acceso o el Phone Number ID.'
                    : 'El adaptador sendOutbound devolvió null sin enviar el mensaje.';

                $this->markAsFailed($fresh, $errorCode, $errorTitle, $errorMessage);
            }

            Log::error('whatsapp.outbox.dispatch_returned_null', [
                'message_id' => $fresh->id,
                'conversation_id' => $fresh->conversation_id,
                'error_code' => $errorCode,
            ]);

            // Unblock outbox for next message in conversation so messages are never silently stuck
            $this->dispatchNextPending($fresh->conversation_id, $channel);

            return null;
        } catch (\Throwable $e) {
            $safeError = WhatsAppChannelAdapter::sanitizeErrorMessage($e->getMessage());
            Log::error("[WhatsAppOutboxService] Excepción de red al despachar mensaje [{$fresh->id}]: {$safeError}", [
                'message_id' => $fresh->id,
                'channel_id' => $channel->id,
            ]);

            $this->markAsFailed($fresh, 'NETWORK_EXCEPTION', 'Error de conexión con WhatsApp', $safeError);

            // Unblock outbox for next message in conversation
            $this->dispatchNextPending($fresh->conversation_id, $channel);

            return null;
        }
    }

    /**
     * Reopen a closed conversation only after provider (Meta/channel) accepts an agent-authored message.
     * Flow and system messages must never reopen a conversation on their own.
     * Reopening restarts the channel's initial entry flow and lets the flow/assignment node
     * select an eligible agent with existing capacity/load-balancing rules (no preference for previous agent).
     */
    public function reopenClosedConversationAfterAcceptedAgentMessage(Message $message): void
    {
        if ($message->sender_type !== MessageSenderType::AGENT) {
            return;
        }

        $reopened = DB::transaction(function () use ($message): ?Conversation {
            /** @var Conversation|null $conversation */
            $conversation = Conversation::whereKey($message->conversation_id)
                ->lockForUpdate()
                ->first();

            if (!$conversation || $conversation->status !== ConversationStatus::CLOSED) {
                return null;
            }

            $dividerService = app(\App\Services\ConversationSessionDividerService::class);
            if (! $dividerService->hasClosedSessionDivider($conversation, $conversation->closed_at)) {
                // Legacy closures have no close-time system marker yet.
                $dividerService->markAsSessionStart($message, 'agent_reply');
            }

            // Close any active assignments to ensure clean state
            ConversationAssignment::where('conversation_id', $conversation->id)
                ->whereNull('unassigned_at')
                ->update(['unassigned_at' => now()]);

            // Cancel any old flow execution (running, waiting_input, waiting_agent)
            FlowExecution::where('conversation_id', $conversation->id)
                ->whereIn('status', [
                    FlowExecutionStatus::RUNNING,
                    FlowExecutionStatus::WAITING_INPUT,
                    FlowExecutionStatus::WAITING_AGENT,
                ])
                ->update([
                    'status' => FlowExecutionStatus::CANCELLED,
                    'completed_at' => now(),
                ]);

            $meta = $conversation->metadata ?? [];
            unset($meta['closing_assignment_captured'], $meta['last_assigned_user_id']);

            $conversation->update([
                'status' => ConversationStatus::BOT,
                'closed_at' => null,
                'assigned_user_id' => null,
                'bot_enabled' => true,
                'metadata' => $meta,
            ]);

            $channel = $conversation->channel ?? Channel::find($conversation->channel_id);
            $entryVersion = $channel ? app(FlowResolver::class)->resolveEntryFlowVersion($channel) : null;

            if ($entryVersion) {
                app(FlowRuntimeEngine::class)->start($conversation, $entryVersion);
            } else {
                // If channel has no entry flow configured, set status to WAITING_AGENT and auto-assign
                $conversation->update([
                    'status' => ConversationStatus::WAITING_AGENT,
                ]);
                app(ConversationAssignmentService::class)->autoAssign($conversation);
            }

            return $conversation->fresh(['channel', 'contact', 'assignedUser', 'assignments']);
        });

        if ($reopened) {
            broadcast(new ConversationStatusChanged($reopened, ConversationStatus::CLOSED->value));
            broadcast(new ConversationUpdated($reopened));
        }
    }

    /**
     * Mark a message unequivocally as FAILED with visible structured error in metadata.
     */
    protected function markAsFailed(Message $message, string $code, string $title, string $details): void
    {
        $fresh = $message->fresh() ?? $message;
        $meta = is_array($fresh->metadata) ? $fresh->metadata : [];
        $meta['delivery_status'] = 'failed';
        $meta['delivery_error'] = $details;
        $meta['delivery_error_code'] = $code;
        $meta['delivery_error_title'] = $title;

        $fresh->update([
            'status' => MessageStatus::FAILED,
            'metadata' => $meta,
        ]);

        Log::warning('whatsapp.outbox.delivery_failed', [
            'message_id' => $fresh->id,
            'conversation_id' => $fresh->conversation_id,
            'error_code' => $code,
            'error_title' => $title,
            'external_message_id_hash' => $this->hashExternalMessageId($fresh->external_message_id ?? $meta['wamid'] ?? null),
        ]);

        broadcast(new MessageStatusUpdated($fresh));
    }

    protected function hashExternalMessageId(mixed $messageId): ?string
    {
        if (!is_string($messageId) || $messageId === '') {
            return null;
        }

        return substr(hash('sha256', $messageId), 0, 16);
    }

    /**
     * Check if a message has already been dispatched to Meta.
     */
    public function isAlreadyDispatched(Message $message): bool
    {
        $meta = is_array($message->metadata) ? $message->metadata : [];

        if (!empty($message->external_message_id) || !empty($meta['wamid']) || !empty($meta['meta_message_id'])) {
            return true;
        }

        $deliveryStatus = $meta['delivery_status'] ?? null;
        if (in_array($deliveryStatus, ['sent', 'delivered', 'read'], true)) {
            return true;
        }

        return false;
    }

    /**
     * Safely retry a failed outbound message ONLY when there is conclusive evidence
     * that Meta never received or explicitly rejected the request.
     *
     * Ambiguous states (such as NETWORK_EXCEPTION or DELIVERY_TIMED_OUT) or messages with a WAMID
     * are strictly rejected to eliminate the risk of duplicate message delivery to the recipient.
     */
    public function retryMessage(Message $message): bool
    {
        $fresh = $message->fresh() ?? $message;
        $conversation = $fresh->conversation;
        if (!$conversation) {
            return false;
        }

        $channel = $conversation->channel ?? Channel::find($conversation->channel_id);
        if (!$channel || $channel->type !== ChannelType::WHATSAPP) {
            return false;
        }

        // Must have conclusive evidence of non-delivery / non-acceptance
        if (!$this->canSafelyRetryMessage($fresh)) {
            return false;
        }

        $meta = is_array($fresh->metadata) ? $fresh->metadata : [];
        unset(
            $meta['delivery_error'],
            $meta['delivery_error_code'],
            $meta['delivery_error_title'],
            $meta['delivery_unconfirmed'],
            $meta['delivery_timed_out'],
            $meta['order_compromised_by_timeout']
        );
        $meta['delivery_status'] = 'pending';
        $meta['outbox_retry_count'] = ($meta['outbox_retry_count'] ?? 0) + 1;

        $fresh->update([
            'status' => MessageStatus::PENDING,
            'external_message_id' => null,
            'metadata' => $meta,
        ]);

        $this->processNextOutbound($conversation->id);

        return true;
    }

    /**
     * Determines whether a message can be safely retried without risk of duplicate delivery.
     *
     * Safety criteria:
     * - Returns FALSE if a WAMID was already assigned (Meta received and acknowledged the message).
     * - Returns FALSE if the failure was an ambiguous network exception (Meta might have received the POST before the connection dropped).
     * - Returns FALSE if the message timed out waiting for delivery or has delivery_timed_out (Meta may still deliver it).
     * - Returns TRUE ONLY when there is conclusive evidence that the message was never dispatched to Meta
     *   (e.g., MISSING_CREDENTIALS, ADAPTER_METHOD_MISSING, DISPATCH_RETURNED_NULL) or Meta explicitly
     *   rejected the message without queuing it (e.g., META_API_ERROR or explicit Meta error code).
     */
    public function canSafelyRetryMessage(Message $message): bool
    {
        $meta = is_array($message->metadata) ? $message->metadata : [];

        // Rule 1: A message with WAMID was already received by Meta.
        if (!empty($message->external_message_id) || !empty($meta['wamid']) || !empty($meta['meta_message_id'])) {
            Log::warning("[WhatsAppOutboxService] Reintento rechazado para mensaje [{$message->id}]: ya posee WAMID registrado. Reintentar causaría duplicación en WhatsApp.");

            return false;
        }

        // Rule 2: Ambiguous delivery timeout states (Meta may still deliver or response is in-flight).
        $errorCode = $meta['delivery_error_code'] ?? null;
        if (!$errorCode && is_array($meta['delivery_error'] ?? null)) {
            $errorCode = $meta['delivery_error']['code'] ?? null;
        }

        if (!empty($meta['delivery_timed_out']) || !empty($meta['delivery_unconfirmed']) || $errorCode === 'DELIVERY_TIMED_OUT') {
            Log::warning("[WhatsAppOutboxService] Reintento rechazado para mensaje [{$message->id}]: estado ambiguo de timeout (DELIVERY_TIMED_OUT). Meta pudo haber aceptado el envío y estar en proceso de entrega.");

            return false;
        }

        // Rule 3: Ambiguous network exception (POST request may have reached Meta before timeout/socket reset).
        if ($errorCode === 'NETWORK_EXCEPTION') {
            Log::warning("[WhatsAppOutboxService] Reintento rechazado para mensaje [{$message->id}]: estado ambiguo por excepción de red (NETWORK_EXCEPTION). La petición pudo haber alcanzado a Meta y la respuesta perderse.");

            return false;
        }

        // Rule 4: Conclusive local failures where no HTTP request reached Meta.
        $conclusiveLocalCodes = [
            'MISSING_CREDENTIALS',
            'ADAPTER_METHOD_MISSING',
            'DISPATCH_RETURNED_NULL',
        ];
        if (in_array($errorCode, $conclusiveLocalCodes, true)) {
            return true;
        }

        // Rule 5: Conclusive Meta rejection (Meta answered with an explicit error response and rejected the message).
        if ($errorCode === 'META_API_ERROR' || (is_string($errorCode) && (str_starts_with($errorCode, 'META_') || is_numeric($errorCode)))) {
            return true;
        }

        Log::warning("[WhatsAppOutboxService] Reintento rechazado para mensaje [{$message->id}]: no existe evidencia concluyente de que Meta no haya recibido la petición (código de error: " . ($errorCode ?? 'null') . ").");

        return false;
    }
}
