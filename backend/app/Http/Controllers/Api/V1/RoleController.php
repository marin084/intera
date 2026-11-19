<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions')->get();

        return response()->json([
            'data' => $roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'max_concurrent_conversations' => $role->max_concurrent_conversations,
                'permissions' => $role->permissions->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'display_name' => $p->display_name,
                    'module' => $p->module,
                ]),
            ]),
        ]);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $roleName = $role->name;
        $min = in_array($roleName, ['super_admin', 'admin', 'flow_manager'], true) ? 0 : 1;

        $validated = $request->validate([
            'max_concurrent_conversations' => ['nullable', 'integer', "min:{$min}", 'max:50'],
            'display_name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $role->update($validated);

        return response()->json([
            'message' => 'Rol actualizado correctamente.',
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'max_concurrent_conversations' => $role->max_concurrent_conversations,
                'permissions' => $role->permissions->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'display_name' => $p->display_name,
                    'module' => $p->module,
                ]),
            ],
        ]);
    }

    public function permissions(): JsonResponse
    {
        $permissions = Permission::all();

        return response()->json([
            'data' => $permissions->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'display_name' => $p->display_name,
                'module' => $p->module,
                'description' => $p->description,
            ]),
        ]);
    }
}
