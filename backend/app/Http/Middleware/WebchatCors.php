<?php

namespace App\Http\Middleware;

use App\Models\WebchatChannelSettings;
use App\Models\WebchatSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WebchatCors
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only process public webchat endpoints
        if (! $request->is('api/v1/public/webchat/*')) {
            return $next($request);
        }

        $rawOrigin = $request->header('Origin');
        if (! $rawOrigin) {
            // Not a CORS request (e.g. same-origin or server-to-server)
            return $next($request);
        }

        $normalizedOrigin = $this->normalizeOrigin($rawOrigin);
        $isAllowed = $this->isOriginAllowed($request, $normalizedOrigin);

        // Preflight OPTIONS handling
        if ($request->isMethod('OPTIONS')) {
            if ($isAllowed) {
                return response('', 204, [
                    'Access-Control-Allow-Origin' => $rawOrigin,
                    'Access-Control-Allow-Methods' => 'POST, GET, OPTIONS',
                    'Access-Control-Allow-Headers' => 'Content-Type, Accept, Authorization, X-Requested-With, X-Webchat-Session-Token',
                    'Access-Control-Max-Age' => '86400',
                    'Vary' => 'Origin',
                ]);
            }

            return response()->json([
                'message' => 'Origen no permitido.',
            ], 403);
        }

        // Actual request handling
        $response = $next($request);

        if ($isAllowed) {
            $response->headers->set('Access-Control-Allow-Origin', $rawOrigin);
            $response->headers->set('Vary', 'Origin');
        }

        return $response;
    }

    /**
     * Determine if the origin is allowed for the requested resource.
     */
    protected function isOriginAllowed(Request $request, string $normalizedOrigin): bool
    {
        // 1. Check if it matches Intera's own origin or configured internal origins
        $appUrl = config('app.url');
        if ($appUrl && $this->normalizeOrigin($appUrl) === $normalizedOrigin) {
            return true;
        }

        $frontendUrl = config('cors.allowed_origins.0') ?: env('FRONTEND_URL');
        if ($frontendUrl && $this->normalizeOrigin($frontendUrl) === $normalizedOrigin) {
            return true;
        }

        $defaultAllowed = config('cors.allowed_origins', []);
        foreach ($defaultAllowed as $allowed) {
            if ($this->normalizeOrigin($allowed) === $normalizedOrigin) {
                return true;
            }
        }

        // 2. Resolve Channel settings via public_key in URL
        $publicKey = $this->resolvePublicKey($request);
        if ($publicKey) {
            /** @var WebchatChannelSettings|null $settings */
            $settings = WebchatChannelSettings::where('public_key', $publicKey)->first();
            if ($settings && is_array($settings->allowed_origins)) {
                foreach ($settings->allowed_origins as $allowed) {
                    if ($this->normalizeOrigin($allowed) === $normalizedOrigin) {
                        return true;
                    }
                }
            }

            return false;
        }

        // 3. Resolve Channel settings via visitor token in bearer header
        $rawToken = $request->bearerToken() ?: $request->header('X-Webchat-Session-Token');
        if ($rawToken) {
            $tokenHash = WebchatSession::hashToken($rawToken);
            /** @var WebchatSession|null $session */
            $session = WebchatSession::with('channel.webchatSettings')
                ->where('token_hash', $tokenHash)
                ->first();

            $settings = $session?->channel?->webchatSettings;
            if ($settings && is_array($settings->allowed_origins)) {
                foreach ($settings->allowed_origins as $allowed) {
                    if ($this->normalizeOrigin($allowed) === $normalizedOrigin) {
                        return true;
                    }
                }
            }

            return false;
        }

        return false;
    }

    /**
     * Resolve the public key from the request path if present.
     */
    protected function resolvePublicKey(Request $request): ?string
    {
        // Path format: api/v1/public/webchat/{publicKey}/(embed|sessions)
        $segments = $request->segments();
        // segments: [0 => api, 1 => v1, 2 => public, 3 => webchat, 4 => {publicKey|other}]
        if (isset($segments[4]) && ! in_array($segments[4], ['session', 'conversations', 'messages', 'broadcast'], true)) {
            return $segments[4];
        }

        return null;
    }

    /**
     * Normalize an origin URL (scheme + lower host + optional port, stripped trailing slash).
     */
    protected function normalizeOrigin(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! isset($parts['scheme']) || ! isset($parts['host'])) {
            return rtrim(trim($url), '/');
        }

        $normalized = strtolower($parts['scheme']).'://'.strtolower($parts['host']);
        if (isset($parts['port'])) {
            $normalized .= ':'.$parts['port'];
        }

        return $normalized;
    }
}
