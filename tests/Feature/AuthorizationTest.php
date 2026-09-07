<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Raju\Streamer\Events\VideoStreamUnauthorized;
use Raju\Streamer\Facades\Streamer;

it('returns 403 when the per-call authorizer denies access', function (): void {
    Event::fake([VideoStreamUnauthorized::class]);

    $this->get('/__stream?file=clip.mp4&authorize=deny')
        ->assertForbidden()
        ->assertSee('You are not authorized to stream this video.');

    Event::assertDispatched(VideoStreamUnauthorized::class);
});

it('streams when the per-call authorizer allows access', function (): void {
    $this->get('/__stream?file=clip.mp4&authorize=allow')->assertOk();
});

it('honors a global authorizer', function (): void {
    Streamer::authorize(fn (string $path, mixed $user): bool => false);

    $response = Streamer::disk('videos')->file('clip.mp4')->stream();

    expect($response->getStatusCode())->toBe(403);
});
