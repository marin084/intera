<?php

namespace App\Services\Runtime;

use App\Enums\ConversationStatus;
use App\Enums\FlowExecutionStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageType;
use App\Events\ConversationStatusChanged;
use App\Events\ConversationUpdated;
use App\Events\MessageCreated;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\ConversationParticipant;
use App\Models\FlowExecution;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Services\Runtime\Channels\ChannelAdapterRegistry;
use App\Services\Runtime\Channels\InboundMessage;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ConversationIngressService
{
    public function __construct(
        protected FlowResolver $flowResolver,
        protected FlowRuntimeEngine $engine,
        protected ChannelAdapterRegistry $channelRegistry
    ) {}

    /**
     * Ingest an inbound message from an InboundMessage DTO.
     *
     * @return array{is_duplicate: bool, contact: Contact, conversation: Conversation, message: Message, execution: ?FlowExecution}
     */
    public function handleInbound(InboundMessage $inbound): array
    {
        return $this->processInboundMessage(
            channel: $inbound->channel,
            contactPayload: $inbound->toContactPayload(),
            content: $inbound->content,
            externalMessageId: $inbound->externalMessageId,
            metadata: $inbound->messageMetadata,
            correlationId: $inbound->correlationId,
            messageType: $inbound->messageType,
            payload: $inbound->payload,
            sourceSentAt: $inbound->sourceSentAt
        );
    }

    /**
     * Ingest an inbound message from raw parameters (backward compatibility).
     *
     * @param  array{external_identifier?: string, display_name?: string, email?: string, phone?: string, metadata?: array}  $contactPayload
     * @param  array<string, mixed>  $metadata
     * @return array{is_duplicate: bool, contact: Contact, conversation: Conversation, message: Message, execution: ?FlowExecution}
     */
    public function handleInboundMessage(
        Channel $channel,
        array $contactPayload,
        string $content,
        ?string $externalMessageId = null,
        array $metadata = [],
        ?string $correlationId = null,
        string|MessageType $messageType = MessageType::TEXT,
        array $payload = []
    ): array {
        $correlationId = $correlationId ?: (string) Str::uuid();

        return $this->processInboundMessage(
            channel: $channel,
            contactPayload: $contactPayload,
            content: $content,
            externalMessageId: $externalMessageId,
            metadata: $metadata,
            correlationId: $correlationId,
            messageType: $messageType,
            payload: $payload
        );
    }

    /**
     * Internal atomic processing for inbound messages with concurrency protection and idempotency.
     *
     * @return array{is_duplicate: bool, contact: Contact, conversation: Conversation, message: Message, execution: ?FlowExecution}
     */
    protected function processInboundMessage(
        Channel $channel,
        array $contactPayload,
        string $content,
        ?string $externalMessageId,
        array $metadata,
        string $correlationId,
        string|MessageType $messageType = MessageType::TEXT,
        array $payload = [],
        ?Carbon $sourceSentAt = null
    ): array {
        try {
            return DB::transaction(function () use ($channel, $contactPayload, $content, $externalMessageId, $metadata, $correlationId, $messageType, $payload, $sourceSentAt) {
                // 1. Locate or create Contact
                $contact = $this->resolveContact($contactPayload);

                // 2. Lock Contact row for update to serialize conversation creation for the same contact
                $contact = Contact::where('id', $contact->id)->lockForUpdate()->first() ?? $contact;

                // 3. Early Idempotency Check: if this external message was already received for this contact & channel,
                // return cleanly without modifying any conversation state, reopening closed conversations, or starting executions.
                if ($externalMessageId !== null) {
                    /** @var Message|null $existingMessage */
                    $existingMessage = Message::where('external_message_id', $externalMessageId)
                        ->whereHas('conversation', function ($q) use ($channel, $contact) {
                            $q->where('channel_id', $channel->id)->where('contact_id', $contact->id);
                        })
                        ->latest('sent_at')
                        ->first();

                    if ($existingMessage) {
                        return $this->buildDuplicateResponse($contact, $existingMessage->conversation, $existingMessage);
                    }
                }

                // 4. Resolve conversation (active or most recent closed) and lock row for update
                $conversation = $this->resolveOrCreateConversation($channel, $contact);
                $conversation = Conversation::where('id', $conversation->id)->lockForUpdate()->first() ?? $conversation;

                // 5. If conversation is closed, safely reopen it for this confirmed new inbound message
                $status = $conversation->status instanceof ConversationStatus
                    ? $conversation->status
                    : ConversationStatus::tryFrom((string) $conversation->status);
                $reopenedAt = null;

                if ($status === ConversationStatus::CLOSED) {
                    $dividerService = app(\App\Services\ConversationSessionDividerService::class);
                    if (! $dividerService->hasClosedSessionDivider($conversation, $conversation->closed_at)) {
                        // Legacy closures predate close-time dividers, so mark their boundary on first reopen.
                        $reopenedAt = now();
                    }

                    $meta = $conversation->metadata ?? [];
                    unset($meta['closing_assignment_captured'], $meta['last_assigned_user_id']);

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

                    ConversationAssignment::where('conversation_id', $conversation->id)
                        ->whereNull('unassigned_at')
                        ->update(['unassigned_at' => now()]);

                    $conversation->update([
                        'status' => ConversationStatus::BOT,
                        'closed_at' => null,
                        'assigned_user_id' => null,
                        'bot_enabled' => true,
                        'active_flow_id' => null,
                        'active_flow_version_id' => null,
                        'metadata' => $meta,
                    ]);

                    DB::afterCommit(function () use ($conversation) {
                        $fresh = $conversation->fresh(['channel', 'contact']);
                        broadcast(new ConversationStatusChanged($fresh, ConversationStatus::CLOSED->value));
                        broadcast(new ConversationUpdated($fresh));
                    });
                }

                // Append correlation_id to message metadata
                $msgMeta = $metadata;
                unset($msgMeta['session_divider_before']);
                $msgMeta['_correlation_id'] = $correlationId;
                if ($reopenedAt) {
                    $msgMeta['session_divider_before'] = [
                        'event' => 'session_divider',
                        'timestamp' => $reopenedAt->toIso8601String(),
                        'source' => 'inbound_message',
                    ];
                }
                if (! empty($payload['attachment'])) {
                    $msgMeta['attachment'] = $payload['attachment'];
                }

                $resolvedType = $messageType instanceof MessageType
                    ? $messageType
                    : (MessageType::tryFrom((string) $messageType) ?? MessageType::TEXT);

                // 6. Persist inbound message
                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'sender_type' => MessageSenderType::CONTACT,
                    'sender_id' => (string) $contact->id,
                    'direction' => MessageDirection::INBOUND,
                    'message_type' => $resolvedType,
                    'visibility' => 'public',
                    'content' => $content,
                    'payload' => $payload,
                    'external_message_id' => $externalMessageId,
                    'metadata' => $msgMeta,
                    'sent_at' => now(),
                    'source_sent_at' => $sourceSentAt,
                ]);

                $convMetadata = $conversation->metadata ?? [];
                if (array_key_exists('inactivity_warned_at', $convMetadata) && $convMetadata['inactivity_warned_at'] !== null) {
                    $convMetadata['inactivity_warned_at'] = null;
                }

                $conversation->update([
                    'last_message_at' => now(),
                    'metadata' => $convMetadata,
                ]);

                DB::afterCommit(function () use ($message) {
                    broadcast(new MessageCreated($message));
                });

                // 7. Check if conversation is handled by human agent (waiting_agent or assigned) or bot is disabled
                if (! $conversation->bot_enabled || in_array($conversation->status, [ConversationStatus::WAITING_AGENT, ConversationStatus::ASSIGNED], true)) {
                    return [
                        'is_duplicate' => false,
                        'contact' => $contact,
                        'conversation' => $conversation->fresh(),
                        'message' => $message,
                        'execution' => null,
                    ];
                }

                // 8. Check for active execution waiting for input (with lock)
                /** @var FlowExecution|null $waitingExecution */
                $waitingExecution = FlowExecution::where('conversation_id', $conversation->id)
                    ->where('status', FlowExecutionStatus::WAITING_INPUT)
                    ->lockForUpdate()
                    ->first();

                if ($waitingExecution) {
                    $execution = $this->engine->resumeWithInboundMessage($conversation, $message, $correlationId);

                    return [
                        'is_duplicate' => false,
                        'contact' => $contact,
                        'conversation' => $conversation->fresh(),
                        'message' => $message,
                        'execution' => $execution,
                    ];
                }

                // 9. If no active execution waiting for input, check if one is currently running or start new entry flow
                $activeExecution = FlowExecution::where('conversation_id', $conversation->id)
                    ->whereIn('status', [FlowExecutionStatus::RUNNING, FlowExecutionStatus::WAITING_INPUT])
                    ->lockForUpdate()
                    ->first();

                $execution = null;
                if (! $activeExecution) {
                    $entryVersion = null;
                    if ($conversation->active_flow_version_id) {
                        $entryVersion = FlowVersion::find($conversation->active_flow_version_id);
                    }
                    if (! $entryVersion) {
                        $entryVersion = $this->flowResolver->resolveEntryFlowVersion($channel);
                    }
                    if ($entryVersion) {
                        $execution = $this->engine->start($conversation, $entryVersion, $correlationId);
                    } else {
                        $conversation->update(['status' => ConversationStatus::WAITING_AGENT]);
                        app(\App\Services\Routing\ConversationAssignmentService::class)->autoAssign($conversation);
                    }
                } else {
                    $execution = $activeExecution;
                }

                return [
                    'is_duplicate' => false,
                    'contact' => $contact,
                    'conversation' => $conversation->fresh(),
                    'message' => $message,
                    'execution' => $execution,
                ];
            }, 3);
        } catch (QueryException $e) {
            if ($externalMessageId !== null && $this->isUniqueViolation($e)) {
                return $this->recoverDuplicateMessage($channel, $contactPayload, $externalMessageId);
            }

            throw $e;
        }
    }

    /**
     * Recovers state for a message that lost a concurrent race on UNIQUE(conversation_id, external_message_id).
     *
     * @param  array{external_identifier?: string, display_name?: string, email?: string, phone?: string, metadata?: array}  $contactPayload
     * @return array{is_duplicate: bool, contact: Contact, conversation: Conversation, message: Message, execution: ?FlowExecution}
     */
    protected function recoverDuplicateMessage(Channel $channel, array $contactPayload, string $externalMessageId): array
    {
        $contact = $this->resolveContact($contactPayload);
        $conversation = $this->resolveActiveConversation($channel, $contact);

        $existingMessage = Message::where('conversation_id', $conversation->id)
            ->where('external_message_id', $externalMessageId)
            ->first();

        if ($existingMessage) {
            return $this->buildDuplicateResponse($contact, $conversation, $existingMessage);
        }

        $fallbackMessage = Message::where('external_message_id', $externalMessageId)->latest('sent_at')->first();
        if ($fallbackMessage) {
            return $this->buildDuplicateResponse($contact, $fallbackMessage->conversation ?? $conversation, $fallbackMessage);
        }

        throw new \RuntimeException('No se pudo recuperar el mensaje duplicado de forma segura.');
    }

    /**
     * @return array{is_duplicate: bool, contact: Contact, conversation: Conversation, message: Message, execution: ?FlowExecution}
     */
    protected function buildDuplicateResponse(Contact $contact, Conversation $conversation, Message $message): array
    {
        $activeExec = FlowExecution::where('conversation_id', $conversation->id)
            ->latest('started_at')
            ->first();

        return [
            'is_duplicate' => true,
            'contact' => $contact,
            'conversation' => $conversation,
            'message' => $message,
            'execution' => $activeExec,
        ];
    }

    protected function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $errorCode = (int) ($e->errorInfo[1] ?? 0);
        $message = $e->getMessage();

        return $sqlState === '23505'
            || $sqlState === '23000'
            || $errorCode === 19
            || $errorCode === 1062
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'duplicate key value')
            || str_contains($message, 'Duplicate entry');
    }

    protected function resolveContact(array $payload): Contact
    {
        $contact = null;

        if (! empty($payload['external_identifier'])) {
            $contact = Contact::where('external_identifier', $payload['external_identifier'])->first();
        }

        if (! $contact && ! empty($payload['email'])) {
            $contact = Contact::where('email', $payload['email'])->first();
        }

        if (! $contact && ! empty($payload['phone'])) {
            $contact = Contact::where('phone', $payload['phone'])->first();
        }

        if (! $contact) {
            try {
                $contact = Contact::create([
                    'display_name' => $payload['display_name'] ?? 'Visitante Anónimo',
                    'external_identifier' => $payload['external_identifier'] ?? (string) Str::uuid(),
                    'email' => $payload['email'] ?? null,
                    'phone' => $payload['phone'] ?? null,
                    'metadata' => $payload['metadata'] ?? [],
                ]);
            } catch (QueryException $e) {
                // If a concurrent request created the contact simultaneously, re-query
                if ($this->isUniqueViolation($e) && ! empty($payload['external_identifier'])) {
                    $contact = Contact::where('external_identifier', $payload['external_identifier'])->first();
                }
                if (! $contact) {
                    throw $e;
                }
            }
        }

        return $contact;
    }

    protected function resolveOrCreateConversation(Channel $channel, Contact $contact): Conversation
    {
        /** @var Conversation|null $active */
        $active = Conversation::where('channel_id', $channel->id)
            ->where('contact_id', $contact->id)
            ->where('status', '!=', ConversationStatus::CLOSED)
            ->latest('started_at')
            ->first();

        if ($active) {
            return $active;
        }

        /** @var Conversation|null $closed */
        $closed = Conversation::where('channel_id', $channel->id)
            ->where('contact_id', $contact->id)
            ->where('status', ConversationStatus::CLOSED)
            ->latest('started_at')
            ->first();

        if ($closed) {
            return $closed;
        }

        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'participant_type' => 'contact',
            'participant_id' => (string) $contact->id,
            'joined_at' => now(),
        ]);

        return $conversation;
    }

    protected function resolveActiveConversation(Channel $channel, Contact $contact): Conversation
    {
        return $this->resolveOrCreateConversation($channel, $contact);
    }
}
