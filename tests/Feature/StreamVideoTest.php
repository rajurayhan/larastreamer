<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Raju\Streamer\Events\VideoStreamCompleted;
use Raju\Streamer\Events\VideoStreamStarted;
use Raju\Streamer\Facades\Streamer;
use Raju\Streamer\Helpers\VideoStream;

it('streams a full video with a 200 response', function (): void {
    $response = $this->get('/__stream?file=clip.mp4');

    $response->assertOk()
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('Content-Type', 'video/mp4');

    expect((string) $response->headers->get('Cache-Control'))
        ->toContain('private')
        ->toContain('max-age=3600');

    expect($this->responseBody($response))->toHaveLength(2000);
});

it('returns 404 for a missing file without leaking paths', function (): void {
    $response = $this->get('/__stream?file=missing.mp4');

    $response->assertNotFound();

    expect($response->getContent())
        ->not->toContain($this->diskRoot)
        ->not->toContain('/missing.mp4')
        ->not->toContain('storage/');
});

it('returns 404 for a disallowed mime or extension', function (): void {
    file_put_contents($this->diskRoot.'/notes.txt', 'hello world');
    file_put_contents($this->diskRoot.'/fake.mp4', str_repeat('not a video', 50));

    $this->get('/__stream?file=notes.txt')->assertNotFound();
    $this->get('/__stream?file=fake.mp4')->assertNotFound();
});

it('returns 404 for an empty file', function (): void {
    file_put_contents($this->diskRoot.'/empty.mp4', '');

    $this->get('/__stream?file=empty.mp4')->assertNotFound();
});

it('dispatches started and completed events', function (): void {
    Event::fake([VideoStreamStarted::class, VideoStreamCompleted::class]);

    $this->get('/__stream?file=clip.mp4')->assertOk();

    Event::assertDispatched(VideoStreamStarted::class, function (VideoStreamStarted $event): bool {
        return $event->path === 'clip.mp4' && $event->disk === 'videos' && $event->strategy === 'file';
    });

    Event::assertDispatched(VideoStreamCompleted::class);
});

it('delegates the deprecated VideoStream shim to the new layer', function (): void {
    $path = $this->writeFixture('legacy.mp4', 1024);

    $response = (new VideoStream($path))->start();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Accept-Ranges'))->toBe('bytes')
        ->and($response->headers->get('Content-Type'))->toBe('video/mp4');
});

it('renders the blade player', function (): void {
    $html = $this->blade('<x-larastreamer::player url="https://cdn.example.test/clip.mp4" mime="video/mp4" />');

    expect((string) $html)
        ->toContain('<video')
        ->toContain('https://cdn.example.test/clip.mp4')
        ->toContain('video/mp4');
});

it('returns embed data for a local file', function (): void {
    $data = Streamer::disk('videos')->file('clip.mp4')->embedData();

    expect($data['url'])->toBe('clip.mp4')
        ->and($data['type'])->toBe('video')
        ->and($data['mime'])->toBe('video/mp4')
        ->and($data['expires_at'])->not->toBeNull();
});
