<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Raju\Streamer\Facades\Streamer;

it('registers the signed stream route when enabled and drops /streamer', function (): void {
    expect(Route::has('larastreamer.stream'))->toBeTrue();

    $this->get('/stream?file=clip.mp4')->assertForbidden();
    $this->get('/streamer')->assertNotFound();

    $this->get(Streamer::signedUrl('clip.mp4', now()->addMinutes(5)))
        ->assertOk()
        ->assertHeader('Content-Type', 'video/mp4');
});
