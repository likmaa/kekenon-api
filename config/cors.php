<?php

return [
    'paths' => [
        'api/*',
        'broadcasting/auth',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => (function() {
        $origins = env('CORS_ALLOWED_ORIGINS', '');
        if ($origins === '*') {
            return env('APP_ENV') === 'production' ? [] : ['*'];
        }
        return array_filter(explode(',', $origins));
    })(),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
