<?php

declare(strict_types=1);

use Raju\Streamer\Drm\PlaybackTicketManager;
use Raju\Streamer\Exceptions\VideoNotFound;

it('issues a ticket scoped to the manifest directory and user', function (): void {
    $manager = app(PlaybackTicketManager::class);
    $token = $manager->issue('videos', 'movies/one/manifest.mpd', now()->addMinute(), 42);

    $ticket = $manager->validate($token, 'movies/one/chunk-1.m4s', 42);

    expect($ticket->disk)->toBe('videos')
        ->and($ticket->scope)->toBe('movies/one')
        ->and($ticket->allows('movies/one/init.m4s'))->toBeTrue()
        ->and($ticket->allows('movies/two/init.m4s'))->toBeFalse();
});

it('rejects tampering expiry traversal and a different user', function (): void {
    $manager = app(PlaybackTicketManager::class);
    $valid = $manager->issue('videos', 'movies/one/manifest.mpd', now()->addMinute(), 42);
    $expired = $manager->issue('videos', 'movies/one/manifest.mpd', now()->subSecond(), 42);

    foreach ([
        'tampered' => [$valid.'x', 'movies/one/chunk-1.m4s', 42],
        'expired' => [$expired, 'movies/one/chunk-1.m4s', 42],
        'different directory' => [$valid, 'movies/two/chunk-1.m4s', 42],
        'traversal' => [$valid, 'movies/one/../two/chunk-1.m4s', 42],
        'encoded null byte' => [$valid, 'movies/one/%2500chunk-1.m4s', 42],
        'different user' => [$valid, 'movies/one/chunk-1.m4s', 7],
    ] as $case => [$token, $path, $userId]) {
        $rejected = false;

        try {
            $manager->validate($token, $path, $userId);
        } catch (VideoNotFound) {
            $rejected = true;
        }

        $this->assertTrue($rejected, $case);
    }
});
