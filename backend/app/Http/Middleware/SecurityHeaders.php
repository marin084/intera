<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request and apply OWASP hardened HTTP security headers.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Modern OWASP standard security headers across all responses
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '0');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Clickjacking protection:
        // Public webchat endpoints and widget embeds MUST allow framing from customer websites.
        // Administrative application routes enforce SAMEORIGIN.
        if (! $request->is('api/v1/public/webchat/*') && ! $request->is('widget/*') && ! $request->is('widget.html')) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        return $response;
    }
}
