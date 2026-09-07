<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Raju\Streamer\Facades\Streamer;
use Raju\Streamer\Http\Controllers\StreamController;

beforeEach(function (): void {
    config([
        'larastreamer.storage.disk' => 'videos',
        'larastreamer.storage.path' => '',
    ]);

    Route::get('stream', StreamController::class)
        ->middleware('signed')
        ->name('larastreamer.stream');
});

it('streams a video from a valid signed url', function (): void {
    $url = Streamer::signedUrl('clip.mp4', now()->addMinutes(30));

    $this->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'video/mp4');
});

it('rejects an unsigned request to the built-in route', function (): void {
    $this->get('/stream?file=clip.mp4')->assertForbidden();
});

it('rejects an expired signature', function (): void {
    $url = Streamer::signedUrl('clip.mp4', now()->subMinute());

    $this->get($url)->assertForbidden();
});

it('rejects a tampered signature', function (): void {
    $url = Streamer::signedUrl('clip.mp4', now()->addMinutes(30));

    $this->get($url.'&file=other.mp4')->assertForbidden();
});

it('rejects an invalid signature', function (): void {
    $url = URL::to('/stream?file=clip.mp4&expires=9999999999&signature=not-valid');

    $this->get($url)->assertForbidden();
});
