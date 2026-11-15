<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\ConversationStatus;
use App\Enums\FlowExecutionStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageType;
use App\Events\ConversationCreated;
use App\Events\ConversationStatusChanged;
use App\Events\ConversationUpdated;
use App\Events\MessageCreated;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\ConversationParticipant;
use App\Models\FlowExecution;
use App\Models\Message;
use App\Models\WebchatChannelSettings;
use App\Models\WebchatSession;
use App\Support\WebchatOriginPolicy;
use App\Services\Media\AttachmentUploadService;
use App\Services\Runtime\ConversationIngressService;
use App\Services\Runtime\FlowResolver;
use App\Services\Runtime\FlowRuntimeEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WebchatController extends Controller
{
    public function __construct(
        protected FlowResolver $flowResolver,
        protected FlowRuntimeEngine $engine,
        protected ConversationIngressService $ingressService
    ) {}

    /**
     * Handshake endpoint for host page embed authorization.
     */
    public function embed(Request $request, string $publicKey): JsonResponse
    {
        /** @var WebchatChannelSettings|null $settings */
        $settings = WebchatChannelSettings::with(['channel.entryFlow.activeVersion'])
            ->where('public_key', $publicKey)
            ->first();

        if (! $settings) {
            return response()->json([
                'message' => 'Configuración de webchat no encontrada.',
            ], 404);
        }

        $channel = $settings->channel;
        if (! $channel || ! $channel->is_active) {
            return response()->json([
                'message' => 'Chat no disponible. El canal está desactivado.',
                'code' => 'channel_inactive',
            ], 422);
        }

        // Check if entry flow has a published version
        $entryVersion = $this->flowResolver->resolveEntryFlowVersion($channel);
        if (! $entryVersion) {
            return response()->json([
                'message' => 'Este Webchat no puede atender conversaciones porque no tiene un flujo publicado asignado.',
                'code' => 'entry_flow_missing',
            ], 422);
        }

        // Validate Origin against allowed_origins
        $rawOrigin = $request->header('Origin') ?: $request->header('Referer') ?: $request->input('origin');
        if (! $rawOrigin) {
            return response()->json([
                'message' => 'Se requiere el encabezado Origin para autorizar el widget.',
            ], 403);
        }

        $normalizedOrigin = $this->normalizeOrigin($rawOrigin);
        $allowed = array_map(fn ($o) => $this->normalizeOrigin($o), $settings->allowed_origins ?? []);

        if (! in_array($normalizedOrigin, $allowed, true) && ! WebchatOriginPolicy::isPlatformOrigin($normalizedOrigin)) {
            return response()->json([
                'message' => 'Origen no permitido.',
            ], 403);
        }

        // Generate short-lived embed token
        $embedToken = $this->createEmbedToken($publicKey, $normalizedOrigin);

        return response()->json([
            'data' => [
                'embed_token' => $embedToken,
                'channel_id' => $channel->id,
                'settings' => [
                    'display_name' => $settings->display_name,
                    'accent_color' => $settings->accent_color,
                    'launcher_position' => $settings->launcher_position,
                ],
            ],
        ]);
    }

    /**
     * Create a new visitor session or resume an existing one using raw visitor token.
     */
    public function createOrResumeSession(Request $request, string $publicKey): JsonResponse
    {
        $validated = $request->validate([
            'embed_token' => ['required', 'string'],
            'visitor_token' => ['nullable', 'string'],
        ]);

        /** @var WebchatChannelSettings|null $settings */
        $settings = WebchatChannelSettings::with('channel')
            ->where('public_key', $publicKey)
            ->first();

        if (! $settings || ! $settings->channel || ! $settings->channel->is_active) {
            return response()->json([
                'message' => 'Canal de webchat no disponible.',
            ], 422);
        }

        $rawOrigin = $request->header('Origin') ?: $request->header('Referer') ?: $request->input('origin');
        $normalizedOrigin = $this->normalizeOrigin($rawOrigin ?: '');

        // Verify embed token
        if (! $this->verifyEmbedToken($validated['embed_token'], $publicKey, $normalizedOrigin)) {
            return response()->json([
                'message' => 'Token de integración inválido o expirado.',
            ], 403);
        }

        $existingToken = $validated['visitor_token'] ?? null;
        if ($existingToken) {
            $tokenHash = WebchatSession::hashToken($existingToken);
            /** @var WebchatSession|null $existingSession */
            $existingSession = WebchatSession::with(['contact', 'activeConversation'])
                ->where('token_hash', $tokenHash)
                ->where('channel_id', $settings->channel_id)
                ->first();

            if ($existingSession && $existingSession->isValid()) {
                $existingSession->update(['last_seen_at' => now()]);

                return response()->json([
                    'message' => 'Sesión recuperada exitosamente.',
                    'data' => [
                        'visitor_token' => $existingToken,
                        'session_id' => $existingSession->id,
                        'contact' => [
                            'display_name' => $existingSession->contact?->display_name,
                        ],
                        'active_conversation' => $existingSession->activeConversation ? $this->formatPublicConversation($existingSession->activeConversation) : null,
                        'expires_at' => $existingSession->expires_at->toIso8601String(),
                    ],
                ]);
            }
        }

        // Create new Contact and WebchatSession
        $shortId = Str::lower(Str::random(6));
        $contact = Contact::create([
            'display_name' => "Visitante {$shortId}",
            'external_identifier' => 'wcv_'.Str::uuid(),
            'metadata' => ['origin' => $normalizedOrigin],
        ]);

        $tokenPair = WebchatSession::createTokenPair();
        $ttlDays = (int) config('webchat.session_ttl_days', 30);

        $session = WebchatSession::create([
            'channel_id' => $settings->channel_id,
            'contact_id' => $contact->id,
            'token_hash' => $tokenPair['token_hash'],
            'origin' => $normalizedOrigin,
            'last_seen_at' => now(),
            'expires_at' => now()->addDays($ttlDays),
        ]);

        return response()->json([
            'message' => 'Sesión creada exitosamente.',
            'data' => [
                'visitor_token' => $tokenPair['raw_token'],
                'session_id' => $session->id,
                'contact' => [
                    'display_name' => $contact->display_name,
                ],
                'active_conversation' => null,
                'expires_at' => $session->expires_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Get current visitor session details.
     */
    public function session(Request $request): JsonResponse
    {
        /** @var WebchatSession $session */
        $session = $request->attributes->get('webchat_session');
        $session->loadMissing(['contact', 'activeConversation']);

        return response()->json([
            'data' => [
                'session_id' => $session->id,
                'contact' => [
                    'display_name' => $session->contact?->display_name,
                ],
                'active_conversation' => $session->activeConversation ? $this->formatPublicConversation($session->activeConversation) : null,
                'expires_at' => $session->expires_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Start a new conversation or resume the active unclosed one.
     */
    public function startConversation(Request $request): JsonResponse
    {
        /** @var WebchatSession $session */
        $session = $request->attributes->get('webchat_session');
        $channel = $session->channel;

        $entryVersion = $this->flowResolver->resolveEntryFlowVersion($channel);
        if (! $entryVersion) {
            return response()->json([
                'message' => 'Este Webchat no puede atender conversaciones porque no tiene un flujo publicado asignado.',
                'code' => 'entry_flow_missing',
            ], 422);
        }

        // Persistent Threading: Check if contact already has a conversation in this channel
        $existing = null;
        if ($session->active_conversation_id) {
            $existing = Conversation::where('id', $session->active_conversation_id)
                ->where('channel_id', $channel->id)
                ->where('contact_id', $session->contact_id)
                ->first();
        }

        if (! $existing) {
            $existing = Conversation::where('channel_id', $channel->id)
                ->where('contact_id', $session->contact_id)
                ->latest('started_at')
                ->first();
        }

        if ($existing) {
            $status = $existing->status instanceof ConversationStatus
                ? $existing->status
                : ConversationStatus::tryFrom((string) $existing->status);

            // Active conversation recovery (not closed)
            if ($status !== ConversationStatus::CLOSED) {
                if ($session->active_conversation_id !== $existing->id) {
                    $session->update(['active_conversation_id' => $existing->id]);
                }
                $msgData = $this->getPublicMessages($existing->id);

                return response()->json([
                    'message' => 'Conversación activa recuperada.',
                    'data' => [
                        'conversation' => $this->formatPublicConversation($existing),
                        'messages' => $msgData['data'],
                        'has_more' => $msgData['has_more'],
                        'oldest_message_id' => $msgData['oldest_message_id'],
                    ],
                ]);
            }

            // Existing conversation was closed: persistent conversation threading!
            $conversation = DB::transaction(function () use ($existing, $channel, $session, $entryVersion) {
                /** @var Conversation $conv */
                $conv = Conversation::where('id', $existing->id)->lockForUpdate()->first();

                // Keep a divider at the close boundary; create one here only for legacy closures.
                app(\App\Services\ConversationSessionDividerService::class)
                    ->ensureClosedSessionDivider($conv, 'webchat_start', $conv->closed_at);

                // 2. Update conversation state: status = 'bot', closed_at = null, last_message_at = now()
                $meta = $conv->metadata ?? [];
                unset($meta['closing_assignment_captured'], $meta['last_assigned_user_id']);

                $conv->update([
                    'status' => ConversationStatus::BOT,
                    'closed_at' => null,
                    'last_message_at' => now(),
                    'assigned_user_id' => null,
                    'metadata' => $meta,
                ]);

                ConversationAssignment::where('conversation_id', $conv->id)
                    ->whereNull('unassigned_at')
                    ->update(['unassigned_at' => now()]);

                $session->update([
                    'active_conversation_id' => $conv->id,
                ]);

                // 3. Cancel/complete old flow executions of this conversation
                FlowExecution::where('conversation_id', $conv->id)
                    ->whereIn('status', [
                        FlowExecutionStatus::RUNNING,
                        FlowExecutionStatus::WAITING_INPUT,
                        FlowExecutionStatus::WAITING_AGENT,
                    ])
                    ->update([
                        'status' => FlowExecutionStatus::CANCELLED,
                        'completed_at' => now(),
                    ]);

                // Start active entry flow execution (hydrating permanent contact variables)
                $this->engine->start($conv, $entryVersion);

                $fresh = $conv->fresh(['channel', 'contact']);

                DB::afterCommit(function () use ($fresh, $divider) {
                    broadcast(new MessageCreated($divider));
                    broadcast(new ConversationUpdated($fresh));
                    broadcast(new ConversationStatusChanged($fresh));
                });

                return $fresh;
            });

            $msgData = $this->getPublicMessages($conversation->id);

            return response()->json([
                'message' => 'Conversación iniciada exitosamente.',
                'data' => [
                    'conversation' => $this->formatPublicConversation($conversation),
                    'messages' => $msgData['data'],
                    'has_more' => $msgData['has_more'],
                    'oldest_message_id' => $msgData['oldest_message_id'],
                ],
            ], 201);
        }

        // If NO previous conversation exists: create initial conversation as usual
        $conversation = DB::transaction(function () use ($channel, $session, $entryVersion) {
            $conv = Conversation::create([
                'channel_id' => $channel->id,
                'contact_id' => $session->contact_id,
                'status' => ConversationStatus::BOT,
                'started_at' => now(),
                'last_message_at' => now(),
            ]);

            ConversationParticipant::create([
                'conversation_id' => $conv->id,
                'participant_type' => 'contact',
                'participant_id' => (string) $session->contact_id,
                'joined_at' => now(),
            ]);

            $session->update([
                'active_conversation_id' => $conv->id,
            ]);

            // Start flow execution
            $this->engine->start($conv, $entryVersion);

            $fresh = $conv->fresh(['channel', 'contact']);
            DB::afterCommit(function () use ($fresh) {
                broadcast(new ConversationCreated($fresh));
            });

            return $fresh;
        });

        $msgData = $this->getPublicMessages($conversation->id);

        return response()->json([
            'message' => 'Conversación iniciada exitosamente.',
            'data' => [
                'conversation' => $this->formatPublicConversation($conversation),
                'messages' => $msgData['data'],
                'has_more' => $msgData['has_more'],
                'oldest_message_id' => $msgData['oldest_message_id'],
            ],
        ], 201);
    }

    /**
     * Get active conversation details for current session.
     */
    public function currentConversation(Request $request): JsonResponse
    {
        /** @var WebchatSession $session */
        $session = $request->attributes->get('webchat_session');

        if (! $session->active_conversation_id) {
            return response()->json(['data' => null]);
        }

        $conversation = Conversation::find($session->active_conversation_id);
        if (! $conversation) {
            return response()->json(['data' => null]);
        }

        $msgData = $this->getPublicMessages($conversation->id);

        return response()->json([
            'data' => [
                'conversation' => $this->formatPublicConversation($conversation),
                'messages' => $msgData['data'],
                'has_more' => $msgData['has_more'],
                'oldest_message_id' => $msgData['oldest_message_id'],
            ],
        ]);
    }

    /**
     * Get public messages of the current active conversation.
     */
    public function messages(Request $request): JsonResponse
    {
        /** @var WebchatSession $session */
        $session = $request->attributes->get('webchat_session');

        if (! $session->active_conversation_id) {
            return response()->json([
                'data' => [],
                'has_more' => false,
                'oldest_message_id' => null,
            ]);
        }

        $msgData = $this->getPublicMessages(
            conversationId: $session->active_conversation_id,
            before: $request->query('before'),
            limit: min((int) $request->query('limit', 50), 100)
        );

        return response()->json([
            'data' => $msgData['data'],
            'has_more' => $msgData['has_more'],
            'oldest_message_id' => $msgData['oldest_message_id'],
        ]);
    }

    /**
     * Send a message from the visitor in the current active conversation.
     */
    public function sendMessage(Request $request): JsonResponse
    {
        /** @var WebchatSession $session */
        $session = $request->attributes->get('webchat_session');

        $maxLen = (int) config('webchat.max_message_length', 4000);
        $validated = $request->validate([
            'content' => ['nullable', 'string', "max:{$maxLen}", 'required_without:attachment'],
            'attachment' => ['nullable', 'file'],
            'client_message_id' => ['required', 'uuid'],
        ], [
            'content.max' => "El mensaje no puede superar los {$maxLen} caracteres.",
            'content.required_without' => 'El contenido o un archivo adjunto es requerido.',
            'client_message_id.uuid' => 'El identificador del mensaje debe ser un UUID válido.',
        ]);

        if (! $session->active_conversation_id) {
            return response()->json([
                'message' => 'No hay una conversación activa iniciada.',
            ], 422);
        }

        $conversation = Conversation::find($session->active_conversation_id);
        if (! $conversation) {
            return response()->json([
                'message' => 'No hay una conversación activa iniciada.',
            ], 422);
        }

        $messageType = MessageType::TEXT->value;
        $payload = [];
        if ($request->hasFile('attachment')) {
            $upload = app(AttachmentUploadService::class)->store($request->file('attachment'));
            $messageType = $upload['message_type']->value;
            $payload = [
                'attachment' => $upload['attachment'],
            ];
        }

        $result = $this->ingressService->handleInboundMessage(
            channel: $session->channel,
            contactPayload: [
                'external_identifier' => (string) $session->contact->external_identifier,
                'display_name' => $session->contact->display_name,
            ],
            content: (string) ($validated['content'] ?? ''),
            externalMessageId: $validated['client_message_id'],
            messageType: $messageType,
            payload: $payload
        );

        $resolvedConversation = $result['conversation'] ?? $conversation;
        if ($session->active_conversation_id !== $resolvedConversation->id) {
            $session->update(['active_conversation_id' => $resolvedConversation->id]);
        }

        $latestMessages = $this->getPublicMessages($resolvedConversation->id)['data'];

        return response()->json([
            'data' => $this->formatPublicMessage($result['message']),
            'messages' => $latestMessages,
            'conversation' => $this->formatPublicConversation($resolvedConversation->fresh()),
            'is_duplicate' => $result['is_duplicate'],
        ], $result['is_duplicate'] ? 200 : 201);
    }

    /**
     * Authorize visitor websocket connection for private conversation channel.
     */
    public function broadcastAuth(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'socket_id' => ['required', 'string'],
            'channel_name' => ['required', 'string'],
        ]);

        /** @var WebchatSession $session */
        $session = $request->attributes->get('webchat_session');

        $channelName = $validated['channel_name'];
        $socketId = $validated['socket_id'];

        // Expected format: private-webchat.conversation.{conversation_uuid}
        if (! preg_match('/^private-webchat\.conversation\.([a-f0-9\-]{36})$/', $channelName, $matches)) {
            return response()->json([
                'message' => 'Canal no permitido para visitantes.',
            ], 403);
        }

        $conversationId = $matches[1];

        // Verify that the conversation belongs to the visitor's session
        $isOwner = $session->active_conversation_id === $conversationId
            || Conversation::where('id', $conversationId)
                ->where('contact_id', $session->contact_id)
                ->where('channel_id', $session->channel_id)
                ->exists();

        if (! $isOwner) {
            return response()->json([
                'message' => 'No autorizado para suscribirse a esta conversación.',
            ], 403);
        }

        $key = config('reverb.apps.apps.0.key') ?: env('REVERB_APP_KEY');
        $secret = config('reverb.apps.apps.0.secret') ?: env('REVERB_APP_SECRET');

        if (! $secret) {
            return response()->json([
                'message' => 'Configuración de realtime no disponible en el servidor.',
            ], 500);
        }

        $stringToSign = "{$socketId}:{$channelName}";
        $signature = hash_hmac('sha256', $stringToSign, $secret);

        return response()->json([
            'auth' => "{$key}:{$signature}",
        ]);
    }

    protected function getPublicMessages(string $conversationId, ?string $before = null, int $limit = 50): array
    {
        $query = Message::where('conversation_id', $conversationId)
            ->whereIn('visibility', ['public', 'external']);

        if ($before) {
            $beforeMsg = Message::where('conversation_id', $conversationId)->find($before);
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

        $items = $query->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $items->count() > $limit;
        $messages = $items->take($limit)->reverse()->values();

        return [
            'data' => $messages->map(fn ($m) => $this->formatPublicMessage($m))->values()->all(),
            'has_more' => $hasMore,
            'oldest_message_id' => $messages->first()?->id,
        ];
    }

    protected function formatPublicMessage(Message $message): array
    {
        return [
            'id' => $message->id,
            'client_message_id' => $message->external_message_id ?? $message->metadata['client_message_id'] ?? null,
            'sender_type' => is_object($message->sender_type) ? $message->sender_type->value : $message->sender_type,
            'direction' => is_object($message->direction) ? $message->direction->value : $message->direction,
            'message_type' => is_object($message->message_type) ? $message->message_type->value : $message->message_type,
            'content' => $message->content,
            'payload' => $message->payload ?? [],
            'metadata' => $message->metadata,
            'sent_at' => $message->sent_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    protected function formatPublicConversation(Conversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'status' => is_object($conversation->status) ? $conversation->status->value : $conversation->status,
            'started_at' => $conversation->started_at?->toIso8601String(),
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'closed_at' => $conversation->closed_at?->toIso8601String(),
        ];
    }

    protected function createEmbedToken(string $publicKey, string $origin): string
    {
        $ttl = (int) config('webchat.embed_token_ttl_seconds', 900);
        $payload = [
            'pk' => $publicKey,
            'origin' => $origin,
            'exp' => time() + $ttl,
            'nonce' => Str::random(16),
        ];

        $json = json_encode($payload);
        $sig = hash_hmac('sha256', $json, config('app.key'));

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=').'.'.$sig;
    }

    protected function verifyEmbedToken(string $token, string $publicKey, string $origin): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return false;
        }

        [$encodedPayload, $givenSig] = $parts;
        $json = base64_decode(strtr($encodedPayload, '-_', '+/'));
        if (! $json) {
            return false;
        }

        $expectedSig = hash_hmac('sha256', $json, config('app.key'));
        if (! hash_equals($expectedSig, $givenSig)) {
            return false;
        }

        $payload = json_decode($json, true);
        if (! is_array($payload)) {
            return false;
        }

        if (($payload['exp'] ?? 0) < time()) {
            return false;
        }

        if (($payload['pk'] ?? '') !== $publicKey) {
            return false;
        }

        if ($origin !== '' && ($payload['origin'] ?? '') !== $origin) {
            return false;
        }

        return true;
    }

    protected function normalizeOrigin(string $url): string
    {
        return WebchatOriginPolicy::normalize($url);
    }
}
