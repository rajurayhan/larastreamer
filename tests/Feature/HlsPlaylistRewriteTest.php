<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Raju\Streamer\Facades\Streamer;
use Raju\Streamer\Http\Controllers\StreamController;

beforeEach(function (): void {
    config([
        'larastreamer.hls.enabled' => true,
        'larastreamer.storage.disk' => 'videos',
        'larastreamer.storage.path' => '',
    ]);

    Route::get('stream', StreamController::class)
        ->middleware('signed')
        ->name('larastreamer.stream');

    $this->writeFixture('courses/seg0.ts', 512);
    $this->writeTextFixture('courses/enc.key', str_repeat('k', 16));
    $this->writeTextFixture('courses/lesson.m3u8', <<<'M3U8'
#EXTM3U
#EXT-X-VERSION:3
#EXT-X-TARGETDURATION:10
#EXT-X-KEY:METHOD=AES-128,URI="enc.key"
#EXTINF:9.0,
seg0.ts
#EXTINF:9.0,
https://cdn.example.test/absolute.ts
#EXT-X-ENDLIST
M3U8);
});

it('rewrites relative playlist uris to signed urls and leaves https alone', function (): void {
    $response = Streamer::disk('videos')->file('courses/lesson.m3u8')->stream();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toBe('application/vnd.apple.mpegurl')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('private');

    $body = (string) $response->getContent();

    expect($body)->toContain('/stream?')
        ->and($body)->toContain('file=')
        ->and($body)->toContain('seg0.ts')
        ->and($body)->toContain('enc.key')
        ->and($body)->toContain('signature=')
        ->and($body)->toContain('https://cdn.example.test/absolute.ts')
        ->and($body)->not->toMatch('/^seg0\.ts$/m')
        ->and($body)->not->toContain('URI="enc.key"');
});

it('returns 404 when a playlist uri traverses the jail', function (): void {
    $this->writeTextFixture('courses/evil.m3u8', <<<'M3U8'
#EXTM3U
#EXTINF:9.0,
../secret.ts
#EXT-X-ENDLIST
M3U8);

    $response = Streamer::disk('videos')->file('courses/evil.m3u8')->stream();

    expect($response->getStatusCode())->toBe(404)
        ->and((string) $response->getContent())->not->toContain('secret.ts')
        ->and((string) $response->getContent())->not->toContain($this->diskRoot);
});

it('rejects unsigned segment requests', function (): void {
    $this->get('/stream?file=courses/seg0.ts')->assertForbidden();
});

it('serves a rewritten playlist from the signed route', function (): void {
    $url = Streamer::signedUrl('courses/lesson.m3u8', now()->addMinutes(5));

    $this->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.apple.mpegurl');
});
