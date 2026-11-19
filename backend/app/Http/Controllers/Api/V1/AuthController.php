<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WorkspaceUser;
use App\Services\AuditLogger;
use App\Services\Presence\AgentPresenceHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        $throttleKey = Str::transliterate(Str::lower($validated['email']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            AuditLogger::log(
                action: 'auth.rate_limited',
                entityType: 'User',
                metadata: ['email' => $validated['email'], 'retry_after' => $seconds]
            );

            return response()->json([
                'message' => 'Demasiados intentos fallidos. Intente nuevamente en '.$seconds.' segundos.',
            ], 429);
        }

        $user = User::with('roles.permissions')->where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            AuditLogger::log(
                action: 'auth.login_failed',
                entityType: 'User',
                metadata: ['email' => $validated['email']]
            );

            return response()->json([
                'message' => 'Credenciales inválidas.',
            ], 401);
        }

        if (! $user->is_active) {
            RateLimiter::hit($throttleKey, 60);

            AuditLogger::log(
                action: 'auth.login_inactive_blocked',
                entityType: 'User',
                entityId: (string) $user->id,
                metadata: ['email' => $user->email],
                userId: $user->id
            );

            return response()->json([
                'message' => 'Usuario inactivo. Contacte al administrador.',
            ], 403);
        }

        RateLimiter::clear($throttleKey);

        $this->ensureActiveWorkspace($user);

        $user->update(['last_login_at' => now()]);

        // Establish first-party web session for Sanctum SPA cookie auth
        Auth::guard('web')->login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        AuditLogger::log(
            action: 'auth.login',
            entityType: 'User',
            entityId: (string) $user->id,
            metadata: ['email' => $user->email],
            userId: $user->id
        );

        $activeMembership = $user->workspaceMembership($user->active_workspace_id);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'is_platform_admin' => (bool) $user->is_platform_admin,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'roles' => $activeMembership?->role
                ? [$activeMembership->role->name]
                : $user->roles->pluck('name')->values(),
            'permissions' => $user->allPermissions($user->active_workspace_id)->pluck('name')->values(),
            'workspace' => $this->workspacePayload($user, $user->active_workspace_id),
            'workspaces' => $this->workspacesPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            if ($request->hasSession()) {
                app(AgentPresenceHistoryService::class)->endSession($user, $request->session()->getId());
            }

            $token = $user->currentAccessToken();
            if ($token && method_exists($token, 'delete')) {
                $token->delete();
            }

            AuditLogger::log(
                action: 'auth.logout',
                entityType: 'User',
                entityId: (string) $user->id,
                metadata: ['email' => $user->email],
                userId: $user->id
            );
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('roles.permissions');
        $this->ensureActiveWorkspace($user);

        $activeMembership = $user->workspaceMembership($user->active_workspace_id);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'is_platform_admin' => (bool) $user->is_platform_admin,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'roles' => $activeMembership?->role ? [$activeMembership->role->name] : $user->roles->pluck('name')->values(),
            'permissions' => $user->allPermissions($user->active_workspace_id)->pluck('name')->values(),
            'workspace' => $this->workspacePayload($user, $user->active_workspace_id),
            'workspaces' => $this->workspacesPayload($user),
        ]);
    }

    private function ensureActiveWorkspace(User $user): void
    {
        $memberships = $user->workspaceMemberships()
            ->with('workspace')
            ->where('is_active', true)
            ->whereHas('workspace', fn ($query) => $query->where('status', 'active'))
            ->orderBy('created_at')
            ->get();

        if ($memberships->isEmpty()) {
            return;
        }

        $active = $memberships->first(fn (WorkspaceUser $membership) =>
            (string) $membership->workspace_id === (string) $user->active_workspace_id
        );
        $selected = $active ?? $memberships->first();

        if ((string) $selected->workspace_id !== (string) $user->active_workspace_id) {
            $user->forceFill(['active_workspace_id' => $selected->workspace_id])->save();
        }
    }

    private function workspacePayload(User $user, ?string $workspaceId): ?array
    {
        if (! $workspaceId) {
            return null;
        }

        $membership = WorkspaceUser::query()
            ->with(['workspace', 'role'])
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (! $membership?->workspace) {
            return null;
        }

        return [
            'id' => (string) $membership->workspace_id,
            'name' => $membership->workspace->name,
            'slug' => $membership->workspace->slug,
            'role' => $membership->role?->name,
        ];
    }

    private function workspacesPayload(User $user): array
    {
        return $user->workspaceMemberships()
            ->with(['workspace', 'role'])
            ->where('is_active', true)
            ->whereHas('workspace', fn ($query) => $query->where('status', 'active'))
            ->orderBy('created_at')
            ->get()
            ->map(fn (WorkspaceUser $membership) => [
                'id' => (string) $membership->workspace_id,
                'name' => $membership->workspace?->name,
                'slug' => $membership->workspace?->slug,
                'role' => $membership->role?->name,
            ])
            ->values()
            ->all();
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'La contraseña actual no es correcta.',
                'errors' => [
                    'current_password' => ['La contraseña actual no coincide con nuestros registros.'],
                ],
            ], 422);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        // Invalidate other Personal Access Tokens if present, while keeping current SPA session
        $currentToken = $user->currentAccessToken();
        if ($currentToken && method_exists($currentToken, 'delete')) {
            $user->tokens()->where('id', '!=', $currentToken->id)->delete();
        }

        AuditLogger::log(
            action: 'user.password_changed',
            entityType: 'User',
            entityId: (string) $user->id,
            metadata: ['user_id' => $user->id, 'timestamp' => now()->toIso8601String()],
            userId: $user->id
        );

        return response()->json([
            'message' => 'Contraseña actualizada exitosamente.',
        ]);
    }
}
