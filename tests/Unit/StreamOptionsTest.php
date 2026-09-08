<?php

declare(strict_types=1);

use Raju\Streamer\Streaming\StreamOptions;

it('reads streaming options from config', function (): void {
    config([
        'larastreamer.streaming.buffer_size' => 2048,
        'larastreamer.streaming.max_age' => 120,
        'larastreamer.streaming.cache' => 'public',
    ]);

    $options = StreamOptions::fromConfig();

    expect($options->bufferSize)->toBe(2048)
        ->and($options->maxAge)->toBe(120)
        ->and($options->cache)->toBe('public')
        ->and($options->disposition)->toBe('inline')
        ->and($options->cacheControl())->toBe('public, max-age=120');
});

it('defaults cache to private and can switch disposition', function (): void {
    config([
        'larastreamer.streaming.cache' => 'secret',
    ]);

    $options = StreamOptions::fromConfig()->withDisposition('attachment');

    expect($options->cache)->toBe('private')
        ->and($options->disposition)->toBe('attachment')
        ->and($options->cacheControl())->toBe('private, max-age=3600');
});
