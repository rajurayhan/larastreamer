<?php

declare(strict_types=1);

use Raju\Streamer\Support\MimeTypeResolver;

$resolver = new MimeTypeResolver;

it('maps known extensions when the detected mime is generic', function () use ($resolver): void {
    expect($resolver->guess('application/octet-stream', 'lesson.mp4'))->toBe('video/mp4')
        ->and($resolver->guess(null, 'clip.webm'))->toBe('video/webm');
});

it('prefers a real detected mime over the extension map', function () use ($resolver): void {
    expect($resolver->guess('video/webm', 'clip.mp4'))->toBe('video/webm');
});

it('allows only configured mime and extension pairs', function () use ($resolver): void {
    expect($resolver->isAllowed('clip.mp4', 'video/mp4'))->toBeTrue()
        ->and($resolver->isAllowed('clip.mp4', 'text/plain'))->toBeFalse()
        ->and($resolver->isAllowed('notes.txt', 'video/mp4'))->toBeFalse()
        ->and($resolver->isAllowed('clip.mp4', null))->toBeFalse();
});

it('detects mime from a local mp4 fixture', function () use ($resolver): void {
    $path = $this->writeFixture('probe.mp4', 512);

    expect($resolver->detectFromFile($path))->toBe('video/mp4');
});
