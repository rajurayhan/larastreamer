<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Raju\Streamer\Facades\Streamer;
use Raju\Streamer\Http\Controllers\StreamController;

it('returns 304 when If-None-Match matches the ETag', function (): void {
    $first = $this->get('/__stream?file=clip.mp4');

    $first->assertOk();
    $etag = $first->headers->get('ETag');

    expect($etag)->toBeString()->toStartWith('W/"');

    $this->withHeaders(['If-None-Match' => (string) $etag])
        ->get('/__stream?file=clip.mp4')
        ->assertStatus(304)
        ->assertHeader('ETag', (string) $etag);

    expect($this->responseBody($this->withHeaders(['If-None-Match' => (string) $etag])->get('/__stream?file=clip.mp4')))
        ->toBe('');
});

it('serves 206 when If-Range matches and Range is valid', function (): void {
    $etag = $this->get('/__stream?file=clip.mp4')->headers->get('ETag');

    $response = $this->withHeaders([
        'Range' => 'bytes=0-99',
        'If-Range' => (string) $etag,
    ])->get('/__stream?file=clip.mp4');

    $response->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 0-99/2000');

    expect($this->responseBody($response))->toHaveLength(100);
});

it('ignores Range and returns 200 when If-Range does not match', function (): void {
    $response = $this->withHeaders([
        'Range' => 'bytes=0-99',
        'If-Range' => '"not-the-etag"',
    ])->get('/__stream?file=clip.mp4');

    $response->assertOk();

    expect($this->responseBody($response))->toHaveLength(2000);
});

it('includes ETag and Last-Modified on GET and HEAD', function (): void {
    $get = $this->get('/__stream?file=clip.mp4');
    $head = $this->head('/__stream?file=clip.mp4');

    expect($get->headers->get('ETag'))->toBeString()->toStartWith('W/"')
        ->and($get->headers->get('Last-Modified'))->toBeString()
        ->and($head->headers->get('ETag'))->toBe($get->headers->get('ETag'))
        ->and($head->headers->get('Last-Modified'))->toBe($get->headers->get('Last-Modified'));
});

it('embeds a signed url with kind and captions when routes are on', function (): void {
    config([
        'larastreamer.storage.disk' => 'videos',
        'larastreamer.storage.path' => '',
    ]);

    Route::get('stream', StreamController::class)
        ->middleware('signed')
        ->name('larastreamer.stream');

    $this->writeTextFixture('clip.en.vtt', "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nHi");

    $data = Streamer::disk('videos')->file('clip.mp4')->captions([
        ['src' => 'clip.en.vtt', 'srclang' => 'en', 'label' => 'English', 'default' => true],
    ])->embedData();

    expect($data['url'])->toContain('/stream')
        ->and($data['url'])->toContain('signature=')
        ->and($data['type'])->toBe('video')
        ->and($data['mime'])->toBe('video/mp4')
        ->and($data['kind'])->toBe('progressive')
        ->and($data['captions'][0]['src'])->toContain('clip.en.vtt')
        ->and($data['captions'][0]['srclang'])->toBe('en');
});
