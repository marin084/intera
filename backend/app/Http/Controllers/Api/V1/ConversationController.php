<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\FlowExecutionStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\ConversationAssigned;
use App\Events\ConversationStatusChanged;
use App\Events\ConversationUpdated;
use App\Events\MessageCreated;
use App\Exceptions\ConversationConflictException;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\ConversationReadState;
use App\Models\FlowExecution;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Models\Tag;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ConversationLifecycleService;
use App\Services\ConversationSessionDividerService;
use App\Services\Media\AttachmentUploadService;
use App\Services\Presence\AgentAvailabilityService;
use App\Services\Routing\ConversationAssignmentService;
use App\Services\Runtime\Channels\ChannelAdapterRegistry;
use App\Services\Runtime\FlowResolver;
use App\Services\Runtime\FlowRuntimeEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    public function __construct(
        protected ConversationLifecycleService $lifecycleService,
        protected ChannelAdapterRegistry $registry
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            AgentAvailabilityService::recordHeartbeat($user);
        }
        $isSupervisor = $user->hasRole(['super_admin', 'admin']) || $user->hasPermission('conversations.view_all');

        $query = Conversation::where('is_simulation', false)
            ->with([
                'channel:id,name,type',
                'contact:id,display_name,email,phone,country,external_identifier',
                'assignedUser:id,name,email',
                'team:id,name',
                'assignments.assignedUser:id,name',
                'tags:id,name,color,slug',
            ]);

        if (! $user->hasRole('super_admin')) {
            $query->whereIn('channel_id', $user->channels()->select('channels.id'));
        }

        $scope = $request->query('scope');
        $status = $request->query('status');

        if (! $isSupervisor) {
            if ($status === 'assigned') {
                $query->where('assigned_user_id', $user->id)
                    ->where('status', ConversationStatus::ASSIGNED);
            } elseif (($scope === 'queue' || $status === ConversationStatus::WAITING_AGENT->value) && $user->hasPermission('teams.view')) {
                $teamIds = $user->teams()->where('teams.is_active', true)->select('teams.id');
                $query->where('status', ConversationStatus::WAITING_AGENT)
                    ->whereNull('assigned_user_id')
                    ->whereIn('team_id', $teamIds);
                if ($request->filled('team_id')) {
                    $teamId = (string) $request->query('team_id');
                    if (Str::isUuid($teamId) && $user->belongsToTeam($teamId)) $query->where('team_id', $teamId);
                    else $query->whereRaw('1 = 0');
                }
            } elseif ($status === 'closed') {
                $query->where('status', ConversationStatus::CLOSED)
                    ->where(function ($q) use ($user) {
                        $q->where('metadata->last_assigned_user_id', $user->id)
                            ->orWhere(function ($q2) use ($user) {
                                $q2->whereNull('metadata->closing_assignment_captured')
                                    ->whereNull('metadata->last_assigned_user_id')
                                    ->whereRaw('(SELECT assigned_user_id FROM conversation_assignments WHERE conversation_assignments.conversation_id = conversations.id ORDER BY assigned_at DESC, id DESC LIMIT 1) = ?', [$user->id]);
                            });
                    });
            } elseif ($status && $status !== 'all') {
                // Agent cannot see bot, waiting_agent or other unassigned/foreign statuses
                $query->whereRaw('1 = 0');
            } else {
                // The all view contains active assigned conversations; closed items live in the archive tab.
                $query->where('assigned_user_id', $user->id)
                    ->where('status', ConversationStatus::ASSIGNED);
            }
        } else {
            // Supervisor / Admin visibility
            if ($scope === 'queue') {
                $query->where('status', ConversationStatus::WAITING_AGENT)->whereNull('assigned_user_id');
                if ($request->filled('team_id')) {
                    $teamId = (string) $request->query('team_id');
                    if (Str::isUuid($teamId)) $query->where('team_id', $teamId);
                    else $query->whereRaw('1 = 0');
                }
            } elseif ($scope === 'mine') {
                $query->where('assigned_user_id', $user->id);
            } elseif ($status && $status !== 'all') {
                $query->where('status', $status);
            } else {
                // Closed conversations are shown in the archive, not in the active all view.
                $query->where('status', '!=', ConversationStatus::CLOSED);
            }
        }

        if ($request->filled('channel_id') && $request->query('channel_id') !== 'all') {
            $channelId = (string) $request->query('channel_id');
            if (Str::isUuid($channelId)) {
                $query->where('channel_id', $channelId);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($request->filled('tag_id') && $request->query('tag_id') !== 'all') {
            $tagId = (string) $request->query('tag_id');
            if (Str::isUuid($tagId)) {
                $query->whereHas('tags', fn ($tq) => $tq->where('tags.id', $tagId));
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // Supervisor/Admin agent filter: assigned_user_id
        if ($isSupervisor && $request->has('assigned_user_id')) {
            $assignedUserId = $request->query('assigned_user_id');
            if ($assignedUserId === 'unassigned' || $assignedUserId === 'null') {
                $query->whereNull('assigned_user_id');
            } elseif ($assignedUserId !== '' && $assignedUserId !== null && $assignedUserId !== 'all' && is_numeric($assignedUserId)) {
                $query->where('assigned_user_id', (int) $assignedUserId);
            }
        }

        // Server-side search filter: contact.display_name, contact.email, contact.phone, conversation.id
        if ($request->filled('search')) {
            $rawSearch = trim((string) $request->query('search'));
            // Bound search string to reasonable length
            $search = mb_substr($rawSearch, 0, 100);

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                    $likePattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                    $q->whereHas('contact', function ($cq) use ($likePattern, $likeOperator) {
                        $cq->where('display_name', $likeOperator, $likePattern)
                            ->orWhere('email', $likeOperator, $likePattern)
                            ->orWhere('phone', $likeOperator, $likePattern)
                            ->orWhere('external_identifier', $likeOperator, $likePattern);
                    });

                    // Match conversation id safely without PostgreSQL type cast failure
                    if (Str::isUuid($search)) {
                        $q->orWhere('conversations.id', $search);
                    } elseif (DB::connection()->getDriverName() === 'pgsql') {
                        $q->orWhereRaw('conversations.id::text ilike ?', [$likePattern]);
                    } else {
                        $q->orWhere('conversations.id', 'like', $likePattern);
                    }
                });
            }
        }

        // Add correlated subquery for unread_count per conversation for current user and last inbound sent_at
        $query->select('conversations.*')
            ->selectSub(function ($sub) {
                $sub->from('messages')
                    ->whereColumn('messages.conversation_id', 'conversations.id')
                    ->where('messages.direction', MessageDirection::INBOUND->value)
                    ->orderByRaw('COALESCE(source_sent_at, sent_at) DESC')
                    ->selectRaw('COALESCE(source_sent_at, sent_at)')
                    ->limit(1);
            }, 'last_inbound_sent_at')
            ->selectSub(function ($sub) use ($user) {
                $sub->from('messages')
                    ->whereColumn('messages.conversation_id', 'conversations.id')
                    ->where('messages.sender_type', MessageSenderType::CONTACT->value)
                    ->where(function ($w) use ($user) {
                        $w->whereNotExists(function ($ex) use ($user) {
                            $ex->from('conversation_read_states')
                                ->whereColumn('conversation_read_states.conversation_id', 'conversations.id')
                                ->where('conversation_read_states.user_id', $user->id)
                                ->whereNotNull('conversation_read_states.last_read_at');
                        })->orWhereRaw('(
                            messages.sent_at > (
                                SELECT last_read_at FROM conversation_read_states
                                WHERE conversation_read_states.conversation_id = conversations.id
                                  AND conversation_read_states.user_id = ?
                                LIMIT 1
                            )
                            OR (
                                messages.sent_at = (
                                    SELECT last_read_at FROM conversation_read_states
                                    WHERE conversation_read_states.conversation_id = conversations.id
                                      AND conversation_read_states.user_id = ?
                                    LIMIT 1
                                )
                                AND messages.id > (
                                    SELECT last_read_message_id FROM conversation_read_states
                                    WHERE conversation_read_states.conversation_id = conversations.id
                                      AND conversation_read_states.user_id = ?
                                    LIMIT 1
                                )
                            )
                        )', [$user->id, $user->id, $user->id]);
                    })
                    ->selectRaw('count(*)');
            }, 'unread_count');

        $query->orderByDesc('last_message_at')->orderByDesc('created_at');

        $conversations = $query->paginate(20);
        app(\App\Services\ConversationSlaService::class)->decorateInbox(collect($conversations->items()));

        $counts = $this->calculateTabCounts($user, $isSupervisor, $request->query('channel_id'));

        return response()->json([
            'data' => $conversations->items(),
            'current_page' => $conversations->currentPage(),
            'last_page' => $conversations->lastPage(),
            'total' => $conversations->total(),
            'counts' => $counts,
        ]);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        if ($conversation->is_simulation) {
            return response()->json(['message' => 'Conversación no encontrada.'], 404);
        }

        $user = $request->user();

        if (! $this->canUserAccessConversation($user, $conversation)) {
            return response()->json([
                'message' => 'Forbidden. No tienes permiso para ver esta conversación.',
            ], 403);
        }

        $conversation->load([
            'channel',
            'contact',
            'team:id,name',
            'assignedUser:id,name,email',
            'assignments.assignedUser:id,name',
            'assignments.assignedByUser:id,name',
            'tags:id,name,color,slug',
        ]);

        $limit = min((int) $request->query('limit', 50), 100);
        $items = Message::where('conversation_id', $conversation->id)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $items->count() > $limit;
        $messages = $items->take($limit)->reverse()->values();
        $this->attachAgentNames($messages);

        $conversation->setRelation('messages', $messages);

        $latestExecution = FlowExecution::where('conversation_id', $conversation->id)
            ->orderByDesc('started_at')
            ->first();

        // 1. Extract variables from flow execution context and contact metadata
        $rawVariables = array_merge(
            is_array($conversation->contact?->metadata) ? $conversation->contact->metadata : [],
            is_array($latestExecution?->context) ? $latestExecution->context : []
        );

        $variables = [];
        foreach ($rawVariables as $key => $value) {
            if (str_starts_with((string) $key, '_')) {
                continue;
            }
            $cleanKey = ltrim((string) $key, '$');
            $variables[$cleanKey] = $value;
        }

        // 2. Extract media and document attachments from messages
        $attachments = Message::where('conversation_id', $conversation->id)
            ->whereIn('message_type', ['image', 'file', 'audio'])
            ->orderByDesc('sent_at')
            ->get()
            ->map(function (Message $msg) {
                $attachment = $msg->payload['attachment'] ?? $msg->metadata['attachment'] ?? null;
                $url = $attachment['url'] ?? (filter_var($msg->content, FILTER_VALIDATE_URL) ? $msg->content : null);
                if (! $url) {
                    return null;
                }

                $msgTypeValue = is_object($msg->message_type) ? $msg->message_type->value : $msg->message_type;
                $defaultFileName = match ($msgTypeValue) {
                    'image' => 'imagen.jpg',
                    'audio' => 'audio.ogg',
                    default => 'documento.pdf',
                };

                return [
                    'id' => $msg->id,
                    'message_id' => $msg->id,
                    'type' => $msg->message_type,
                    'url' => $url,
                    'file_name' => $attachment['file_name'] ?? $defaultFileName,
                    'file_size' => $attachment['size_bytes'] ?? ($attachment['file_size'] ?? null),
                    'mime_type' => $attachment['mime_type'] ?? null,
                    'sent_at' => $msg->sent_at?->toIso8601String(),
                    'sender_type' => $msg->sender_type,
                ];
            })
            ->filter()
            ->values();

        // 3. Extract internal notes
        $internalNotes = Message::where('conversation_id', $conversation->id)
            ->where('visibility', 'internal')
            ->where(function ($query) {
                $query->whereNull('metadata->event')
                    ->orWhere('metadata->event', '!=', 'session_divider');
            })
            ->orderByDesc('sent_at')
            ->get()
            ->map(function (Message $m) {
                $senderName = 'Sistema';
                if ($m->sender_type === 'agent' && $m->sender_id) {
                    $user = User::find($m->sender_id);
                    $senderName = $user?->name ?? 'Agente';
                }

                return [
                    'id' => $m->id,
                    'content' => $m->content,
                    'sender_id' => $m->sender_id,
                    'sender_name' => $senderName,
                    'sender_type' => $m->sender_type,
                    'sent_at' => $m->sent_at?->toIso8601String(),
                ];
            });

        if ($conversation->tags->isEmpty() && ! empty($conversation->metadata['tags'])) {
            foreach ($conversation->metadata['tags'] as $tagName) {
                if (is_string($tagName) && trim($tagName) !== '') {
                    $tagModel = Tag::firstOrCreate(
                        ['name' => trim($tagName)],
                        ['slug' => Str::slug($tagName), 'color' => '#3B82F6']
                    );
                    $conversation->tags()->syncWithoutDetaching([$tagModel->id => ['assigned_by' => $user->id]]);
                }
            }
            $conversation->load('tags:id,name,color,slug');
        }

        $tags = $conversation->tags;

        $conversationData = $conversation->toArray();
        $conversationData['tags'] = $tags;
        $conversationData['variables'] = $variables;
        $conversationData['attachments'] = $attachments;
        $conversationData['internal_notes'] = $internalNotes;
        $conversationData['whatsapp_window'] = $conversation->whatsapp_window;
        $conversationData['meta_window'] = $conversation->meta_window;
        if (isset($conversationData['contact'])) {
            $conversationData['contact']['name'] = $conversation->contact?->display_name;
        }

        return response()->json([
            'data' => $conversationData,
            'variables' => $variables,
            'tags' => $tags,
            'attachments' => $attachments,
            'internal_notes' => $internalNotes,
            'whatsapp_window' => $conversation->whatsapp_window,
            'meta_window' => $conversation->meta_window,
            'messages' => [
                'data' => $messages,
                'has_more' => $hasMore,
                'oldest_message_id' => $messages->first()?->id,
            ],
        ]);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        if (! $this->canUserAccessConversation($user, $conversation)) {
            return response()->json([
                'message' => 'Forbidden. No tienes permiso para ver esta conversación.',
            ], 403);
        }

        $query = Message::where('conversation_id', $conversation->id);

        if ($request->filled('before')) {
            $beforeMsg = Message::where('conversation_id', $conversation->id)->find($request->query('before'));
            if ($beforeMsg) {
                $query->where(function ($q) use ($beforeMsg) {
                    $q->where('sent_at', '<', $beforeMsg->sent_at)
                        ->orWhere(function ($q2) use ($beforeMsg) {
                            $q2->where('sent_at', '=', $beforeMsg->sent_at)
                                ->where('id', '<', $beforeMsg->id);
                        });
                });
            }
        }

        $limit = min((int) $request->query('limit', 50), 100);
        $items = $query->orderByDesc('sent_at')->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $items->count() > $limit;
        $messages = $items->take($limit)->reverse()->values();
        $this->attachAgentNames($messages);

        return response()->json([
            'data' => $messages,
            'has_more' => $hasMore,
            'oldest_message_id' => $messages->first()?->id,
        ]);
    }

    public function claim(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        try {
            $updated = $this->lifecycleService->claim($conversation, $user);

            return response()->json([
                'message' => 'Conversación reclamada correctamente.',
                'data' => $updated,
            ]);
        } catch (ConversationConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
            ], 409);
        }
    }

    public function assign(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'assigned_user_id' => ['nullable', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $currentUser = $request->user();
        $assignedUser = ! empty($validated['assigned_user_id']) ? User::find($validated['assigned_user_id']) : null;

        if (! $currentUser->canAccessChannel((string) $conversation->channel_id)) {
            return response()->json(['message' => 'No tienes acceso al canal de esta conversación.'], 403);
        }
        if ($assignedUser && ! $assignedUser->canAccessChannel((string) $conversation->channel_id)) {
            return response()->json(['message' => 'El usuario seleccionado no tiene acceso al canal de esta conversación.'], 422);
        }

        $updated = $this->lifecycleService->assign(
            conversation: $conversation,
            assignedUser: $assignedUser,
            assignedBy: $currentUser,
            reason: $validated['reason'] ?? null
        );

        return response()->json([
            'message' => 'Conversación asignada correctamente.',
            'data' => $updated,
        ]);
    }

    public function close(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        if (! $user->canAccessChannel((string) $conversation->channel_id)) {
            return response()->json(['message' => 'No tienes acceso al canal de esta conversación.'], 403);
        }

        $updated = $this->lifecycleService->close($conversation, $user);

        return response()->json([
            'message' => 'Conversación cerrada correctamente.',
            'data' => $updated,
        ]);
    }

    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        return $this->assign($request, $conversation);
    }

    public function updatePriority(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        if (! $this->canUserAccessConversation($user, $conversation)) {
            return response()->json(['message' => 'No tienes acceso a esta conversación.'], 403);
        }

        $validated = $request->validate([
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ]);

        $updated = DB::transaction(function () use ($conversation, $validated) {
            $locked = Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === ConversationStatus::CLOSED) {
                abort(422, 'No se puede cambiar la prioridad de una conversación archivada.');
            }
            $locked->update(['priority' => $validated['priority']]);
            return $locked->fresh(['team', 'channel', 'contact', 'assignedUser']);
        });

        DB::afterCommit(fn () => broadcast(new ConversationUpdated($updated)));
        AuditLogger::log(
            action: 'conversation.priority_updated',
            entityType: 'Conversation',
            entityId: (string) $updated->id,
            metadata: ['priority' => $validated['priority']]
        );

        return response()->json(['message' => 'Prioridad actualizada.', 'data' => $updated]);
    }

    public function markAsRead(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        if (! $this->canUserAccessConversation($user, $conversation)) {
            return response()->json([
                'message' => 'Forbidden. No tienes permiso para marcar esta conversación como leída.',
            ], 403);
        }

        $latestMessage = Message::where('conversation_id', $conversation->id)->orderByDesc('sent_at')->orderByDesc('id')->first();

        $readState = ConversationReadState::updateOrCreate(
            [
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
            ],
            [
                'last_read_message_id' => $latestMessage?->id,
                'last_read_at' => $latestMessage?->sent_at ?? now(),
            ]
        );

        return response()->json([
            'message' => 'Conversación marcada como leída.',
            'data' => $readState,
            'unread_count' => 0,
        ]);
    }

    public function sendMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        if (! $user->canAccessChannel((string) $conversation->channel_id)) {
            return response()->json(['message' => 'No tienes acceso al canal de esta conversación.'], 403);
        }

        // RBAC check: Agent can reply if assigned, supervisor, or was the closing assigned agent
        $isAssigned = (int) $conversation->assigned_user_id === (int) $user->id;
        $isSupervisor = $user->hasRole(['super_admin', 'admin']) || $user->hasPermission('conversations.view_all');
        $wasClosingAgent = $conversation->status === ConversationStatus::CLOSED
            && $user->hasPermission('conversations.reply')
            && $conversation->closing_assigned_user_id === (int) $user->id;

        if (! $isSupervisor && ! $isAssigned && ! $wasClosingAgent) {
            return response()->json([
                'message' => 'Forbidden. Solo el agente asignado o un supervisor puede responder esta conversación.',
            ], 403);
        }

        $channel = $conversation->channel ?? Channel::find($conversation->channel_id);
        $channelType = is_object($channel?->type) ? $channel->type->value : $channel?->type;

        // Validar conversaciones cerradas: Webchat/Telegram/canales sin ventana Meta siempre bloqueados.
        // En canales Meta (WhatsApp, Messenger, Instagram), un agente autorizado puede responder solo si la ventana sigue abierta.
        if ($conversation->status === ConversationStatus::CLOSED) {
            if ($channelType === ChannelType::WHATSAPP->value) {
                if (! $conversation->is_whatsapp_window_open) {
                    return response()->json([
                        'message' => 'La ventana de atención de 24 horas de WhatsApp ha expirado. Meta solo permite reabrir la conversación mediante una plantilla preaprobada.',
                        'error_code' => 'WHATSAPP_24H_WINDOW_EXPIRED',
                    ], 422);
                }
            } elseif ($channelType === ChannelType::FACEBOOK_MESSENGER->value) {
                if (! $conversation->is_messenger_window_open) {
                    return response()->json([
                        'message' => 'La ventana de atención de 24 horas de Facebook Messenger ha expirado. Meta solo permite responder mensajes dentro de las 24 horas posteriores al último mensaje del usuario.',
                        'error_code' => 'MESSENGER_24H_WINDOW_EXPIRED',
                    ], 422);
                }
            } elseif ($channelType === ChannelType::INSTAGRAM->value) {
                if (! $conversation->is_instagram_window_open) {
                    return response()->json([
                        'message' => 'La ventana de atención de 24 horas de Instagram Direct ha expirado. Meta solo permite responder mensajes directos dentro de las 24 horas posteriores al último mensaje del usuario.',
                        'error_code' => 'INSTAGRAM_24H_WINDOW_EXPIRED',
                    ], 422);
                }
            } else {
                return response()->json([
                    'message' => 'La conversación está cerrada y no admite nuevos mensajes.',
                    'code' => 'conversation_closed',
                ], 422);
            }
        }

        $validated = $request->validate([
            'content' => ['nullable', 'string', 'max:5000', 'required_without_all:attachment,template'],
            'attachment' => ['nullable', 'file'],
            'message_type' => ['nullable', 'string', 'in:text,template,image,audio,file'],
            'template' => ['nullable', 'array', 'required_if:message_type,template'],
            'template.name' => ['required_if:message_type,template', 'string'],
            'template.language' => ['nullable'],
            'template.language.code' => ['nullable', 'string'],
            'template.components' => ['nullable', 'array'],
            'visibility' => ['nullable', 'string', 'in:public,internal,external'],
            'metadata' => ['nullable', 'array'],
        ]);

        $messageType = MessageType::TEXT;
        $payload = [];
        $metadata = $validated['metadata'] ?? [];

        if (($validated['message_type'] ?? '') === 'template' || ! empty($validated['template'])) {
            $messageType = MessageType::TEMPLATE;
            $payload = ['template' => $validated['template']];
            $templateName = $validated['template']['name'] ?? 'plantilla';
            $validated['content'] = $validated['content'] ?? "[Plantilla WhatsApp: {$templateName}]";
        } elseif ($request->hasFile('attachment')) {
            $upload = app(AttachmentUploadService::class)->store($request->file('attachment'));
            $messageType = $upload['message_type'];
            $payload = [
                'attachment' => $upload['attachment'],
            ];
            $metadata['attachment'] = $upload['attachment'];
        }

        $visibility = ($validated['visibility'] ?? 'public') === 'internal' ? 'internal' : 'public';
        $isTemplate = ($messageType === MessageType::TEMPLATE);

        // Validación crítica servidor: si es template, exigir coincidencia exacta de nombre e idioma con plantillas APPROVED en Meta
        if ($isTemplate) {
            if ($channelType !== ChannelType::WHATSAPP->value) {
                return response()->json([
                    'message' => 'El tipo de mensaje plantilla solo está disponible para canales de WhatsApp.',
                    'error_code' => 'TEMPLATE_UNSUPPORTED_CHANNEL',
                ], 422);
            }

            $adapter = $this->registry->forChannel($channel);
            $approvedTemplates = method_exists($adapter, 'getApprovedTemplates')
                ? $adapter->getApprovedTemplates($channel)
                : [];

            $requestedName = trim((string) ($validated['template']['name'] ?? ''));
            $requestedLang = '';
            if (isset($validated['template']['language'])) {
                $requestedLang = is_array($validated['template']['language'])
                    ? trim((string) ($validated['template']['language']['code'] ?? ''))
                    : trim((string) $validated['template']['language']);
            }

            if (empty($requestedName) || empty($requestedLang)) {
                return response()->json([
                    'message' => 'El nombre y el idioma de la plantilla son obligatorios.',
                    'error_code' => 'TEMPLATE_VALIDATION_FAILED',
                ], 422);
            }

            $matchedTemplate = collect($approvedTemplates)->first(function ($t) use ($requestedName, $requestedLang) {
                return ($t['name'] ?? '') === $requestedName
                    && strtolower((string) ($t['language'] ?? '')) === strtolower($requestedLang);
            });

            if (! $matchedTemplate) {
                return response()->json([
                    'message' => "La plantilla '{$requestedName}' con idioma '{$requestedLang}' no existe o no se encuentra en estado APPROVED en Meta para este canal.",
                    'error_code' => 'TEMPLATE_NOT_APPROVED',
                ], 422);
            }

            $validated['content'] = $validated['content'] ?? ($matchedTemplate['text'] ?: "[Plantilla WhatsApp: {$requestedName}]");
            $payload['template']['name'] = $requestedName;
            $payload['template']['language'] = ['code' => $requestedLang];
        }

        // Validar ventana de atención de 24 horas de Meta (WhatsApp, Messenger e Instagram) para mensajes externos
        if ($visibility !== 'internal') {
            if ($channelType === ChannelType::WHATSAPP->value && ! $conversation->is_whatsapp_window_open && ! $isTemplate) {
                return response()->json([
                    'message' => 'La ventana de atención de 24 horas de WhatsApp ha expirado. Meta solo permite reabrir la conversación mediante una plantilla preaprobada.',
                    'error_code' => 'WHATSAPP_24H_WINDOW_EXPIRED',
                ], 422);
            }

            if ($channelType === ChannelType::FACEBOOK_MESSENGER->value && ! $conversation->is_messenger_window_open) {
                return response()->json([
                    'message' => 'La ventana de atención de 24 horas de Facebook Messenger ha expirado. Meta solo permite responder mensajes dentro de las 24 horas posteriores al último mensaje del usuario.',
                    'error_code' => 'MESSENGER_24H_WINDOW_EXPIRED',
                ], 422);
            }

            if ($channelType === ChannelType::INSTAGRAM->value && ! $conversation->is_instagram_window_open) {
                return response()->json([
                    'message' => 'La ventana de atención de 24 horas de Instagram Direct ha expirado. Meta solo permite responder mensajes directos dentro de las 24 horas posteriores al último mensaje del usuario.',
                    'error_code' => 'INSTAGRAM_24H_WINDOW_EXPIRED',
                ], 422);
            }
        }

        $initialStatus = ($visibility === 'internal' || $channelType === ChannelType::WEBCHAT->value)
            ? MessageStatus::SENT
            : MessageStatus::PENDING;

        $message = DB::transaction(function () use ($conversation, $user, $validated, $messageType, $payload, $metadata, $visibility, $initialStatus) {
            $msg = Message::create([
                'conversation_id' => $conversation->id,
                'sender_type' => MessageSenderType::AGENT,
                'sender_id' => (string) $user->id,
                'direction' => MessageDirection::OUTBOUND,
                'message_type' => $messageType,
                'visibility' => $visibility,
                'status' => $initialStatus,
                'content' => $validated['content'] ?? '',
                'payload' => $payload,
                'metadata' => $metadata,
                'sent_at' => now(),
            ]);

            $conversation->update([
                'last_message_at' => now(),
            ]);

            DB::afterCommit(function () use ($msg) {
                broadcast(new MessageCreated($msg));
            });

            return $msg;
        });

        AuditLogger::log(
            action: 'conversation.reply',
            entityType: 'Message',
            entityId: (string) $message->id,
            metadata: [
                'conversation_id' => $conversation->id,
                'sender_id' => $user->id,
            ]
        );

        return response()->json([
            'message' => 'Mensaje enviado exitosamente.',
            'data' => $message,
        ], 201);
    }

    public function execution(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        if (! $this->canUserAccessConversation($user, $conversation)) {
            return response()->json([
                'message' => 'Forbidden. No tienes permiso para ver esta conversación.',
            ], 403);
        }

        /** @var FlowExecution|null $execution */
        $execution = FlowExecution::with(['flow', 'flowVersion', 'currentNode', 'steps.flowNode'])
            ->where('conversation_id', $conversation->id)
            ->latest('started_at')
            ->first();

        if (! $execution) {
            return response()->json([
                'data' => null,
            ]);
        }

        return response()->json([
            'data' => [
                'id' => $execution->id,
                'flow' => $execution->flow ? [
                    'id' => $execution->flow->id,
                    'name' => $execution->flow->name,
                ] : null,
                'version' => $execution->flowVersion ? [
                    'id' => $execution->flowVersion->id,
                    'version_number' => $execution->flowVersion->version_number,
                ] : null,
                'status' => $execution->status->value,
                'current_node' => $execution->currentNode ? [
                    'id' => $execution->currentNode->id,
                    'node_key' => $execution->currentNode->node_key,
                    'name' => $execution->currentNode->name,
                    'type' => $execution->currentNode->type->value,
                ] : null,
                'context' => AuditLogger::sanitize($execution->context ?? []),
                'started_at' => $execution->started_at?->toIso8601String(),
                'waiting_since' => $execution->waiting_since?->toIso8601String(),
                'completed_at' => $execution->completed_at?->toIso8601String(),
                'failed_at' => $execution->failed_at?->toIso8601String(),
                'failure_code' => $execution->failure_code,
                'steps' => $execution->steps->map(fn ($s) => [
                    'id' => $s->id,
                    'node_id' => $s->flow_node_id,
                    'node_key' => $s->flowNode?->node_key,
                    'node_name' => $s->flowNode?->name,
                    'node_type' => $s->node_type,
                    'status' => $s->status->value,
                    'entered_at' => $s->entered_at?->toIso8601String(),
                    'completed_at' => $s->completed_at?->toIso8601String(),
                    'input' => AuditLogger::sanitize($s->input ?? []),
                    'output' => AuditLogger::sanitize($s->output ?? []),
                    'error_code' => $s->error_code,
                    'metadata' => AuditLogger::sanitize($s->metadata ?? []),
                ]),
            ],
        ]);
    }

    public function updateContact(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        if (! $this->canUserAccessConversation($user, $conversation)) {
            return response()->json([
                'message' => 'Forbidden. No tienes permiso para editar este contacto.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'display_name' => ['sometimes', 'string', 'max:150'],
        ]);

        $name = $validated['name'] ?? $validated['display_name'] ?? null;
        if (! $name) {
            return response()->json(['message' => 'El campo name o display_name es requerido.'], 422);
        }

        $contact = $conversation->contact;
        if ($contact) {
            $contact->update(['display_name' => $name]);
        }

        $conversation->load(['channel', 'contact', 'assignedUser']);
        ConversationUpdated::dispatch($conversation);

        return response()->json([
            'message' => 'Contacto actualizado correctamente.',
            'contact' => $contact,
        ]);
    }

    public function addTag(Request $request, Conversation $conversation): JsonResponse
    {
        return $this->syncTags($request, $conversation);
    }

    public function syncTags(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        if (! $this->canUserAccessConversation($user, $conversation)) {
            return response()->json([
                'message' => 'Forbidden. No tienes permiso para editar etiquetas.',
            ], 403);
        }

        // Support tag_ids (array of UUIDs), tag_id (single UUID), or tag (legacy string name)
        if ($request->has('tag_ids')) {
            $validated = $request->validate([
                'tag_ids' => ['array'],
                'tag_ids.*' => ['uuid', 'exists:tags,id'],
            ]);

            $syncData = [];
            foreach ($validated['tag_ids'] ?? [] as $tId) {
                $syncData[$tId] = ['assigned_by' => $user->id];
            }
            $conversation->tags()->sync($syncData);
        } elseif ($request->has('tag_id')) {
            $validated = $request->validate([
                'tag_id' => ['required', 'uuid', 'exists:tags,id'],
            ]);

            $tId = $validated['tag_id'];
            if ($conversation->tags()->where('tags.id', $tId)->exists()) {
                $conversation->tags()->detach($tId);
            } else {
                $conversation->tags()->attach($tId, ['assigned_by' => $user->id]);
            }
        } elseif ($request->has('tag')) {
            $validated = $request->validate([
                'tag' => ['required', 'string', 'max:50'],
            ]);
            $conversation->addTag($validated['tag'], $user->id);
        } else {
            return response()->json([
                'message' => 'Debes proporcionar tag_ids, tag_id o tag.',
            ], 422);
        }

        $conversation->load(['channel', 'contact', 'assignedUser', 'tags']);
        ConversationUpdated::dispatch($conversation);

        return response()->json([
            'message' => 'Etiquetas actualizadas correctamente.',
            'tags' => $conversation->tags,
            'data' => $conversation->tags,
        ]);
    }

    public function removeTag(Request $request, Conversation $conversation, string $tag): JsonResponse
    {
        $user = $request->user();

        if (! $this->canUserAccessConversation($user, $conversation)) {
            return response()->json([
                'message' => 'Forbidden. No tienes permiso para editar etiquetas.',
            ], 403);
        }

        $conversation->removeTag($tag);

        $conversation->load(['channel', 'contact', 'assignedUser', 'tags']);
        ConversationUpdated::dispatch($conversation);

        return response()->json([
            'message' => 'Etiqueta eliminada correctamente.',
            'tags' => $conversation->tags,
            'data' => $conversation->tags,
        ]);
    }

    public function transfer(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        if (! $this->canUserAccessConversation($user, $conversation)) {
            return response()->json([
                'message' => 'Forbidden. No tienes permiso para transferir esta conversación.',
            ], 403);
        }

        $validated = $request->validate([
            'target_user_id' => [
                'required',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('is_active', true)),
            ],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $targetUser = User::findOrFail($validated['target_user_id']);

        if (! $targetUser->canAccessChannel((string) $conversation->channel_id)) {
            return response()->json(['message' => 'La persona seleccionada no tiene acceso al canal de esta conversación.'], 422);
        }
        if (! $conversation->team_id || ! $targetUser->belongsToTeam((string) $conversation->team_id)) {
            return response()->json(['message' => 'La persona seleccionada no pertenece al equipo de esta conversación.'], 422);
        }

        DB::transaction(function () use ($conversation, $targetUser, $user, $validated) {
            /** @var Conversation $locked */
            $locked = Conversation::where('id', $conversation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ConversationStatus::CLOSED) {
                abort(422, 'No se puede transferir una conversación que ya está cerrada.');
            }

            $lockedTarget = User::query()->whereKey($targetUser->id)->lockForUpdate()->firstOrFail();
            if (! $lockedTarget->is_active
                || ! $lockedTarget->canAccessChannel((string) $locked->channel_id)
                || ! $locked->team_id
                || ! $lockedTarget->belongsToTeam((string) $locked->team_id)) {
                abort(422, 'La persona seleccionada no está habilitada para este equipo y canal.');
            }
            if ((int) $locked->assigned_user_id !== (int) $lockedTarget->id
                && ! app(ConversationAssignmentService::class)->canAcceptMoreConversations($lockedTarget, (string) $locked->id)) {
                abort(422, 'La persona seleccionada ya alcanzó su capacidad de conversaciones activas.');
            }

            // Close active assignments
            ConversationAssignment::where('conversation_id', $locked->id)
                ->whereNull('unassigned_at')
                ->update(['unassigned_at' => now()]);

            // Register new assignment
            ConversationAssignment::create([
                'conversation_id' => $locked->id,
                'assigned_user_id' => $targetUser->id,
                'assigned_by_user_id' => $user->id,
                'assigned_at' => now(),
                'reason' => 'Transferencia directa entre agentes',
            ]);

            // Transition status to assigned
            $locked->update([
                'assigned_user_id' => $targetUser->id,
                'status' => ConversationStatus::ASSIGNED,
                'queued_at' => null,
            ]);
            app(\App\Services\Routing\ConversationQueueService::class)->leave($locked->id, 'assigned', (int) $targetUser->id);

            // Internal transfer note if provided
            $noteText = trim((string) ($validated['note'] ?? ''));
            if ($noteText !== '') {
                $noteMessage = Message::create([
                    'conversation_id' => $locked->id,
                    'sender_type' => MessageSenderType::AGENT->value,
                    'sender_id' => (string) $user->id,
                    'direction' => MessageDirection::OUTBOUND->value,
                    'message_type' => 'text',
                    'visibility' => 'internal',
                    'content' => "🔄 Transferido a {$targetUser->name}: {$noteText}",
                    'sent_at' => now(),
                ]);

                broadcast(new MessageCreated($noteMessage));
            }
        });

        $fresh = $conversation->fresh(['channel', 'contact', 'assignedUser', 'assignments', 'tags']);

        broadcast(new ConversationAssigned($fresh, $targetUser, 'Transferida por '.$user->name));
        broadcast(new ConversationUpdated($fresh));
        broadcast(new ConversationStatusChanged($fresh));

        return response()->json([
            'message' => 'Conversación transferida correctamente a '.$targetUser->name.'.',
            'data' => $fresh,
        ]);
    }

    public function toggleBot(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        if (! $this->canUserAccessConversation($user, $conversation)) {
            return response()->json([
                'message' => 'Forbidden. No tienes permiso para modificar esta conversación.',
            ], 403);
        }

        $conversation->bot_enabled = ! (bool) $conversation->bot_enabled;

        if (! $conversation->bot_enabled) {
            FlowExecution::where('conversation_id', $conversation->id)
                ->whereIn('status', [FlowExecutionStatus::RUNNING, FlowExecutionStatus::WAITING_INPUT])
                ->update([
                    'status' => FlowExecutionStatus::CANCELLED,
                    'completed_at' => now(),
                ]);
        }

        $conversation->save();

        $conversation->load(['channel', 'contact', 'assignedUser']);
        ConversationUpdated::dispatch($conversation);

        return response()->json([
            'message' => $conversation->bot_enabled ? 'Bot reanudado correctamente.' : 'Bot pausado correctamente.',
            'data' => $conversation,
        ]);
    }

    public function restartFlow(Request $request, Conversation $conversation, FlowResolver $flowResolver, FlowRuntimeEngine $engine): JsonResponse
    {
        $user = $request->user();

        if (! $this->canUserAccessConversation($user, $conversation)) {
            return response()->json([
                'message' => 'Forbidden. No tienes permiso para reiniciar el flujo en esta conversación.',
            ], 403);
        }

        $startsNewSession = $conversation->status === ConversationStatus::CLOSED;
        $closedAt = $startsNewSession ? $conversation->closed_at : null;

        // Cancel previous running/waiting executions
        FlowExecution::where('conversation_id', $conversation->id)
            ->whereIn('status', [FlowExecutionStatus::RUNNING, FlowExecutionStatus::WAITING_INPUT, FlowExecutionStatus::WAITING_AGENT])
            ->update([
                'status' => FlowExecutionStatus::CANCELLED,
                'completed_at' => now(),
            ]);

        // Close any active assignments to release quota immediately
        ConversationAssignment::where('conversation_id', $conversation->id)
            ->whereNull('unassigned_at')
            ->update(['unassigned_at' => now()]);

        $meta = $conversation->metadata ?? [];
        unset($meta['closing_assignment_captured'], $meta['last_assigned_user_id']);

        $conversation->bot_enabled = true;
        $conversation->status = ConversationStatus::BOT;
        $conversation->closed_at = null;
        $conversation->assigned_user_id = null;
        $conversation->metadata = $meta;
        $conversation->save();

        if ($startsNewSession) {
            app(ConversationSessionDividerService::class)
                ->ensureClosedSessionDivider($conversation, 'manual_restart', $closedAt);
        }

        $channel = $conversation->channel;
        $entryVersion = null;
        if ($channel) {
            $entryVersion = $flowResolver->resolveEntryFlowVersion($channel);
        }
        if (! $entryVersion && $conversation->active_flow_version_id) {
            $entryVersion = FlowVersion::find($conversation->active_flow_version_id);
        }

        $execution = null;
        if ($entryVersion) {
            $execution = $engine->start($conversation, $entryVersion);
        } else {
            $conversation->status = ConversationStatus::WAITING_AGENT;
            $conversation->save();
            app(ConversationAssignmentService::class)->autoAssign($conversation);
        }

        $conversation->load(['channel', 'contact', 'assignedUser', 'assignments']);
        ConversationUpdated::dispatch($conversation);

        return response()->json([
            'message' => 'Flujo reiniciado correctamente.',
            'data' => [
                'conversation' => $conversation,
                'execution_id' => $execution?->id,
            ],
        ]);
    }

    protected function canUserAccessConversation(User $user, Conversation $conversation): bool
    {
        if (! $user->canAccessChannel((string) $conversation->channel_id)) {
            return false;
        }

        if ($user->hasRole('super_admin') || $user->hasRole('admin') || $user->hasPermission('conversations.view_all')) {
            return true;
        }

        if ((int) $conversation->assigned_user_id === (int) $user->id) {
            return true;
        }

        if ($conversation->status === ConversationStatus::WAITING_AGENT
            && $user->hasPermission('teams.view')
            && $conversation->team_id
            && $user->belongsToTeam((string) $conversation->team_id)) {
            return true;
        }

        if ($conversation->status === ConversationStatus::CLOSED && $user->hasPermission('conversations.reply')) {
            return $conversation->closing_assigned_user_id === (int) $user->id;
        }

        return false;
    }

    private function attachAgentNames(Collection $messages): void
    {
        $agentIds = $messages
            ->filter(function (Message $message) {
                $senderType = $message->sender_type instanceof MessageSenderType
                    ? $message->sender_type->value
                    : $message->sender_type;

                return $senderType === MessageSenderType::AGENT->value && $message->sender_id;
            })
            ->pluck('sender_id')
            ->unique()
            ->values();

        if ($agentIds->isEmpty()) {
            return;
        }

        $namesById = User::whereIn('id', $agentIds)->pluck('name', 'id');

        foreach ($messages as $message) {
            $senderType = $message->sender_type instanceof MessageSenderType
                ? $message->sender_type->value
                : $message->sender_type;

            if ($senderType === MessageSenderType::AGENT->value) {
                $message->setAttribute('sender_name', $namesById->get((int) $message->sender_id));
            }
        }
    }

    protected function calculateTabCounts(User $user, bool $isSupervisor, ?string $channelId = null): array
    {
        $baseQuery = Conversation::where('is_simulation', false);
        if (! $user->hasRole('super_admin')) {
            $baseQuery->whereIn('channel_id', $user->channels()->select('channels.id'));
        }
        if ($channelId && $channelId !== 'all') {
            if (Str::isUuid($channelId)) {
                $baseQuery->where('channel_id', $channelId);
            } else {
                $baseQuery->whereRaw('1 = 0');
            }
        }

        if ($isSupervisor) {
            $all = (clone $baseQuery)->where('status', '!=', ConversationStatus::CLOSED)->count();
            $bot = (clone $baseQuery)->where('status', ConversationStatus::BOT)->count();
            $waiting = (clone $baseQuery)->where('status', ConversationStatus::WAITING_AGENT)->whereNull('assigned_user_id')->count();
            $assigned = (clone $baseQuery)->where('status', ConversationStatus::ASSIGNED)->count();
            $closed = (clone $baseQuery)->where('status', ConversationStatus::CLOSED)->count();
        } else {
            $assigned = (clone $baseQuery)
                ->where('assigned_user_id', $user->id)
                ->where('status', ConversationStatus::ASSIGNED)
                ->count();
            $closed = (clone $baseQuery)
                ->where('status', ConversationStatus::CLOSED)
                ->where(function ($q) use ($user) {
                    $q->where('metadata->last_assigned_user_id', $user->id)
                        ->orWhere(function ($q2) use ($user) {
                            $q2->whereNull('metadata->closing_assignment_captured')
                                ->whereNull('metadata->last_assigned_user_id')
                                ->whereRaw('(SELECT assigned_user_id FROM conversation_assignments WHERE conversation_assignments.conversation_id = conversations.id ORDER BY assigned_at DESC, id DESC LIMIT 1) = ?', [$user->id]);
                        });
                })
                ->count();
            $waiting = $user->hasPermission('teams.view')
                ? (clone $baseQuery)->where('status', ConversationStatus::WAITING_AGENT)
                    ->whereNull('assigned_user_id')
                    ->whereIn('team_id', $user->teams()->where('teams.is_active', true)->select('teams.id'))
                    ->count()
                : 0;
            $bot = 0;
            $all = $assigned;
        }

        return [
            'all' => $all,
            'bot' => $bot,
            'waiting_agent' => $waiting,
            'assigned' => $assigned,
            'closed' => $closed,
        ];
    }
}
