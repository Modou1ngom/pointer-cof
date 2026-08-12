<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirige HTTP → HTTPS en production (derrière proxy si TRUSTED_PROXIES est défini).
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production') && ! $request->secure()) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
