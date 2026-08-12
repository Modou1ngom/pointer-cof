<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self), payment=(), usb=()');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        // HSTS uniquement en production : le poser en local force le navigateur
        // vers https://127.0.0.1 (artisan serve = HTTP only → ERR_CONNECTION_CLOSED).
        if (app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        // En local, pas de CSP : Vite HMR utilise souvent http://[::1]:5173 (IPv6),
        // mal supporté par les host-sources CSP des navigateurs.
        if (config('security.csp_enabled', true) && ! app()->environment('local')) {
            $response->headers->set('Content-Security-Policy', $this->csp());
        }

        return $response;
    }

    private function csp(): string
    {
        $isLocal = app()->environment('local');

        // Vite HMR peut servir via localhost, 127.0.0.1 ou [::1] (IPv6).
        $script = $isLocal
            ? "script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:* http://127.0.0.1:* http://[::1]:*"
            : "script-src 'self' 'unsafe-inline'";

        $connect = $isLocal
            ? "connect-src 'self' ws://localhost:* ws://127.0.0.1:* ws://[::1]:* http://localhost:* http://127.0.0.1:* http://[::1]:*"
            : "connect-src 'self'";

        $style = "style-src 'self' 'unsafe-inline'";

        $directives = [
            "default-src 'self'",
            $script,
            $style,
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            $connect,
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ];

        if (! $isLocal) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }
}
