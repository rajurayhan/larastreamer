<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Raju\Streamer\Exceptions\StreamException;
use Raju\Streamer\Facades\Streamer;
use Raju\Streamer\Http\Controllers\StreamController;

beforeEach(function (): void {
    config([
        'larastreamer.storage.disk' => 'videos',
        'larastreamer.storage.path' => '',
        'filesystems.disks.alternate' => [
            'driver' => 'local',
            'root' => $this->diskRoot.'/alternate',
            'throw' => false,
        ],
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

it('throws when signed urls are disabled', function (): void {
    config(['larastreamer.security.signed_urls' => false]);

    expect(fn () => Streamer::signedUrl('clip.mp4'))->toThrow(StreamException::class);
});

it('returns 404 from the built-in route when signed urls are disabled', function (): void {
    config(['larastreamer.security.signed_urls' => false]);

    $url = URL::temporarySignedRoute('larastreamer.stream', now()->addMinutes(5), ['file' => 'clip.mp4']);

    $this->get($url)->assertNotFound();
});

it('preserves a named disk in a signed url', function (): void {
    Storage::disk('alternate')->put('only.mp4', file_get_contents($this->diskRoot.'/clip.mp4'));

    $url = Streamer::signedUrl('only.mp4', now()->addMinutes(5), 'alternate');

    $this->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'video/mp4');
});

it('preserves the pending stream disk in embed data', function (): void {
    Storage::disk('alternate')->put('only.mp4', file_get_contents($this->diskRoot.'/clip.mp4'));

    $data = Streamer::disk('alternate')->file('only.mp4')->embedData(300);

    expect($data['url'])->toContain('disk=alternate');
    $this->get($data['url'])->assertOk();
});
