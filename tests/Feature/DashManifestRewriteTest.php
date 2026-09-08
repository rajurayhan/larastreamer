<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Raju\Streamer\Facades\Streamer;
use Raju\Streamer\Http\Controllers\StreamController;

beforeEach(function (): void {
    config([
        'larastreamer.dash.enabled' => true,
        'larastreamer.storage.disk' => 'videos',
        'larastreamer.storage.path' => '',
    ]);

    Route::get('stream', StreamController::class)
        ->middleware('signed')
        ->name('larastreamer.stream');

    $this->writeFixture('courses/init.m4s', 256);
    $this->writeTextFixture('courses/manifest.mpd', <<<'MPD'
<?xml version="1.0"?>
<MPD xmlns="urn:mpeg:dash:schema:mpd:2011">
  <Period>
    <AdaptationSet>
      <Representation>
        <SegmentTemplate media="chunk-$Number$.m4s" initialization="init.m4s"/>
        <BaseURL href="https://cdn.example.test/keep.m4s"/>
      </Representation>
    </AdaptationSet>
  </Period>
</MPD>
MPD);
});

it('rewrites relative dash uris and leaves absolute hrefs', function (): void {
    $response = Streamer::disk('videos')->file('courses/manifest.mpd')->stream();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toBe('application/dash+xml')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');

    $body = (string) $response->getContent();

    expect($body)->toContain('/stream?')
        ->and($body)->toContain('file=')
        ->and($body)->toContain('init.m4s')
        ->and($body)->toContain('https://cdn.example.test/keep.m4s')
        ->and($body)->not->toContain('initialization="init.m4s"');
});

it('returns 404 for a dash playlist with traversal', function (): void {
    $this->writeTextFixture('courses/evil.mpd', <<<'MPD'
<?xml version="1.0"?>
<MPD>
  <SegmentTemplate media="../secret.m4s" initialization="init.m4s"/>
</MPD>
MPD);

    $response = Streamer::disk('videos')->file('courses/evil.mpd')->stream();

    expect($response->getStatusCode())->toBe(404);
});
