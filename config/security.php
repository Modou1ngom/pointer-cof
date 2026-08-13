<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Verrouillage après échecs de connexion
    |--------------------------------------------------------------------------
    */
    'login_max_attempts' => (int) env('SECURITY_LOGIN_MAX_ATTEMPTS', 5),
    'login_decay_minutes' => (int) env('SECURITY_LOGIN_DECAY_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Durée des jetons API Sanctum (minutes).
    | null / vide / 0 = illimité (recommandé mobile).
    | Ex. 43200 = 30 jours si SECURITY_API_TOKEN_TTL_MINUTES est défini.
    |--------------------------------------------------------------------------
    */
    'api_token_ttl_minutes' => (($ttl = env('SECURITY_API_TOKEN_TTL_MINUTES')) === null || $ttl === '' || (int) $ttl <= 0)
        ? null
        : (int) $ttl,

    /*
    |--------------------------------------------------------------------------
    | Content-Security-Policy
    |--------------------------------------------------------------------------
    */
    'csp_enabled' => filter_var(env('SECURITY_CSP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

];
