<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::with(['roles.permissions', 'channels:id,name,type,is_active'])->orderBy('id', 'desc')->get();

        return response()->json([
            'data' => $users->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'created_at' => $user->created_at->toIso8601String(),
                'roles' => $user->roles->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'display_name' => $r->display_name,
                ]),
                'channel_access_scope' => $user->hasRole('super_admin') ? 'all' : 'selected',
                'accessible_channels' => $user->hasRole('super_admin')
                    ? Channel::query()->orderBy('name')->get(['id', 'name', 'type', 'is_active'])->map(fn (Channel $channel) => $this->formatAccessibleChannel($channel))->values()
                    : $user->channels->sortBy('name')->map(fn (Channel $channel) => $this->formatAccessibleChannel($channel))->values(),
            ]),
        ]);
    }

    private function formatAccessibleChannel(Channel $channel): array
    {
        return [
            'id' => (string) $channel->id,
            'name' => $channel->name,
            'type' => $channel->type->value,
            'is_active' => $channel->is_active,
        ];
    }

    public function updateChannels(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'channel_ids' => ['present', 'array'],
            'channel_ids.*' => ['required', 'uuid', 'distinct', 'exists:channels,id'],
        ]);

        if ($user->hasRole('super_admin')) {
            return response()->json(['message' => 'El superadministrador conserva acceso a todos los canales.'], 422);
        }

        $user->channels()->sync($validated['channel_ids']);
        $user->load('channels:id,name,type,is_active');

        AuditLogger::log(
            action: 'user.channels.update',
            entityType: 'User',
            entityId: (string) $user->id,
            metadata: ['channel_ids' => $user->channels->pluck('id')->all()]
        );

        return response()->json([
            'message' => 'Canales del usuario actualizados correctamente.',
            'data' => $user->channels->sortBy('name')->map(fn (Channel $channel) => $this->formatAccessibleChannel($channel))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12'],
            'is_active' => ['boolean'],
            'roles' => ['array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if (! empty($validated['roles'])) {
            $roleIds = Role::whereIn('name', $validated['roles'])->pluck('id');
            $user->roles()->sync($roleIds);
        }

        $user->load('roles');

        AuditLogger::log(
            action: 'user.create',
            entityType: 'User',
            entityId: (string) $user->id,
            metadata: ['email' => $user->email, 'roles' => $validated['roles'] ?? []]
        );

        return response()->json([
            'message' => 'Usuario creado exitosamente.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'roles' => $user->roles->pluck('name'),
            ],
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        $user->load('roles.permissions');

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'created_at' => $user->created_at->toIso8601String(),
                'roles' => $user->roles->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'display_name' => $r->display_name,
                ]),
                'permissions' => $user->allPermissions()->pluck('name')->values(),
            ],
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:12'],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

        $updateData = [];
        if (isset($validated['name'])) {
            $updateData['name'] = $validated['name'];
        }
        if (isset($validated['email'])) {
            $updateData['email'] = $validated['email'];
        }
        if (! empty($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
        }
        if (isset($validated['is_active'])) {
            $updateData['is_active'] = $validated['is_active'];
            if (! $validated['is_active']) {
                $user->tokens()->delete();
            }
        }

        $user->update($updateData);

        if (array_key_exists('roles', $validated)) {
            $roleIds = Role::whereIn('name', $validated['roles'] ?? [])->pluck('id');
            $user->roles()->sync($roleIds);
        }

        $user->load('roles');

        AuditLogger::log(
            action: 'user.update',
            entityType: 'User',
            entityId: (string) $user->id,
            metadata: ['email' => $user->email, 'updated_fields' => array_keys($updateData)]
        );

        return response()->json([
            'message' => 'Usuario actualizado exitosamente.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'roles' => $user->roles->pluck('name'),
            ],
        ]);
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        // Prevent self-deactivation of super_admin
        if ($request->user()->id === $user->id && ! $validated['is_active']) {
            return response()->json([
                'message' => 'No puedes desactivar tu propia cuenta de usuario.',
            ], 422);
        }

        $user->update(['is_active' => $validated['is_active']]);

        // Revoke tokens if deactivated
        if (! $validated['is_active']) {
            $user->tokens()->delete();
        }

        AuditLogger::log(
            action: 'user.status_change',
            entityType: 'User',
            entityId: (string) $user->id,
            metadata: ['email' => $user->email, 'is_active' => $user->is_active]
        );

        return response()->json([
            'message' => 'Estado de usuario actualizado correctamente.',
            'data' => [
                'id' => $user->id,
                'is_active' => $user->is_active,
            ],
        ]);
    }

    public function updateRoles(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'roles' => ['required', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

        $roleIds = Role::whereIn('name', $validated['roles'])->pluck('id');
        $user->roles()->sync($roleIds);
        $user->load('roles');

        AuditLogger::log(
            action: 'user.role_change',
            entityType: 'User',
            entityId: (string) $user->id,
            metadata: ['email' => $user->email, 'roles' => $validated['roles']]
        );

        return response()->json([
            'message' => 'Roles actualizados exitosamente.',
            'data' => [
                'id' => $user->id,
                'roles' => $user->roles->pluck('name'),
            ],
        ]);
    }
}
