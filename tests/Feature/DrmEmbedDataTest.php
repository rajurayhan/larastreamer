<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Raju\Streamer\Contracts\DrmProvider;
use Raju\Streamer\Drm\DrmConfiguration;
use Raju\Streamer\Drm\DrmContext;
use Raju\Streamer\Drm\KeySystem;
use Raju\Streamer\Exceptions\DrmConfigurationException;
use Raju\Streamer\Exceptions\UnauthorizedStream;
use Raju\Streamer\Facades\Streamer;
use Raju\Streamer\Http\Controllers\StreamController;

beforeEach(function (): void {
    config(['larastreamer.dash.enabled' => true]);
    $this->writeTextFixture('movie.mpd', '<?xml version="1.0"?><MPD></MPD>');
});

it('adds drm data and uses a CDN manifest override', function (): void {
    $configuration = new DrmConfiguration(
        [KeySystem::Widevine->value => 'https://license.example.test/widevine'],
        manifestUrl: 'https://media.example.test/movie/manifest.mpd',
    );

    $data = Streamer::disk('videos')->file('movie.mpd')->drm($configuration)->embedData();

    expect($data['url'])->toBe('https://media.example.test/movie/manifest.mpd')
        ->and($data['drm']['servers'])->toHaveKey(KeySystem::Widevine->value);
});

it('evaluates a provider after authorization with a safe context', function (): void {
    $provider = new class implements DrmProvider
    {
        public ?DrmContext $context = null;

        public function configuration(DrmContext $context): DrmConfiguration
        {
            $this->context = $context;

            return new DrmConfiguration(
                [KeySystem::Widevine->value => 'https://license.example.test/widevine'],
                manifestUrl: 'https://media.example.test/movie/manifest.mpd',
            );
        }
    };

    Streamer::disk('videos')->file('movie.mpd')->drm($provider)->embedData();

    expect($provider->context)->not->toBeNull()
        ->and($provider->context?->disk)->toBe('videos')
        ->and($provider->context?->path)->toBe('movie.mpd')
        ->and(property_exists($provider->context, 'localPath'))->toBeFalse();
});

it('does not evaluate a provider for an unauthorized viewer', function (): void {
    $provider = new class implements DrmProvider
    {
        public bool $called = false;

        public function configuration(DrmContext $context): DrmConfiguration
        {
            $this->called = true;

            return new DrmConfiguration([
                KeySystem::Widevine->value => 'https://license.example.test/widevine',
            ]);
        }
    };

    expect(fn () => Streamer::disk('videos')
        ->file('movie.mpd')
        ->authorize(fn (): bool => false)
        ->drm($provider)
        ->embedData())
        ->toThrow(UnauthorizedStream::class);

    expect($provider->called)->toBeFalse();
});

it('wraps provider failures without exposing their message', function (): void {
    $provider = new class implements DrmProvider
    {
        public function configuration(DrmContext $context): DrmConfiguration
        {
            throw new RuntimeException('vendor-secret-in-query');
        }
    };

    expect(fn () => Streamer::disk('videos')->file('movie.mpd')->drm($provider)->embedData())
        ->toThrow(
            DrmConfigurationException::class,
            'Unable to build DRM playback configuration.',
        );
});

it('rejects DRM configuration on a progressive file', function (): void {
    $configuration = new DrmConfiguration(
        [KeySystem::Widevine->value => 'https://license.example.test/widevine'],
        manifestUrl: 'https://media.example.test/movie.mp4',
    );

    expect(fn () => Streamer::disk('videos')->file('clip.mp4')->drm($configuration)->embedData())
        ->toThrow(DrmConfigurationException::class, 'DRM playback requires an HLS or DASH manifest.');
});

it('does not add a drm key to ordinary embed data', function (): void {
    config([
        'larastreamer.storage.disk' => 'videos',
        'larastreamer.storage.path' => '',
    ]);
    Route::get('stream', StreamController::class)
        ->middleware('signed')
        ->name('larastreamer.stream');

    $data = Streamer::disk('videos')->file('movie.mpd')->embedData();

    expect($data)->not->toHaveKey('drm');
});
