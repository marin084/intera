<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TagController extends Controller
{
    /**
     * List all tags ordered by name.
     */
    public function index(): JsonResponse
    {
        if (Tag::count() === 0) {
            Tag::ensureDefaultTags();
        }

        $tags = Tag::orderBy('name')->get();

        return response()->json([
            'data' => $tags,
        ]);
    }

    /**
     * Create a new tag.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60', 'unique:tags,name'],
            'color' => ['nullable', 'string', 'max:20', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $name = trim($validated['name']);
        $tag = Tag::create([
            'name' => $name,
            'slug' => Str::slug($name),
            'color' => ! empty($validated['color']) ? strtoupper($validated['color']) : '#3B82F6',
        ]);

        return response()->json([
            'message' => 'Etiqueta creada correctamente.',
            'data' => $tag,
        ], 201);
    }

    /**
     * Update an existing tag.
     */
    public function update(Request $request, Tag $tag): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:60', Rule::unique('tags', 'name')->ignore($tag->id)],
            'color' => ['nullable', 'string', 'max:20', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        if (isset($validated['name'])) {
            $name = trim($validated['name']);
            $tag->name = $name;
            $tag->slug = Str::slug($name);
        }

        if (array_key_exists('color', $validated)) {
            $tag->color = ! empty($validated['color']) ? strtoupper($validated['color']) : '#3B82F6';
        }

        $tag->save();

        return response()->json([
            'message' => 'Etiqueta actualizada correctamente.',
            'data' => $tag,
        ]);
    }

    /**
     * Delete a tag.
     */
    public function destroy(Tag $tag): JsonResponse
    {
        $tag->delete();

        return response()->json([
            'message' => 'Etiqueta eliminada correctamente.',
        ]);
    }
}
