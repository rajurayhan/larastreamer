<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Raju\Streamer\Facades\Streamer;

it('does not leak Streamer::authorize across http requests', function (): void {
    Route::get('/__scoped-deny', function () {
        Streamer::authorize(fn (string $path, mixed $user): bool => false);

        return Streamer::disk('videos')->file('clip.mp4')->stream();
    });

    Route::get('/__scoped-default', function () {
        return Streamer::disk('videos')->file('clip.mp4')->stream();
    });

    $this->get('/__scoped-deny')->assertForbidden();
    $this->get('/__scoped-default')->assertOk();
});

it('still honors a request-wide authorizer for subsequent calls in the same request', function (): void {
    Streamer::authorize(fn (string $path, mixed $user): bool => false);

    expect(Streamer::disk('videos')->file('clip.mp4')->stream()->getStatusCode())->toBe(403);
});
