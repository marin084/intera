<?php

namespace App\Http\Middleware;

use App\Models\WebchatSession;
use App\Support\WebchatOriginPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWebchatVisitor
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $rawToken = $request->bearerToken() ?: $request->header('X-Webchat-Session-Token');

        if (! $rawToken) {
            return response()->json([
                'message' => 'No autorizado. Se requiere token de sesión de webchat.',
            ], 401);
        }

        $tokenHash = WebchatSession::hashToken($rawToken);

        /** @var WebchatSession|null $session */
        $session = WebchatSession::with(['channel.webchatSettings', 'contact'])
            ->where('token_hash', $tokenHash)
            ->first();

        if (! $session || $session->isRevoked()) {
            return response()->json([
                'message' => 'Sesión de webchat inválida o revocada.',
            ], 401);
        }

        if ($session->isExpired()) {
            return response()->json([
                'message' => 'Sesión de webchat expirada.',
                'code' => 'session_expired',
            ], 401);
        }

        $channel = $session->channel;
        if (! $channel || ! $channel->is_active) {
            return response()->json([
                'message' => 'El canal de atención se encuentra actualmente desactivado.',
                'code' => 'channel_inactive',
            ], 403);
        }

        // Validate origin against allowed_origins
        $requestOrigin = $request->header('Origin') ?: $request->header('Referer') ?: $request->input('origin') ?: $session->origin;
        if ($requestOrigin) {
            $parsedOrigin = $this->normalizeOrigin($requestOrigin);
            $allowedOrigins = array_map(
                fn ($o) => $this->normalizeOrigin($o),
                $channel->webchatSettings?->allowed_origins ?? []
            );

            if (! in_array($parsedOrigin, $allowedOrigins, true) && ! WebchatOriginPolicy::isPlatformOrigin($parsedOrigin)) {
                return response()->json([
                    'message' => 'Origen no autorizado para este canal.',
                ], 403);
            }
        }

        // Touch last seen
        $session->update(['last_seen_at' => now()]);

        // Inject session into request attributes
        $request->attributes->set('webchat_session', $session);

        return $next($request);
    }

    protected function normalizeOrigin(string $url): string
    {
        return WebchatOriginPolicy::normalize($url);
    }
}
