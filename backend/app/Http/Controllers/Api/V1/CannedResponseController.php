<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CannedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CannedResponseController extends Controller
{
    /**
     * List canned responses.
     */
    public function index(Request $request): JsonResponse
    {
        $query = CannedResponse::with('creator:id,name,email');

        // By default show active responses unless requested by manager
        $user = $request->user();
        $isManager = $user && ($user->hasRole(['super_admin', 'admin', 'supervisor']) || $user->hasPermission('canned_responses.manage'));

        if (! $isManager || ! $request->boolean('include_inactive')) {
            $query->active();
        }

        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        if ($request->filled('search')) {
            $search = trim($request->query('search'));
            // strip leading slash if user typed /shortcut
            $cleanSearch = ltrim($search, '/');

            $query->where(function ($q) use ($search, $cleanSearch) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('shortcut', 'like', "%{$cleanSearch}%")
                    ->orWhere('content', 'like', "%{$search}%");
            });
        }

        $responses = $query->orderByRaw('shortcut ASC NULLS LAST')
            ->orderBy('title', 'asc')
            ->get();

        return response()->json([
            'data' => $responses,
        ]);
    }

    /**
     * Store a new canned response.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'shortcut' => ['nullable', 'string', 'max:50', 'alpha_dash', 'unique:canned_responses,shortcut'],
            'content' => ['required', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $cannedResponse = CannedResponse::create([
            'title' => $validated['title'],
            'shortcut' => $validated['shortcut'] ?? null,
            'content' => $validated['content'],
            'category' => $validated['category'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'data' => $cannedResponse->load('creator:id,name,email'),
            'message' => 'Respuesta rápida creada exitosamente.',
        ], 201);
    }

    /**
     * Display a specific canned response.
     */
    public function show(CannedResponse $cannedResponse): JsonResponse
    {
        return response()->json([
            'data' => $cannedResponse->load('creator:id,name,email'),
        ]);
    }

    /**
     * Update an existing canned response.
     */
    public function update(Request $request, CannedResponse $cannedResponse): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'shortcut' => [
                'nullable',
                'string',
                'max:50',
                'alpha_dash',
                Rule::unique('canned_responses', 'shortcut')->ignore($cannedResponse->id),
            ],
            'content' => ['sometimes', 'required', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $cannedResponse->update($validated);

        return response()->json([
            'data' => $cannedResponse->fresh()->load('creator:id,name,email'),
            'message' => 'Respuesta rápida actualizada exitosamente.',
        ]);
    }

    /**
     * Remove a canned response.
     */
    public function destroy(CannedResponse $cannedResponse): JsonResponse
    {
        $cannedResponse->delete();

        return response()->json([
            'message' => 'Respuesta rápida eliminada exitosamente.',
        ]);
    }
}
