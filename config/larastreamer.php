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

    'hls' => [
        'enabled' => false,
        'rewrite' => true,
        'player' => 'native', // native | hlsjs
        'hlsjs_src' => 'https://cdn.jsdelivr.net/npm/hls.js@1/dist/hls.min.js',
    ],

    'dash' => [
        'enabled' => false,
        'rewrite' => true,
    ],

    'drm' => [
        'enabled' => true,
        'shaka_src' => 'https://cdn.jsdelivr.net/npm/shaka-player@5.2.9/dist/shaka-player.compiled.js',
        'fallback_message' => 'Protected playback is not supported on this device.',
        'playback_route_name' => 'larastreamer.playback',
        'playback_middleware' => [],
    ],

    'captions' => [
        'enabled' => true,
        'allowed_extensions' => ['vtt', 'srt'],
    ],

    'offload' => [
        'enabled' => false,
        'driver' => 'nginx', // nginx | apache
        'prefix' => '/internal-videos/',
    ],
];
