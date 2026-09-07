<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Raju\Streamer\Http\Controllers\StreamController;

it('renders an empty player without a source when src and url are missing', function (): void {
    $html = (string) $this->blade('<x-larastreamer::player />');

    expect($html)
        ->toContain('<video')
        ->toContain('data-empty="true"')
        ->not->toContain('<source');
});

it('does not leak a disk path when routes are off', function (): void {
    $html = (string) $this->blade('<x-larastreamer::player src="clip.mp4" />');

    expect($html)
        ->toContain('data-empty="true"')
        ->not->toContain('<source')
        ->not->toContain('clip.mp4')
        ->not->toContain($this->diskRoot);
});

it('loads hls.js from the configured cdn when the player is hlsjs', function (): void {
    config([
        'larastreamer.hls.enabled' => true,
        'larastreamer.hls.player' => 'hlsjs',
        'larastreamer.storage.disk' => 'local',
        'larastreamer.storage.path' => '',
    ]);

    Route::get('stream', StreamController::class)
        ->middleware('signed')
        ->name('larastreamer.stream');

    $this->writeTextFixture('lesson.m3u8', "#EXTM3U\n#EXT-X-ENDLIST\n");

    $html = (string) $this->blade('<x-larastreamer::player src="lesson.m3u8" />');

    expect($html)
        ->toContain('cdn.jsdelivr.net/npm/hls.js')
        ->toContain('data-hls="true"')
        ->toContain('new Hls')
        ->not->toContain($this->diskRoot);
});
