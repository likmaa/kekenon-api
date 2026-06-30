<?php

use BeyondCode\LaravelWebSockets\Dashboard\Http\Middleware\Authorize;

return [

    'dashboard' => [
        'port' => env('LARAVEL_WEBSOCKETS_PORT', 6001),
    ],

    'apps' => [
        [
            'id' => env('PUSHER_APP_ID', 'local'),
            'name' => env('APP_NAME', 'Laravel'),
            'key' => env('PUSHER_APP_KEY', 'local-key'),
            'secret' => env('PUSHER_APP_SECRET', 'local-secret'),
            'path' => env('PUSHER_APP_PATH', ''),
            'capacity' => null,
            'enable_client_messages' => true,
            'enable_statistics' => true,
        ],
    ],

    'app_provider' => BeyondCode\LaravelWebSockets\Apps\ConfigAppProvider::class,

    'allowed_origins' => [
        '*',
    ],

    'max_request_size_in_kb' => 250,

    'path' => env('LARAVEL_WEBSOCKETS_PATH', 'app/websocket'),

    'middleware' => [
        'web',
        Authorize::class,
    ],

    'statistics' => [
        'model' => \BeyondCode\LaravelWebSockets\Statistics\Models\WebSocketsStatisticsEntry::class,
        'interval_in_seconds' => 60,
        'delete_statistics_older_than_days' => 60,
        'perform_dns_lookup' => false,
    ],

    'ssl' => [
        'local_cert' => env('LARAVEL_WEBSOCKETS_SSL_LOCAL_CERT', null),
        'local_pk' => env('LARAVEL_WEBSOCKETS_SSL_LOCAL_PK', null),
        'passphrase' => env('LARAVEL_WEBSOCKETS_SSL_PASSPHRASE', null),
        'verify_peer' => env('LARAVEL_WEBSOCKETS_SSL_VERIFY_PEER', false),
    ],

    'replication' => [
        'mode' => env('LARAVEL_WEBSOCKETS_REPLICATION_MODE', 'local'),
        'modes' => [
            'local' => [
                'channel_manager' => \BeyondCode\LaravelWebSockets\ChannelManagers\LocalChannelManager::class,
            ],
        ],
    ],

];

