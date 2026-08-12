<?php

$origins = array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))));

if ($origins === []) {
    $origins = env('APP_ENV') === 'production'
        ? array_values(array_filter([(string) env('APP_URL')]))
        : ['*'];
}

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
