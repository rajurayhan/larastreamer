<?php

declare(strict_types=1);

use Raju\Streamer\Contracts\Probe;
use Raju\Streamer\Facades\Streamer;
use Raju\Streamer\Storage\ResolvedVideo;

it('returns size mime last_modified and etag without a probe', function (): void {
    $meta = Streamer::disk('videos')->file('clip.mp4')->meta();

    expect($meta->size)->toBe(2000)
        ->and($meta->mime)->toBe('video/mp4')
        ->and($meta->lastModified)->toBeInt()
        ->and($meta->etag)->toStartWith('W/"')
        ->and($meta->duration)->toBeNull()
        ->and($meta->codec)->toBeNull()
        ->and($meta['size'])->toBe(2000)
        ->and($meta['last_modified'])->toBe($meta->lastModified);
});

it('adds duration and codec when an optional probe is bound', function (): void {
    $this->app->bind(Probe::class, fn (): Probe => new class implements Probe
    {
        public function inspect(ResolvedVideo $video): array
        {
            return ['duration' => 12.5, 'codec' => 'avc1'];
        }
    });

    $meta = Streamer::disk('videos')->file('clip.mp4')->meta();

    expect($meta->duration)->toBe(12.5)
        ->and($meta->codec)->toBe('avc1');
});
