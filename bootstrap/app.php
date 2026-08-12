<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnsurePointrustAdmin;
use App\Http\Middleware\EnsureRhPointageWebAccess;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PointrustAuthenticate;
use App\Http\Middleware\RejectOtpPendingSanctumToken;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetFilialeContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $trustedProxies = env('TRUSTED_PROXIES');
        if (is_string($trustedProxies) && $trustedProxies !== '') {
            $middleware->trustProxies(at: $trustedProxies === '*' ? '*' : array_map('trim', explode(',', $trustedProxies)));
        }

        $middleware->web(append: [
            ForceHttps::class,
            EnsureUserIsActive::class,
            ForcePasswordChange::class,
            SetFilialeContext::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SecurityHeaders::class,
        ]);

        $middleware->api(prepend: [
            ForceHttps::class,
        ]);

        $middleware->api(append: [
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'role' => CheckRole::class,
            'rh.pointage' => EnsureRhPointageWebAccess::class,
            'pointrust' => PointrustAuthenticate::class,
            'pointrust.admin' => EnsurePointrustAdmin::class,
            'reject_otp_pending' => RejectOtpPendingSanctumToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function (Request $request, \Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! app()->environment('production')) {
                return null;
            }

            if ($request->is('api/*') || $request->expectsJson()) {
                $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
                if ($status >= 500) {
                    return response()->json(['message' => 'Erreur serveur.'], $status);
                }
            }

            return null;
        });
    })->create();
