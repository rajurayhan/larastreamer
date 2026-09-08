<?php

declare(strict_types=1);

use Raju\Streamer\Exceptions\VideoNotFound;
use Raju\Streamer\Playlist\DashManifestRewriter;

$rewriter = new DashManifestRewriter;

it('rewrites relative media and initialization uris', function () use ($rewriter): void {
    $mpd = <<<'MPD'
<?xml version="1.0"?>
<MPD>
  <SegmentTemplate media="chunk-$Number$.m4s" initialization="init.m4s"/>
  <BaseURL href="https://cdn.example.test/keep.m4s"/>
</MPD>
MPD;

    $rewritten = $rewriter->rewrite($mpd, 'courses/manifest.mpd', fn (string $path): string => 'signed:'.$path);

    expect($rewritten)
        ->toContain('signed:courses/chunk-$Number$.m4s')
        ->toContain('signed:courses/init.m4s')
        ->toContain('https://cdn.example.test/keep.m4s');
});

it('rejects traversal in a dash href', function () use ($rewriter): void {
    $rewriter->rewrite(
        '<?xml version="1.0"?><MPD href="../secret.m4s"></MPD>',
        'courses/manifest.mpd',
        fn (string $path): string => $path,
    );
})->throws(VideoNotFound::class);

it('rejects an invalid manifest', function () use ($rewriter): void {
    $rewriter->rewrite('<not-mpd/>', 'manifest.mpd', fn (string $path): string => $path);
})->throws(VideoNotFound::class);
