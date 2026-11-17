<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use App\Services\Routing\ConversationQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamController extends Controller
{
    public function __construct(private readonly ConversationQueueService $queueService) {}

    public function index(Request $request): JsonResponse
    {
        $teams = Team::query()
            ->where('is_active', true)
            ->when(! $request->user()->hasPermission('teams.manage'), fn ($query) => $query->whereHas('members', fn ($members) => $members->where('users.id', $request->user()->id)))
            ->with(['members:id,name,email', 'channels:id,name,type,is_active'])
            ->withCount(['conversations as waiting_count' => fn ($query) => $query->where('status', 'waiting_agent')->whereNull('assigned_user_id')])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        foreach ($teams as $team) {
            $entries = DB::table('conversation_queue_entries')
                ->where('team_id', $team->id)
                ->where('exit_reason', 'assigned')
                ->where('assigned_at', '>=', now()->startOfDay())
                ->whereNotNull('assigned_at')
                ->get(['entered_at', 'assigned_at']);

            $waits = $entries->map(fn ($entry) => max(0, strtotime($entry->assigned_at) - strtotime($entry->entered_at)));
            if ($team->waiting_count > 0) {
                $waits = $waits->concat(DB::table('conversation_queue_entries')
                    ->where('team_id', $team->id)
                    ->whereNull('ended_at')
                    ->get(['entered_at'])
                    ->map(fn ($entry) => max(0, now()->timestamp - strtotime($entry->entered_at))));
            }
            $team->setAttribute('average_wait_seconds', $waits->isNotEmpty() ? (int) round($waits->avg()) : 0);
        }

        return response()->json(['data' => $teams]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateTeam($request, null);
        $team = DB::transaction(function () use ($validated) {
            $team = Team::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'queue_wait_target_seconds' => $validated['queue_wait_target_seconds'] ?? null,
                'first_response_target_seconds' => $validated['first_response_target_seconds'] ?? null,
                'is_default' => false,
                'is_active' => true,
            ]);
            $this->syncTeamRelations($team, $validated);
            return $team;
        });

        return response()->json(['message' => 'Equipo creado correctamente.', 'data' => $team->load(['members:id,name,email', 'channels:id,name,type,is_active'])], 201);
    }

    public function update(Request $request, Team $team): JsonResponse
    {
        $validated = $this->validateTeam($request, $team);
        $oldChannelIds = $team->channels()->pluck('channels.id')->all();

        DB::transaction(function () use ($team, $validated) {
            $team->update([
                'name' => $validated['name'] ?? $team->name,
                'description' => array_key_exists('description', $validated) ? $validated['description'] : $team->description,
                'queue_wait_target_seconds' => array_key_exists('queue_wait_target_seconds', $validated)
                    ? $validated['queue_wait_target_seconds']
                    : $team->queue_wait_target_seconds,
                'first_response_target_seconds' => array_key_exists('first_response_target_seconds', $validated)
                    ? $validated['first_response_target_seconds']
                    : $team->first_response_target_seconds,
            ]);
            $this->syncTeamRelations($team, $validated);
        });

        $newChannelIds = $team->channels()->pluck('channels.id')->all();
        $this->queueService->routeWaitingForChannels(array_values(array_unique([...$oldChannelIds, ...$newChannelIds])));

        return response()->json(['message' => 'Equipo actualizado correctamente.', 'data' => $team->fresh()->load(['members:id,name,email', 'channels:id,name,type,is_active'])]);
    }

    private function validateTeam(Request $request, ?Team $team): array
    {
        $validated = $request->validate([
            'name' => [$team ? 'sometimes' : 'required', 'string', 'min:2', 'max:120', Rule::unique('teams', 'name')->ignore($team?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'queue_wait_target_seconds' => ['sometimes', 'nullable', 'integer', 'min:60', 'max:86400'],
            'first_response_target_seconds' => ['sometimes', 'nullable', 'integer', 'min:60', 'max:86400'],
            'member_ids' => ['sometimes', 'array'],
            'member_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'channel_ids' => ['sometimes', 'array'],
            'channel_ids.*' => ['uuid', 'distinct', 'exists:channels,id'],
        ]);

        if (array_key_exists('member_ids', $validated)) {
            $invalid = User::query()->whereIn('id', $validated['member_ids'])
                ->whereDoesntHave('roles', fn ($query) => $query->whereIn('name', ['agent', 'admin', 'supervisor', 'super_admin']))
                ->exists();
            if ($invalid) {
                throw ValidationException::withMessages(['member_ids' => 'Solo se pueden agregar personas con un rol de atención o supervisión.']);
            }
        }

        if (array_key_exists('channel_ids', $validated)) {
            $conflict = DB::table('team_channels')
                ->whereIn('channel_id', $validated['channel_ids'])
                ->when($team, fn ($query) => $query->where('team_id', '!=', $team->id))
                ->exists();
            if ($conflict) {
                throw ValidationException::withMessages(['channel_ids' => 'Cada canal solo puede dirigir conversaciones a un equipo. Quita el canal del otro equipo primero.']);
            }
        }

        return $validated;
    }

    private function syncTeamRelations(Team $team, array $validated): void
    {
        if (array_key_exists('member_ids', $validated)) {
            $team->members()->sync($validated['member_ids']);
        }

        if (array_key_exists('channel_ids', $validated)) {
            $team->channels()->sync($validated['channel_ids']);
        }
    }
}
