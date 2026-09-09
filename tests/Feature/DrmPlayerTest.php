<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Raju\Streamer\Drm\DrmConfiguration;
use Raju\Streamer\Drm\KeySystem;
use Raju\Streamer\Http\Controllers\StreamController;

beforeEach(function (): void {
    config([
        'larastreamer.route.enabled' => true,
        'larastreamer.dash.enabled' => true,
        'larastreamer.storage.disk' => 'videos',
        'larastreamer.storage.path' => '',
    ]);
    Route::get('stream', StreamController::class)
        ->middleware('signed')
        ->name('larastreamer.stream');
    $this->writeTextFixture('movie.mpd', '<?xml version="1.0"?><MPD></MPD>');
});

it('initializes pinned Shaka only for DRM playback', function (): void {
    $drm = new DrmConfiguration(
        [KeySystem::Widevine->value => 'https://license.example.test/widevine'],
        ['Authorization' => 'Bearer short-lived-token'],
        'https://media.example.test/movie/manifest.mpd',
    );

    $html = (string) $this->blade(
        '<x-larastreamer::player disk="videos" src="movie.mpd" :drm="$drm" />',
        compact('drm'),
    );

    expect($html)
        ->toContain('shaka-player@5.2.9')
        ->toContain('com.widevine.alpha')
        ->toContain('larastreamer:drm-ready')
        ->toContain('larastreamer:drm-error')
        ->toContain('LICENSE')
        ->not->toContain($this->diskRoot);
});

it('does not load Shaka for the existing progressive player', function (): void {
    $html = (string) $this->blade(
        '<x-larastreamer::player url="https://cdn.example.test/clip.mp4" mime="video/mp4" />',
    );

    expect($html)
        ->toContain('<source')
        ->not->toContain('shaka-player')
        ->not->toContain('larastreamer:drm-ready');
});

it('loads the Shaka script once for multiple DRM players', function (): void {
    $drm = new DrmConfiguration(
        [KeySystem::Widevine->value => 'https://license.example.test/widevine'],
        manifestUrl: 'https://media.example.test/movie/manifest.mpd',
    );

    $html = (string) $this->blade(
        '<x-larastreamer::player src="movie.mpd" :drm="$drm" /><x-larastreamer::player src="movie.mpd" :drm="$drm" />',
        compact('drm'),
    );

    expect(substr_count($html, 'shaka-player@5.2.9'))->toBe(1);
});
