<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\ConversationUpdated;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function update(Request $request, Contact $contact): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'display_name' => ['sometimes', 'string', 'max:150'],
        ]);

        $name = $validated['name'] ?? $validated['display_name'] ?? null;
        if (! $name) {
            return response()->json(['message' => 'El campo name es requerido.'], 422);
        }

        $contact->update(['display_name' => $name]);

        // Broadcast ConversationUpdated on active conversations for this contact
        $conversations = Conversation::where('contact_id', $contact->id)
            ->whereIn('status', ['bot', 'waiting_agent', 'assigned'])
            ->with(['channel', 'contact', 'assignedUser'])
            ->get();

        foreach ($conversations as $conversation) {
            ConversationUpdated::dispatch($conversation);
        }

        return response()->json([
            'message' => 'Contacto actualizado correctamente.',
            'contact' => $contact,
        ]);
    }
}
