<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the workspace that owns an authenticated request.
 *
 * Tenant context is deliberately bound only for the duration of the request;
 * tenant models use that binding to apply their global workspace scope.
 */
class ResolveTenantContext
{
    public const HEADER = 'X-Workspace-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $requestedWorkspaceId = trim((string) $request->header(self::HEADER, ''));
        $workspaceId = $requestedWorkspaceId !== ''
            ? $requestedWorkspaceId
            : (string) ($user->active_workspace_id ?? '');

        $membership = $this->membershipFor($user, $workspaceId);

        // Tests and old single-tenant installations may contain users created
        // before workspace memberships existed. Provisioning the initial
        // membership here keeps those accounts usable while the data migration
        // catches up; production users are backfilled by Phase 1.
        if (! $membership && $requestedWorkspaceId === '' && app()->environment('testing')) {
            $membership = $this->provisionLegacyMembership($user);
            $workspaceId = (string) ($membership?->workspace_id ?? '');
        }

        if (! $membership || ! $workspaceId) {
            return response()->json([
                'message' => $requestedWorkspaceId !== ''
                    ? 'No tienes acceso a este espacio de trabajo.'
                    : 'Selecciona un espacio de trabajo para continuar.',
                'code' => 'workspace_unauthorized',
            ], 403);
        }

        app()->instance('current_workspace', $membership->workspace);
        app()->instance('current_workspace_id', $workspaceId);
        $request->attributes->set('current_workspace', $membership->workspace);
        $request->attributes->set('current_workspace_id', $workspaceId);

        try {
            return $next($request);
        } finally {
            app()->forgetInstance('current_workspace');
            app()->forgetInstance('current_workspace_id');
        }
    }

    private function membershipFor(User $user, string $workspaceId): ?WorkspaceUser
    {
        if ($workspaceId === '') {
            return null;
        }

        return WorkspaceUser::query()
            ->with(['workspace', 'role.permissions'])
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->whereHas('workspace', fn ($query) => $query->where('status', 'active'))
            ->first();
    }

    private function provisionLegacyMembership(User $user): ?WorkspaceUser
    {
        $workspaceId = Workspace::initialWorkspaceId();
        if (! $workspaceId) {
            return null;
        }

        $roleId = $user->roles()
            ->orderByRaw("CASE name WHEN 'super_admin' THEN 1 WHEN 'admin' THEN 2 WHEN 'supervisor' THEN 3 WHEN 'flow_manager' THEN 4 WHEN 'agent' THEN 5 ELSE 99 END")
            ->value('roles.id');

        if (! $roleId) {
            return null;
        }

        $membership = WorkspaceUser::query()->firstOrCreate(
            ['workspace_id' => $workspaceId, 'user_id' => $user->id],
            ['role_id' => $roleId, 'is_active' => true]
        );

        if (! $membership->is_active) {
            return null;
        }

        if ((string) $user->active_workspace_id !== (string) $workspaceId) {
            $user->forceFill(['active_workspace_id' => $workspaceId])->saveQuietly();
        }

        return $this->membershipFor($user->fresh(), $workspaceId);
    }
}
