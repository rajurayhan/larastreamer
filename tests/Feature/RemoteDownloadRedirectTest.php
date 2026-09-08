<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Raju\Streamer\Facades\Streamer;

it('redirects remote downloads instead of proxying through php', function (): void {
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
        $mock->shouldReceive('lastModified')->with('clip.mp4')->andReturn(1_700_000_000);
        $mock->shouldReceive('readStream')->never();
        $mock->shouldReceive('temporaryUrl')
            ->once()
            ->withArgs(function (string $path, mixed $expiration, array $options = []): bool {
                return $path === 'clip.mp4'
                    && isset($options['ResponseContentDisposition'])
                    && str_contains((string) $options['ResponseContentDisposition'], 'attachment');
            })
            ->andReturn('https://s3.example.test/clip.mp4?response-content-disposition=attachment');
    });

    Storage::set('s3', $disk);

    $response = Streamer::disk('s3')->file('clip.mp4')->download();

    expect($response->isRedirect())->toBeTrue()
        ->and($response->headers->get('Location'))
        ->toBe('https://s3.example.test/clip.mp4?response-content-disposition=attachment');
});
