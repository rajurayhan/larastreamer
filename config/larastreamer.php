<?php

declare(strict_types=1);

return [
    'route' => [
        'enabled' => false,
        'prefix' => 'stream',
        'middleware' => ['signed'],
        'name' => 'larastreamer.stream',
    ],

    'storage' => [
        'disk' => env('LARASTREAMER_DISK', 'local'),
        'path' => env('LARASTREAMER_PATH', 'uploads'),
        'remote' => [
            'strategy' => 'redirect', // redirect | proxy
        ],
    ],

    'streaming' => [
        'buffer_size' => 1024 * 1024,
        'max_age' => 3600,
        'cache' => 'private', // private | public
    ],

    'security' => [
        'signed_urls' => true,
        'default_expiration' => 1800,
    ],

    'allowed_mimes' => [
        'video/mp4',
        'video/webm',
        'video/ogg',
        'video/quicktime',
        'video/x-msvideo',
        'video/mpeg',
    ],

    'allowed_extensions' => ['mp4', 'webm', 'ogv', 'mov', 'avi', 'mpeg', 'mpg'],

    'offload' => [
        'enabled' => false,
        'driver' => 'nginx', // nginx | apache
        'prefix' => '/internal-videos/',
    ],
];
