<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Raju\Streamer\Facades\Streamer;

it('streams from a configured local disk', function (): void {
    $this->writeFixture('courses/lesson-01.mp4', 1500);

    $response = $this->get('/__stream?file=courses/lesson-01.mp4');

    $response->assertOk()->assertHeader('Content-Type', 'video/mp4');
    expect($this->responseBody($response))->toHaveLength(1500);
});

it('creates a mocked s3 temporary url', function (): void {
    config([
        'filesystems.disks.s3' => [
            'driver' => 's3',
            'key' => 'testing',
            'secret' => 'testing',
            'region' => 'us-east-1',
            'bucket' => 'videos',
        ],
    ]);

    $disk = Mockery::mock(FilesystemAdapter::class, function (MockInterface $mock): void {
        $mock->shouldReceive('exists')->with('clip.mp4')->andReturn(true);
        $mock->shouldReceive('directoryExists')->with('clip.mp4')->andReturn(false);
        $mock->shouldReceive('size')->with('clip.mp4')->andReturn(2048);
        $mock->shouldReceive('mimeType')->with('clip.mp4')->andReturn('video/mp4');
        $mock->shouldReceive('temporaryUrl')
            ->once()
            ->andReturn('https://s3.example.test/clip.mp4?X-Amz-Signature=abc');
    });

    Storage::set('s3', $disk);

    $url = Streamer::disk('s3')->file('clip.mp4')->temporaryUrl(now()->addMinutes(30));

    expect($url)->toBe('https://s3.example.test/clip.mp4?X-Amz-Signature=abc');
});

it('redirects remote disks by default', function (): void {
    config([
        'filesystems.disks.s3' => [
            'driver' => 's3',
            'key' => 'testing',
            'secret' => 'testing',
            'region' => 'us-east-1',
            'bucket' => 'videos',
        ],
        'larastreamer.storage.remote.strategy' => 'redirect',
    ]);

    $disk = Mockery::mock(FilesystemAdapter::class, function (MockInterface $mock): void {
        $mock->shouldReceive('exists')->with('clip.mp4')->andReturn(true);
        $mock->shouldReceive('directoryExists')->with('clip.mp4')->andReturn(false);
        $mock->shouldReceive('size')->with('clip.mp4')->andReturn(2048);
        $mock->shouldReceive('mimeType')->with('clip.mp4')->andReturn('video/mp4');
        $mock->shouldReceive('temporaryUrl')
            ->andReturn('https://s3.example.test/clip.mp4?X-Amz-Signature=abc');
    });

    Storage::set('s3', $disk);

    $response = Streamer::disk('s3')->file('clip.mp4')->redirect();

    expect($response->isRedirect())->toBeTrue()
        ->and($response->headers->get('Location'))->toBe('https://s3.example.test/clip.mp4?X-Amz-Signature=abc');
});

it('redirects remote stream() calls when the default strategy is redirect', function (): void {
    config([
        'filesystems.disks.s3' => [
            'driver' => 's3',
            'key' => 'testing',
            'secret' => 'testing',
            'region' => 'us-east-1',
            'bucket' => 'videos',
        ],
    ]);

    $disk = Mockery::mock(FilesystemAdapter::class, function (MockInterface $mock): void {
        $mock->shouldReceive('exists')->with('clip.mp4')->andReturn(true);
        $mock->shouldReceive('directoryExists')->with('clip.mp4')->andReturn(false);
        $mock->shouldReceive('size')->with('clip.mp4')->andReturn(2048);
        $mock->shouldReceive('mimeType')->with('clip.mp4')->andReturn('video/mp4');
        $mock->shouldReceive('temporaryUrl')
            ->andReturn('https://s3.example.test/clip.mp4?X-Amz-Signature=abc');
    });

    Storage::set('s3', $disk);

    $response = Streamer::disk('s3')->file('clip.mp4')->stream();

    expect($response->isRedirect())->toBeTrue();
});
