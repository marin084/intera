<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($this->canManageAnyWorkspace($user)) {
            $workspaces = Workspace::query()
                ->withCount(['memberships as active_member_count' => fn ($query) => $query->where('is_active', true)])
                ->with(['memberships' => fn ($query) => $query->where('user_id', $user->id)->where('is_active', true)->with('role')])
                ->where('status', 'active')
                ->orderBy('created_at')
                ->get();

            return response()->json([
                'data' => $workspaces->map(fn (Workspace $workspace) => $this->formatWorkspace($workspace, $workspace->memberships->first()))->values(),
                'active_workspace_id' => $user->active_workspace_id,
            ]);
        }

        $memberships = $this->activeMemberships($user);

        return response()->json([
            'data' => $memberships->map(fn (WorkspaceUser $membership) => $this->formatMembership($membership))->values(),
            'active_workspace_id' => $user->active_workspace_id,
        ]);
    }

    public function switchWorkspace(Request $request): JsonResponse
    {
        $validated = $request->validate(['workspace_id' => ['required', 'uuid']]);
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
            return response()->json(['message' => 'No tienes acceso a este workspace.', 'code' => 'workspace_unauthorized'], 403);
        }

        $previousWorkspaceId = $user->active_workspace_id;
        DB::transaction(function () use ($user, $membership, $previousWorkspaceId): void {
            if ($previousWorkspaceId && (string) $previousWorkspaceId !== (string) $membership->workspace_id) {
                DB::table('agent_presence_sessions')->where('user_id', $user->id)->where('workspace_id', $previousWorkspaceId)->whereNull('ended_at')->update(['ended_at' => now()]);
            }
            $user->forceFill(['active_workspace_id' => $membership->workspace_id])->save();
        });

        AuditLogger::log(
            action: 'workspace.switched',
            entityType: 'Workspace',
            entityId: (string) $membership->workspace_id,
            metadata: ['previous_workspace_id' => $previousWorkspaceId, 'workspace_id' => $membership->workspace_id],
            userId: $user->id,
            workspaceId: $membership->workspace_id,
        );

        return response()->json(['message' => 'Workspace cambiado correctamente.', 'data' => $this->formatMembership($membership), 'active_workspace_id' => $membership->workspace_id]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->ensurePlatformAdministrator($user);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
        ]);
        $name = trim($validated['name']);
        $slug = $this->resolveUniqueSlug($validated['slug'] ?? $name);
        $superAdminRole = Role::query()->where('name', 'super_admin')->first();
        if (! $superAdminRole) {
            return response()->json(['message' => 'No se encontró el rol de administrador de workspaces.'], 422);
        }

        $workspace = DB::transaction(function () use ($name, $slug, $user, $superAdminRole): Workspace {
            $workspace = Workspace::create(['name' => $name, 'slug' => $slug, 'status' => 'active', 'initial_owner_id' => $user->id, 'configuration' => []]);
            WorkspaceUser::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role_id' => $superAdminRole->id, 'is_active' => true]);
            return $workspace;
        });

        AuditLogger::log(action: 'workspace.created', entityType: 'Workspace', entityId: (string) $workspace->id, metadata: ['name' => $workspace->name, 'slug' => $workspace->slug], userId: $user->id, workspaceId: $workspace->id);
        $workspace->loadCount(['memberships as active_member_count' => fn ($query) => $query->where('is_active', true)]);
        $membership = WorkspaceUser::query()->with('role')->where('workspace_id', $workspace->id)->where('user_id', $user->id)->first();

        return response()->json(['message' => 'Workspace creado correctamente.', 'data' => $this->formatWorkspace($workspace, $membership)], 201);
    }

    public function members(Request $request, Workspace $workspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->ensureWorkspaceManager($user, $workspace);
        return response()->json([
            'data' => $workspace->memberships()->with(['user', 'role'])->orderByDesc('is_active')->orderBy('created_at')->get()->map(fn (WorkspaceUser $membership) => $this->formatWorkspaceMember($membership))->values(),
            'workspace' => $this->formatWorkspace($workspace),
        ]);
    }

    public function addMember(Request $request, Workspace $workspace): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->ensureWorkspaceManager($actor, $workspace);
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', 'in:super_admin,admin,supervisor,flow_manager,agent'],
        ]);
        if ($workspace->status !== 'active') {
            return response()->json(['message' => 'El workspace no está activo.'], 422);
        }
        $member = User::query()->whereRaw('LOWER(email) = ?', [strtolower(trim($validated['email']))])->first();
        if (! $member) {
            return response()->json(['message' => 'No existe un usuario con ese correo. Créalo primero desde Usuarios.', 'code' => 'workspace_user_not_found'], 422);
        }
        $role = Role::query()->where('name', $validated['role'])->first();
        if (! $role) {
            return response()->json(['message' => 'El rol seleccionado no está disponible.'], 422);
        }
        $membership = WorkspaceUser::updateOrCreate(['workspace_id' => $workspace->id, 'user_id' => $member->id], ['role_id' => $role->id, 'is_active' => true]);
        $membership->load(['user', 'role', 'workspace']);
        AuditLogger::log(action: 'workspace.member_added', entityType: 'WorkspaceUser', entityId: (string) $membership->id, metadata: ['member_id' => $member->id, 'role' => $role->name], userId: $actor->id, workspaceId: $workspace->id);
        return response()->json(['message' => 'Integrante agregado al workspace.', 'data' => $this->formatWorkspaceMember($membership)], 201);
    }

    public function updateMember(Request $request, Workspace $workspace, User $member): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->ensureWorkspaceManager($actor, $workspace);
        $validated = $request->validate([
            'role' => ['sometimes', 'string', 'in:super_admin,admin,supervisor,flow_manager,agent'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $membership = WorkspaceUser::query()->where('workspace_id', $workspace->id)->where('user_id', $member->id)->with(['user', 'role', 'workspace'])->first();
        if (! $membership) {
            return response()->json(['message' => 'El usuario no pertenece a este workspace.'], 404);
        }
        $nextRole = $validated['role'] ?? $membership->role?->name;
        $nextActive = array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : $membership->is_active;
        $this->ensureMembershipChangeIsSafe($workspace, $membership, $nextRole, $nextActive);
        if (isset($validated['role'])) {
            $membership->role_id = Role::query()->where('name', $validated['role'])->value('id');
        }
        if (array_key_exists('is_active', $validated)) {
            $membership->is_active = $nextActive;
        }
        $membership->save();
        $membership->load(['user', 'role', 'workspace']);
        AuditLogger::log(action: 'workspace.member_updated', entityType: 'WorkspaceUser', entityId: (string) $membership->id, metadata: ['member_id' => $member->id, 'role' => $membership->role?->name, 'is_active' => $membership->is_active], userId: $actor->id, workspaceId: $workspace->id);
        return response()->json(['message' => 'Integrante actualizado correctamente.', 'data' => $this->formatWorkspaceMember($membership)]);
    }

    public function removeMember(Request $request, Workspace $workspace, User $member): JsonResponse
    {
        $request->merge(['is_active' => false]);
        return $this->updateMember($request, $workspace, $member);
    }

    private function activeMemberships(User $user)
    {
        return WorkspaceUser::query()->with(['workspace', 'role'])->where('user_id', $user->id)->where('is_active', true)->whereHas('workspace', fn ($query) => $query->where('status', 'active'))->orderBy('created_at')->get();
    }

    private function formatMembership(WorkspaceUser $membership): array
    {
        return $this->formatWorkspace($membership->workspace, $membership);
    }

    private function formatWorkspace(?Workspace $workspace, ?WorkspaceUser $membership = null): array
    {
        return [
            'id' => (string) $workspace?->id,
            'name' => $workspace?->name,
            'slug' => $workspace?->slug,
            'status' => $workspace?->status,
            'initial_owner_id' => $workspace?->initial_owner_id,
            'member_count' => isset($workspace?->active_member_count) ? (int) $workspace->active_member_count : null,
            'role' => $membership?->role ? ['id' => $membership->role->id, 'name' => $membership->role->name, 'display_name' => $membership->role->display_name] : null,
            'is_active' => $membership ? (bool) $membership->is_active : true,
            'can_switch' => $membership ? (bool) $membership->is_active : false,
        ];
    }

    private function formatWorkspaceMember(WorkspaceUser $membership): array
    {
        return [
            'id' => (int) $membership->user_id,
            'membership_id' => (int) $membership->id,
            'name' => $membership->user?->name,
            'email' => $membership->user?->email,
            'role' => $membership->role ? ['id' => $membership->role->id, 'name' => $membership->role->name, 'display_name' => $membership->role->display_name] : null,
            'is_active' => (bool) $membership->is_active,
            'is_owner' => (int) $membership->workspace?->initial_owner_id === (int) $membership->user_id,
        ];
    }

    private function canManageAnyWorkspace(User $user): bool
    {
        return (bool) $user->is_platform_admin || $user->hasRole(['super_admin', 'admin']);
    }

    private function ensurePlatformAdministrator(User $user): void
    {
        abort_unless($this->canManageAnyWorkspace($user), 403, 'No tienes permiso para administrar workspaces.');
    }

    private function ensureWorkspaceManager(User $user, Workspace $workspace): void
    {
        if ($this->canManageAnyWorkspace($user)) {
            return;
        }
        $isManager = WorkspaceUser::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->where('is_active', true)->whereHas('role', fn ($query) => $query->whereIn('name', ['super_admin', 'admin']))->exists();
        abort_unless($isManager, 403, 'No tienes permiso para administrar este workspace.');
    }

    private function resolveUniqueSlug(string $value): string
    {
        $base = Str::slug($value) ?: 'workspace';
        $slug = Str::limit($base, 72, '');
        $suffix = 1;
        while (Workspace::query()->where('slug', $slug)->exists()) {
            $slug = Str::limit($base.'-'.$suffix++, 80, '');
        }
        return $slug;
    }

    private function ensureMembershipChangeIsSafe(Workspace $workspace, WorkspaceUser $membership, ?string $nextRole, bool $nextActive): void
    {
        $isOwner = (int) $workspace->initial_owner_id === (int) $membership->user_id;
        if ($isOwner && (! $nextActive || $nextRole !== 'super_admin')) {
            abort(422, 'El propietario inicial debe conservar acceso activo como administrador del workspace.');
        }

        $currentlySoleAdmin = $membership->is_active
            && $membership->role?->name === 'super_admin'
            && (! $nextActive || $nextRole !== 'super_admin')
            && ! $workspace->memberships()->where('is_active', true)->where('role_id', $membership->role_id)->where('id', '!=', $membership->id)->exists();
        if ($currentlySoleAdmin) {
            abort(422, 'El workspace debe conservar al menos un administrador activo.');
        }
    }
}
