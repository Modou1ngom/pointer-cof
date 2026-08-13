<?php

$origins = array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))));

if ($origins === []) {
    // Prod : APP_URL + origine PWA iPhone (Flutter Web sur GitHub Pages).
    // Android natif n’est pas soumis au CORS navigateur.
    $defaults = array_filter([
        (string) env('APP_URL'),
        'https://modou1ngom.github.io',
    ]);
    $origins = env('APP_ENV') === 'production'
        ? array_values($defaults)
        : ['*'];
}

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [
        '#^https://modou1ngom\.github\.io$#',
        '#^https://.*\.github\.io$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
