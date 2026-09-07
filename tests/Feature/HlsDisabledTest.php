<?php

declare(strict_types=1);

it('returns 404 for m3u8 when hls is disabled', function (): void {
    config(['larastreamer.hls.enabled' => false]);

    $this->writeTextFixture('lesson.m3u8', "#EXTM3U\n#EXTINF:9.0,\nseg0.ts\n");

    $this->get('/__stream?file=lesson.m3u8')->assertNotFound();
});

it('does not put m3u8 on the default allowlist', function (): void {
    expect(config('larastreamer.allowed_extensions'))->not->toContain('m3u8');
});
