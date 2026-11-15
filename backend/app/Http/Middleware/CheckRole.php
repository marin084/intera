<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'User account is inactive.'], 403);
        }

        if ($user->hasRole('super_admin')) {
            return $next($request);
        }

        if ($user->hasRole($roles)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Forbidden. Unauthorized role.',
        ], 403);
    }
}
