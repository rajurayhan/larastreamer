<?php

declare(strict_types=1);

use Raju\Streamer\Exceptions\VideoNotFound;
use Raju\Streamer\Playlist\HlsPlaylistRewriter;

$rewriter = new HlsPlaylistRewriter;

it('rewrites relative segment and key uris', function () use ($rewriter): void {
    $playlist = <<<'M3U8'
#EXTM3U
#EXT-X-KEY:METHOD=AES-128,URI="enc.key"
#EXT-X-MAP:URI="init.mp4"
#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="aac",NAME="English",URI="audio.m3u8"
#EXTINF:9.0,
seg0.ts
#EXTINF:9.0,
https://cdn.example.test/keep.ts
#EXT-X-ENDLIST
M3U8;

    $rewritten = $rewriter->rewrite($playlist, 'courses/lesson.m3u8', fn (string $path): string => 'signed:'.$path);

    expect($rewritten)
        ->toContain('signed:courses/enc.key')
        ->toContain('signed:courses/init.mp4')
        ->toContain('signed:courses/audio.m3u8')
        ->toContain('signed:courses/seg0.ts')
        ->toContain('https://cdn.example.test/keep.ts')
        ->not->toContain('URI="enc.key"');
});

it('rejects traversal inside a playlist uri', function () use ($rewriter): void {
    $playlist = "#EXTM3U\n#EXTINF:9.0,\n../secret.ts\n";

    $rewriter->rewrite($playlist, 'courses/lesson.m3u8', fn (string $path): string => $path);
})->throws(VideoNotFound::class);

it('rejects encoded traversal in a playlist uri', function () use ($rewriter): void {
    $playlist = "#EXTM3U\n#EXTINF:9.0,\n%2e%2e/secret.ts\n";

    $rewriter->rewrite($playlist, 'courses/lesson.m3u8', fn (string $path): string => $path);
})->throws(VideoNotFound::class);

it('rejects an invalid playlist', function () use ($rewriter): void {
    $rewriter->rewrite('not a playlist', 'lesson.m3u8', fn (string $path): string => $path);
})->throws(VideoNotFound::class);

it('does not invoke the resolver for absolute https uris', function () use ($rewriter): void {
    $called = [];

    $rewriter->rewrite(
        "#EXTM3U\nhttps://cdn.example.test/a.ts\n",
        'lesson.m3u8',
        function (string $path) use (&$called): string {
            $called[] = $path;

            return $path;
        },
    );

    expect($called)->toBe([]);
});
