<?php

declare(strict_types=1);

use Raju\Streamer\Concerns\Streamable;

it('streams from the default path column and configured disk', function (): void {
    $lesson = new class
    {
        use Streamable;

        public string $path = 'clip.mp4';

        public string $disk = 'videos';
    };

    $response = $lesson->stream();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toBe('video/mp4');
});

it('downloads through the trait', function (): void {
    $lesson = new class
    {
        use Streamable;

        public string $path = 'clip.mp4';

        public string $disk = 'videos';
    };

    $response = $lesson->download();

    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->headers->get('Content-Disposition'))->toContain('attachment');
});

it('honors streamPath and streamDisk overrides', function (): void {
    $this->writeFixture('courses/lesson-01.mp4', 1024);

    $lesson = new class
    {
        use Streamable;

        public function streamDisk(): string
        {
            return 'videos';
        }

        public function streamPath(): string
        {
            return 'courses/lesson-01.mp4';
        }
    };

    expect($lesson->stream()->getStatusCode())->toBe(200);
});

it('uses the config default disk when none is set', function (): void {
    config(['larastreamer.storage.disk' => 'videos']);

    $lesson = new class
    {
        use Streamable;

        public string $path = 'clip.mp4';
    };

    expect($lesson->streamDisk())->toBe('videos')
        ->and($lesson->stream()->getStatusCode())->toBe(200);
});
