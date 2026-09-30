<?php

declare(strict_types=1);

use Raju\Streamer\Drm\DrmConfiguration;
use Raju\Streamer\Drm\KeySystem;
use Raju\Streamer\Exceptions\DrmConfigurationException;

it('serializes browser-safe drm configuration', function (): void {
    $configuration = new DrmConfiguration(
        licenseServers: [KeySystem::Widevine->value => 'https://license.example.test/widevine'],
        licenseHeaders: ['Authorization' => 'Bearer short-lived-token'],
        manifestUrl: 'https://media.example.test/movie/manifest.mpd',
        contentHeaders: ['X-Playback-Token' => 'short-lived-token'],
        advanced: [
            KeySystem::Widevine->value => ['videoRobustness' => ['SW_SECURE_DECODE']],
        ],
    );

    expect($configuration->manifestUrl())->toBe('https://media.example.test/movie/manifest.mpd')
        ->and($configuration->toArray())->toBe([
            'servers' => [KeySystem::Widevine->value => 'https://license.example.test/widevine'],
            'license_headers' => ['Authorization' => 'Bearer short-lived-token'],
            'content_headers' => ['X-Playback-Token' => 'short-lived-token'],
            'certificate_url' => null,
            'certificate_headers' => [],
            'advanced' => [
                KeySystem::Widevine->value => ['videoRobustness' => ['SW_SECURE_DECODE']],
            ],
        ]);
});

it('allows http only for loopback development urls', function (): void {
    expect(fn () => new DrmConfiguration([
        KeySystem::ClearKey->value => 'http://localhost/license',
    ]))->not->toThrow(DrmConfigurationException::class);

    expect(fn () => new DrmConfiguration([
        KeySystem::ClearKey->value => 'http://[::1]/license',
    ]))->not->toThrow(DrmConfigurationException::class);

    expect(fn () => new DrmConfiguration([
        KeySystem::Widevine->value => 'http://license.example.test/widevine',
    ]))->toThrow(DrmConfigurationException::class);
});

it('rejects credentials and header injection', function (): void {
    expect(fn () => new DrmConfiguration([
        KeySystem::Widevine->value => 'https://user:password@license.example.test/widevine',
    ]))->toThrow(DrmConfigurationException::class);

    expect(fn () => new DrmConfiguration(
        [KeySystem::Widevine->value => 'https://license.example.test/widevine'],
        ['Authorization' => "Bearer token\r\nX-Injected: yes"],
    ))->toThrow(DrmConfigurationException::class);
});

it('rejects an empty server map and unsupported advanced keys', function (): void {
    expect(fn () => new DrmConfiguration([]))->toThrow(DrmConfigurationException::class);

    expect(fn () => new DrmConfiguration(
        [KeySystem::Widevine->value => 'https://license.example.test/widevine'],
        advanced: [KeySystem::Widevine->value => ['serverCertificate' => ['secret']]],
    ))->toThrow(DrmConfigurationException::class);
});
