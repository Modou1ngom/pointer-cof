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
    | Défaut 30 jours : évite « impossible de synchroniser » après 12 h
    | (ancien défaut 720) alors que la session locale mobile reste affichée.
    |--------------------------------------------------------------------------
    */
    'api_token_ttl_minutes' => (int) env('SECURITY_API_TOKEN_TTL_MINUTES', 43200),

    /*
    |--------------------------------------------------------------------------
    | Content-Security-Policy
    |--------------------------------------------------------------------------
    */
    'csp_enabled' => filter_var(env('SECURITY_CSP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

];
