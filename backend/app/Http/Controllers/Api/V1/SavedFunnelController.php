<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SavedFunnel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SavedFunnelController extends Controller
{
    /**
     * Check if the authenticated user is restricted to viewing only their own agent metrics.
     */
    protected function isRestrictedAgent(Request $request): bool
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();
        if (! $user) {
            return false;
        }

        if ($user->hasRole(['super_admin', 'admin', 'supervisor']) || $user->hasPermission('conversations.view_all')) {
            return false;
        }

        return true;
    }

    /**
     * Ensure user has access to analytics configuration.
     */
    protected function authorizeAccess(Request $request): void
    {
        if ($this->isRestrictedAgent($request)) {
            abort(403, 'No tienes permisos para gestionar embudos guardados.');
        }
    }

    /**
     * List saved funnels, optionally filtered by flow_id.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);

        $validated = $request->validate([
            'flow_id' => ['nullable', 'string', 'uuid'],
        ]);

        $query = SavedFunnel::query()->with(['creator:id,name,email']);

        if (! empty($validated['flow_id'])) {
            $query->where('flow_id', $validated['flow_id']);
        }

        $funnels = $query->orderBy('name')->get();

        return response()->json([
            'data' => $funnels,
        ]);
    }

    /**
     * Store a newly created saved funnel.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'flow_id' => ['required', 'string', 'uuid', 'exists:flows,id'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.id' => ['nullable', 'string'],
            'steps.*.label' => ['required', 'string'],
            'steps.*.node_id' => ['nullable', 'string'],
            'steps.*.node_key' => ['nullable', 'string'],
            'steps.*.option_id' => ['nullable', 'string'],
            'steps.*.option_title' => ['nullable', 'string'],
            'steps.*.is_active' => ['nullable', 'boolean'],
        ]);

        $funnel = SavedFunnel::create([
            'name' => $validated['name'],
            'flow_id' => $validated['flow_id'],
            'steps' => $validated['steps'],
            'created_by' => $request->user()?->id,
        ]);

        $funnel->load('creator:id,name,email');

        return response()->json([
            'data' => $funnel,
            'message' => 'Embudo guardado exitosamente.',
        ], Response::HTTP_CREATED);
    }

    /**
     * Update an existing saved funnel.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorizeAccess($request);

        if (! \Illuminate\Support\Str::isUuid($id)) {
            return response()->json(['message' => 'Embudo no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        /** @var SavedFunnel|null $funnel */
        $funnel = SavedFunnel::find($id);
        if (! $funnel) {
            return response()->json(['message' => 'Embudo no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'flow_id' => ['sometimes', 'string', 'uuid', 'exists:flows,id'],
            'steps' => ['sometimes', 'array', 'min:1'],
            'steps.*.id' => ['nullable', 'string'],
            'steps.*.label' => ['required', 'string'],
            'steps.*.node_id' => ['nullable', 'string'],
            'steps.*.node_key' => ['nullable', 'string'],
            'steps.*.option_id' => ['nullable', 'string'],
            'steps.*.option_title' => ['nullable', 'string'],
            'steps.*.is_active' => ['nullable', 'boolean'],
        ]);

        $funnel->update($validated);
        $funnel->load('creator:id,name,email');

        return response()->json([
            'data' => $funnel,
            'message' => 'Embudo actualizado exitosamente.',
        ]);
    }

    /**
     * Delete a saved funnel.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authorizeAccess($request);

        if (! \Illuminate\Support\Str::isUuid($id)) {
            return response()->json(['message' => 'Embudo no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        /** @var SavedFunnel|null $funnel */
        $funnel = SavedFunnel::find($id);
        if (! $funnel) {
            return response()->json(['message' => 'Embudo no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $funnel->delete();

        return response()->json([
            'message' => 'Embudo eliminado exitosamente.',
        ]);
    }
}
