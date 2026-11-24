<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\ConversationUpdated;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Channel;
use App\Services\Contacts\ContactCsvService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactController extends Controller
{
    /**
     * List external contacts that have written in the active workspace.
     */
    public function index(Request $request): JsonResponse
    {
        $this->ensureDirectoryAccess($request);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 25);

        $query = $this->eligibleContactsQuery()
            ->withCount([
                'conversations as conversations_count' => fn (Builder $conversationQuery) =>
                    $conversationQuery->where('is_simulation', false),
            ])
            ->withMax([
                'conversations as last_activity_at' => fn (Builder $conversationQuery) =>
                    $conversationQuery->where('is_simulation', false),
            ], 'last_message_at')
            ->when($search !== '', function (Builder $contactQuery) use ($search): void {
                $likeOperator = $contactQuery->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
                $likePattern = '%'.$escaped.'%';

                $contactQuery->where(function (Builder $nestedQuery) use ($likeOperator, $likePattern): void {
                    $nestedQuery
                        ->where('display_name', $likeOperator, $likePattern)
                        ->orWhere('email', $likeOperator, $likePattern)
                        ->orWhere('phone', $likeOperator, $likePattern)
                        ->orWhere('external_identifier', $likeOperator, $likePattern);
                });
            })
            ->orderByDesc('last_activity_at')
            ->orderByDesc('created_at');

        $contacts = $query->paginate($perPage);
        $contactIds = $contacts->getCollection()->pluck('id')->values();
        $channelsByContact = [];

        if ($contactIds->isNotEmpty()) {
            $channelRows = Conversation::query()
                ->join('channels', 'channels.id', '=', 'conversations.channel_id')
                ->whereIn('conversations.contact_id', $contactIds)
                ->where('conversations.is_simulation', false)
                ->whereColumn('channels.workspace_id', 'conversations.workspace_id')
                ->select([
                    'conversations.contact_id',
                    'channels.id as channel_id',
                    'channels.name as channel_name',
                    'channels.type as channel_type',
                ])
                ->distinct()
                ->orderBy('channels.name')
                ->get();

            foreach ($channelRows as $channelRow) {
                $channelsByContact[(string) $channelRow->contact_id][] = [
                    'id' => (string) $channelRow->channel_id,
                    'name' => $channelRow->channel_name,
                    'type' => (string) $channelRow->channel_type,
                ];
            }
        }

        return response()->json([
            'data' => $contacts->getCollection()
                ->map(fn (Contact $contact): array => $this->serializeSummary($contact, $channelsByContact[(string) $contact->id] ?? []))
                ->values(),
            'meta' => [
                'current_page' => $contacts->currentPage(),
                'last_page' => $contacts->lastPage(),
                'per_page' => $contacts->perPage(),
                'total' => $contacts->total(),
            ],
        ]);
    }

    /**
     * Return a contact profile with captured variables and conversation history.
     */
    public function show(Request $request, Contact $contact): JsonResponse
    {
        $this->ensureDirectoryAccess($request);

        $isImported = data_get($contact->metadata, 'directory_source') === 'import';
        abort_unless(
            $isImported || $contact->conversations()->where('is_simulation', false)->exists(),
            404,
            'El contacto no tiene conversaciones reales ni fue importado en este Workspace.'
        );

        $contact->load([
            'conversations' => function ($conversationQuery): void {
                $conversationQuery
                    ->where('is_simulation', false)
                    ->select(['id', 'contact_id', 'channel_id', 'status', 'last_message_at', 'started_at', 'closed_at'])
                    ->with('channel:id,name,type')
                    ->orderByDesc('last_message_at');
            },
        ]);

        $summary = $this->serializeSummary($contact);
        $summary['conversations'] = $contact->conversations
            ->map(function (Conversation $conversation): array {
                $channel = $conversation->channel;
                $channelType = is_object($channel?->type) ? $channel->type->value : (string) ($channel?->type ?? '');

                return [
                    'id' => (string) $conversation->id,
                    'status' => is_object($conversation->status) ? $conversation->status->value : (string) $conversation->status,
                    'channel' => $channel ? [
                        'id' => (string) $channel->id,
                        'name' => $channel->name,
                        'type' => $channelType,
                    ] : null,
                    'started_at' => $conversation->started_at?->toIso8601String(),
                    'last_activity_at' => $conversation->last_message_at?->toIso8601String(),
                    'closed_at' => $conversation->closed_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return response()->json(['data' => $summary]);
    }

    public function previewImport(Request $request, ContactCsvService $service): JsonResponse
    {
        $this->ensureDirectoryAccess($request);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        try {
            $preview = $service->preview($validated['file']);
            $preview['available_channels'] = Channel::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'type'])
                ->map(fn (Channel $channel): array => [
                    'id' => (string) $channel->id,
                    'name' => $channel->name,
                    'type' => is_object($channel->type) ? $channel->type->value : (string) $channel->type,
                ])
                ->values()
                ->all();

            return response()->json(['data' => $preview]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function importCsv(Request $request, ContactCsvService $service): JsonResponse
    {
        $this->ensureDirectoryAccess($request);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'mapping' => ['required'],
        ]);
        $mapping = $request->input('mapping');
        if (is_string($mapping)) {
            $mapping = json_decode($mapping, true);
        }
        if (! is_array($mapping)) {
            return response()->json(['message' => 'El mapeo de columnas debe ser un objeto válido.'], 422);
        }
        $destinationChannelId = $request->input('destination_channel_id');
        if (! is_string($destinationChannelId) || $destinationChannelId === '') {
            return response()->json(['message' => 'Selecciona un canal destino antes de importar.'], 422);
        }

        try {
            return response()->json([
                'message' => 'Contactos importados correctamente.',
                'data' => $service->import($validated['file'], $mapping, $destinationChannelId),
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function export(Request $request, ContactCsvService $service): StreamedResponse
    {
        $this->ensureDirectoryAccess($request);

        $filename = 'intera-contactos-'.($request->user()->active_workspace_id ?? 'workspace').'-'.now()->format('Ymd-His').'.csv';
        $query = $this->eligibleContactsQuery()->orderBy('id');

        return response()->streamDownload(function () use ($query, $service): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            $service->writeExport($query->cursor(), $handle);
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function update(Request $request, Contact $contact): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'display_name' => ['sometimes', 'string', 'max:150'],
        ]);

        $name = $validated['name'] ?? $validated['display_name'] ?? null;
        if (! $name) {
            return response()->json(['message' => 'El campo name es requerido.'], 422);
        }

        $contact->update(['display_name' => $name]);

        // Broadcast ConversationUpdated on active conversations for this contact
        $conversations = Conversation::where('contact_id', $contact->id)
            ->whereIn('status', ['bot', 'waiting_agent', 'assigned'])
            ->with(['channel', 'contact', 'assignedUser'])
            ->get();

        foreach ($conversations as $conversation) {
            ConversationUpdated::dispatch($conversation);
        }

        return response()->json([
            'message' => 'Contacto actualizado correctamente.',
            'contact' => $contact,
        ]);
    }

    private function ensureDirectoryAccess(Request $request): void
    {
        $user = $request->user();
        $isAllowed = (bool) $user?->is_platform_admin
            || (bool) $user?->hasRole(['super_admin', 'admin']);

        abort_unless($isAllowed, 403, 'Solo los administradores pueden consultar el directorio de contactos.');
    }

    private function eligibleContactsQuery(): Builder
    {
        return Contact::query()->where(function (Builder $contactQuery): void {
            $contactQuery
                ->whereHas('conversations', fn (Builder $conversationQuery) =>
                    $conversationQuery->where('is_simulation', false)
                )
                ->orWhere('metadata->directory_source', 'import');
        });
    }

    /** @return array<string, mixed> */
    private function serializeSummary(Contact $contact, ?array $channelSummary = null): array
    {
        $conversations = $contact->relationLoaded('conversations')
            ? $contact->conversations
            : collect();

        $channels = $channelSummary ?? $conversations
            ->map(fn (Conversation $conversation) => $conversation->channel)
            ->filter()
            ->unique('id')
            ->values()
            ->map(function ($channel): array {
                $channelType = is_object($channel->type) ? $channel->type->value : (string) $channel->type;

                return [
                    'id' => (string) $channel->id,
                    'name' => $channel->name,
                    'type' => $channelType,
                ];
            })
            ->all();

        $linkedChannelIds = data_get($contact->metadata, 'contact_import.linked_channel_ids', []);
        $linkedChannels = is_array($linkedChannelIds) && $linkedChannelIds !== []
            ? Channel::query()->whereIn('id', $linkedChannelIds)->get(['id', 'name', 'type'])
            : collect();
        foreach ($linkedChannels as $linkedChannel) {
            $channelType = is_object($linkedChannel->type) ? $linkedChannel->type->value : (string) $linkedChannel->type;
            $channels[] = [
                'id' => (string) $linkedChannel->id,
                'name' => $linkedChannel->name,
                'type' => $channelType,
            ];
        }

        $channels = collect($channels)
            ->unique(fn (array $channel): string => $channel['id'].'|'.$channel['type'])
            ->values()
            ->all();

        $variables = $this->extractVariables($contact);
        $lastActivity = $contact->getAttribute('last_activity_at')
            ?: $conversations->first()?->last_message_at
            ?: $contact->updated_at;
        if (is_string($lastActivity)) {
            $lastActivity = Carbon::parse($lastActivity);
        }

        return [
            'id' => (string) $contact->id,
            'display_name' => $contact->display_name,
            'external_identifier' => $contact->external_identifier,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'country' => $contact->country,
            'conversations_count' => (int) ($contact->getAttribute('conversations_count') ?? $conversations->count()),
            'last_activity_at' => $lastActivity?->toIso8601String(),
            'channels' => $channels,
            'variables' => $variables,
            'variable_count' => count($variables),
            'attributes' => $this->extractAttributes($contact),
        ];
    }

    /** @return array<string, mixed> */
    private function extractVariables(Contact $contact): array
    {
        $metadata = is_array($contact->metadata) ? $contact->metadata : [];
        $stored = $metadata['flow_variables'] ?? $metadata['variables'] ?? [];
        if (! is_array($stored)) {
            return [];
        }

        $variables = [];
        foreach ($stored as $key => $value) {
            $key = ltrim((string) $key, '$');
            if ($key === '' || str_starts_with($key, '_') || $this->isSensitiveKey($key)) {
                continue;
            }

            $variables[$key] = $this->sanitizeMetadataValue($value);
        }

        return $variables;
    }

    /** @return array<string, mixed> */
    private function extractAttributes(Contact $contact): array
    {
        $metadata = is_array($contact->metadata) ? $contact->metadata : [];
        $attributes = [];

        foreach ($metadata as $key => $value) {
            $key = (string) $key;
            if (in_array($key, ['flow_variables', 'variables', 'directory_source'], true) || str_starts_with($key, '_') || $this->isSensitiveKey($key)) {
                continue;
            }

            $attributes[$key] = $this->sanitizeMetadataValue($value);
        }

        return $attributes;
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match('/token|secret|password|access[_-]?key|api[_-]?key|authorization|credential/i', $key) === 1;
    }

    private function sanitizeMetadataValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sanitized = [];
        foreach ($value as $key => $nestedValue) {
            $key = (string) $key;
            if (str_starts_with($key, '_') || $this->isSensitiveKey($key)) {
                continue;
            }

            $sanitized[$key] = $this->sanitizeMetadataValue($nestedValue);
        }

        return $sanitized;
    }
}
