<?php

$sanctumTtl = env('SANCTUM_TOKEN_EXPIRATION', env('SECURITY_API_TOKEN_TTL_MINUTES'));

return [

    'stateful' => explode(',', (string) env(
        'SANCTUM_STATEFUL_DOMAINS',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1'
    )),

    'guard' => ['web'],

    /**
     * Expiration globale Sanctum (minutes). null = pas d’expiration par âge.
     * Aligné sur SECURITY_API_TOKEN_TTL_MINUTES (vide/0 = illimité).
     */
    'expiration' => ($sanctumTtl === null || $sanctumTtl === '' || (int) $sanctumTtl <= 0)
        ? null
        : (int) $sanctumTtl,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];
