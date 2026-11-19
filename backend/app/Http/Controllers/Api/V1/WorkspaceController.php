<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $memberships = $this->activeMemberships($user);

        return response()->json([
            'data' => $memberships->map(fn (WorkspaceUser $membership) => $this->formatMembership($membership))->values(),
            'active_workspace_id' => $user->active_workspace_id,
        ]);
    }

    public function switchWorkspace(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workspace_id' => ['required', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $membership = WorkspaceUser::query()
            ->with(['workspace', 'role.permissions'])
            ->where('user_id', $user->id)
            ->where('workspace_id', $validated['workspace_id'])
            ->where('is_active', true)
            ->whereHas('workspace', fn ($query) => $query->where('status', 'active'))
            ->first();

        if (! $membership) {
            return response()->json([
                'message' => 'No tienes acceso a este espacio de trabajo.',
                'code' => 'workspace_unauthorized',
            ], 403);
        }

        $previousWorkspaceId = $user->active_workspace_id;

        DB::transaction(function () use ($user, $membership, $previousWorkspaceId): void {
            if ($previousWorkspaceId && (string) $previousWorkspaceId !== (string) $membership->workspace_id) {
                DB::table('agent_presence_sessions')
                    ->where('user_id', $user->id)
                    ->where('workspace_id', $previousWorkspaceId)
                    ->whereNull('ended_at')
                    ->update(['ended_at' => now()]);
            }

            $user->forceFill(['active_workspace_id' => $membership->workspace_id])->save();
        });

        AuditLogger::log(
            action: 'workspace.switched',
            entityType: 'Workspace',
            entityId: (string) $membership->workspace_id,
            metadata: [
                'previous_workspace_id' => $previousWorkspaceId,
                'workspace_id' => $membership->workspace_id,
            ],
            userId: $user->id,
            workspaceId: $membership->workspace_id,
        );

        return response()->json([
            'message' => 'Espacio de trabajo cambiado correctamente.',
            'data' => $this->formatMembership($membership),
            'active_workspace_id' => $membership->workspace_id,
        ]);
    }

    private function activeMemberships(User $user)
    {
        return WorkspaceUser::query()
            ->with(['workspace', 'role'])
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereHas('workspace', fn ($query) => $query->where('status', 'active'))
            ->orderBy('created_at')
            ->get();
    }

    private function formatMembership(WorkspaceUser $membership): array
    {
        return [
            'id' => (string) $membership->workspace_id,
            'name' => $membership->workspace?->name,
            'slug' => $membership->workspace?->slug,
            'status' => $membership->workspace?->status,
            'role' => $membership->role ? [
                'id' => $membership->role->id,
                'name' => $membership->role->name,
                'display_name' => $membership->role->display_name,
            ] : null,
            'is_active' => (bool) $membership->is_active,
        ];
    }
}
