# Provider-Neutral DRM Playback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add provider-neutral Widevine, PlayReady, FairPlay, and development-only ClearKey playback to the existing Blade player through conditional Shaka Player integration.

**Architecture:** Externally packaged encrypted HLS/DASH remains on local storage or an application-supplied CDN. Immutable DRM configuration and provider contracts feed browser-safe data into `embedData()`, while an encrypted directory-scoped playback ticket supports local dynamic segments without signing an exact filename. The existing Blade component uses native playback for ordinary media and initializes pinned Shaka Player 5.2.9 only when DRM data is attached.

**Tech Stack:** PHP 8.3+, Laravel 12/13, Pest, Testbench, PHPStan level 9, Blade, Shaka Player 5.2.9, browser EME.

**Spec:** `docs/superpowers/specs/2026-09-09-drm-shaka-player-design.md`

## Global Constraints

- Work only on branch `rahat/drm-shaka-player`.
- Keep Larastreamer delivery-only: no encoding, packaging, content-key storage, license issuance, or license proxy.
- Preserve all behavior and rendered markup for calls that do not attach DRM.
- Never serialize raw content keys, server credentials, license challenges, license responses, or unfiltered advanced player configuration.
- Require HTTPS for DRM URLs except `http://localhost`, `http://127.0.0.1`, and `http://[::1]`.
- Load Shaka from exactly `https://cdn.jsdelivr.net/npm/shaka-player@5.2.9/dist/shaka-player.compiled.js` by default and allow a self-hosted replacement.
- Prefer a CDN manifest URL for production; local playback tickets are a fallback.
- Use `declare(strict_types=1)`, final classes by default, immutable DTOs, existing package naming, and existing exception response behavior.
- Follow TDD for every behavior change and commit after every task.
- Run Pest, Pint, and PHPStan before completion.

## File Structure

### New production files

- `src/Contracts/DrmProvider.php` — application extension point for viewer/content-specific DRM configuration.
- `src/Drm/DrmConfiguration.php` — validates and serializes browser-safe DRM settings.
- `src/Drm/DrmContext.php` — immutable provider input without absolute filesystem paths.
- `src/Drm/DrmResolver.php` — evaluates a direct configuration or provider after authorization.
- `src/Drm/KeySystem.php` — known EME key-system identifiers.
- `src/Drm/PlaybackTicket.php` — immutable decoded local-playback claims and path-scope check.
- `src/Drm/PlaybackTicketManager.php` — issues and validates encrypted, expiring tickets.
- `src/Drm/PlaybackUrlGenerator.php` — creates local manifest/segment URLs while preserving DASH templates.
- `src/Exceptions/DrmConfigurationException.php` — controlled configuration failure.
- `src/Http/Controllers/PlaybackController.php` — validates playback tickets before dispatching the existing stream pipeline.
- `resources/views/partials/shaka-player.blade.php` — isolated Shaka loader and per-player initialization.

### New test files

- `tests/Unit/DrmConfigurationTest.php`
- `tests/Unit/PlaybackTicketManagerTest.php`
- `tests/Unit/PlaybackUrlGeneratorTest.php`
- `tests/Feature/DrmEmbedDataTest.php`
- `tests/Enabled/DrmPlaybackRouteTest.php`
- `tests/Feature/DrmPlayerTest.php`
- `tests/Feature/ExpirationArgumentTest.php`

### Existing files to modify

- `src/Contracts/Streamer.php`
- `src/Facades/Streamer.php`
- `src/Streaming/PendingStream.php`
- `src/Streaming/VideoStreamer.php`
- `src/StreamServiceProvider.php`
- `src/Http/Controllers/StreamController.php`
- `src/Playlist/DashManifestRewriter.php`
- `config/larastreamer.php`
- `routes/web.php`
- `resources/views/components/player.blade.php`
- `tests/Feature/SignedUrlTest.php`
- `tests/Feature/DashManifestRewriteTest.php`
- `tests/Feature/HlsPlaylistRewriteTest.php`
- `README.md`
- `CHANGELOG.md`

---

### Task 1: Correct Expiration Types and Preserve Named Disks

**Files:**
- Create: `tests/Feature/ExpirationArgumentTest.php`
- Modify: `tests/Feature/SignedUrlTest.php`
- Modify: `src/Contracts/Streamer.php`
- Modify: `src/Facades/Streamer.php`
- Modify: `src/Streaming/PendingStream.php`
- Modify: `src/Streaming/VideoStreamer.php`
- Modify: `src/Http/Controllers/StreamController.php`

**Interfaces:**
- Produces: `Streamer::signedUrl(string $path, DateTimeInterface|int|null $expires = null, ?string $disk = null): string`.
- Produces: `PendingStream::redirect()`, `temporaryUrl()`, and `embedData()` accepting `DateTimeInterface|int|null`.
- Produces: signed URLs whose optional `disk` query parameter is covered by Laravel's signature and restored by `StreamController`.

- [ ] **Step 1: Write failing named-disk and integer-expiration tests**

Add to `tests/Feature/SignedUrlTest.php`:

```php
use Illuminate\Support\Facades\Storage;

it('preserves a named disk in a signed url', function (): void {
    Storage::fake('alternate');
    Storage::disk('alternate')->put('only.mp4', file_get_contents($this->diskRoot.'/clip.mp4'));

    $url = Streamer::signedUrl('only.mp4', now()->addMinutes(5), 'alternate');

    $this->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'video/mp4');
});

it('preserves the pending stream disk in embed data', function (): void {
    Storage::fake('alternate');
    Storage::disk('alternate')->put('only.mp4', file_get_contents($this->diskRoot.'/clip.mp4'));

    $data = Streamer::disk('alternate')->file('only.mp4')->embedData(300);

    expect($data['url'])->toContain('disk=alternate');
    $this->get($data['url'])->assertOk();
});
```

Create `tests/Feature/ExpirationArgumentTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Raju\Streamer\Facades\Streamer;
use Raju\Streamer\Http\Controllers\StreamController;

it('accepts integer seconds for pending embed data', function (): void {
    config([
        'larastreamer.storage.disk' => 'videos',
        'larastreamer.storage.path' => '',
    ]);

    Route::get('stream', StreamController::class)
        ->middleware('signed')
        ->name('larastreamer.stream');

    $data = Streamer::disk('videos')->file('clip.mp4')->embedData(120);

    expect($data['expires_at'])->toBeString()
        ->and($data['url'])->toContain('expires=');
});
```

- [ ] **Step 2: Run the focused tests and verify the current API fails**

Run:

```bash
vendor/bin/pest tests/Feature/SignedUrlTest.php tests/Feature/ExpirationArgumentTest.php
```

Expected: failure because `signedUrl()` lacks the disk argument and `PendingStream::embedData()` rejects an integer.

- [ ] **Step 3: Widen signatures and sign the disk name**

Use this signature in the contract, implementation, and facade docblock:

```php
public function signedUrl(
    string $path,
    DateTimeInterface|int|null $expires = null,
    ?string $disk = null,
): string;
```

Build signed parameters without emitting an empty disk:

```php
$parameters = ['file' => $path];

if (is_string($disk) && $disk !== '') {
    $parameters['disk'] = $disk;
}

return $this->urls->temporarySignedRoute(
    $name,
    $this->expiration($expires),
    $parameters,
);
```

Update the three pending methods and matching `VideoStreamer` methods to accept `DateTimeInterface|int|null`. Restore the signed disk in `StreamController`:

```php
$disk = $request->query('disk');
$pending = is_string($disk) && $disk !== ''
    ? $streamer->disk($disk)->file($file)
    : $streamer->file($file);

return $pending->stream();
```

When `publicUrl()` signs a local pending stream, pass `$pending->diskName()` as the third argument. When a local playlist signs a segment, pass `$playlist->disk`.

- [ ] **Step 4: Run focused and regression tests**

Run:

```bash
vendor/bin/pest tests/Feature/SignedUrlTest.php tests/Feature/ExpirationArgumentTest.php tests/Feature/HlsPlaylistRewriteTest.php tests/Feature/CaptionsTest.php
```

Expected: all tests pass and generated named-disk URLs contain a signed `disk` query parameter.

- [ ] **Step 5: Commit the compatibility fixes**

```bash
git add src/Contracts/Streamer.php src/Facades/Streamer.php src/Streaming/PendingStream.php src/Streaming/VideoStreamer.php src/Http/Controllers/StreamController.php tests/Feature/SignedUrlTest.php tests/Feature/ExpirationArgumentTest.php
git commit -m "fix: preserve stream disks and expiration types"
```

---

### Task 2: Add Validated DRM Value Objects

**Files:**
- Create: `src/Drm/KeySystem.php`
- Create: `src/Drm/DrmConfiguration.php`
- Create: `src/Exceptions/DrmConfigurationException.php`
- Create: `tests/Unit/DrmConfigurationTest.php`

**Interfaces:**
- Produces: `KeySystem` string-backed enum with `Widevine`, `PlayReady`, `FairPlay`, and `ClearKey` cases.
- Produces: immutable `DrmConfiguration` constructor and `toArray(): array`.
- Produces: `DrmConfiguration::manifestUrl(): ?string` for server-side URL selection.

- [ ] **Step 1: Write failing configuration validation tests**

Create `tests/Unit/DrmConfigurationTest.php` with these cases:

```php
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
```

- [ ] **Step 2: Run the unit test and verify missing classes fail**

Run:

```bash
vendor/bin/pest tests/Unit/DrmConfigurationTest.php
```

Expected: failure because the DRM classes do not exist.

- [ ] **Step 3: Implement the enum, exception, and immutable configuration**

Create `KeySystem`:

```php
enum KeySystem: string
{
    case Widevine = 'com.widevine.alpha';
    case PlayReady = 'com.microsoft.playready';
    case FairPlay = 'com.apple.fps';
    case ClearKey = 'org.w3.clearkey';
}
```

Create a final `DrmConfigurationException extends StreamException`. Implement `DrmConfiguration` with this public constructor:

```php
public function __construct(
    array $licenseServers,
    array $licenseHeaders = [],
    ?string $manifestUrl = null,
    array $contentHeaders = [],
    ?string $fairPlayCertificateUrl = null,
    array $certificateHeaders = [],
    array $advanced = [],
) {}
```

Use these explicit validation constants:

```php
private const ADVANCED_KEYS = ['audioRobustness', 'videoRobustness'];

private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1'];
```

Validate key-system IDs and header names with these patterns:

```php
private const KEY_SYSTEM_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]+$/';

private const HEADER_NAME_PATTERN = "/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/";
```

Reject CR/LF in header values, reject URL user/password components, and accept only HTTPS or HTTP on the listed loopback hosts. `advanced` may contain only key-system maps whose keys are in `ADVANCED_KEYS` and whose values are lists of non-empty strings.

Return this exact browser payload from `toArray()`:

```php
return [
    'servers' => $this->licenseServers,
    'license_headers' => $this->licenseHeaders,
    'content_headers' => $this->contentHeaders,
    'certificate_url' => $this->fairPlayCertificateUrl,
    'certificate_headers' => $this->certificateHeaders,
    'advanced' => $this->advanced,
];
```

Keep `manifestUrl` server-side through `manifestUrl()` and do not duplicate it inside the DRM payload.

- [ ] **Step 4: Run unit tests and static analysis for the new domain model**

Run:

```bash
vendor/bin/pest tests/Unit/DrmConfigurationTest.php
vendor/bin/phpstan analyse src/Drm src/Exceptions --memory-limit=1G
```

Expected: both commands succeed.

- [ ] **Step 5: Commit the DRM value objects**

```bash
git add src/Drm/KeySystem.php src/Drm/DrmConfiguration.php src/Exceptions/DrmConfigurationException.php tests/Unit/DrmConfigurationTest.php
git commit -m "feat: add validated DRM configuration"
```

---

### Task 3: Resolve Direct and Provider-Generated DRM Configuration

**Files:**
- Create: `src/Contracts/DrmProvider.php`
- Create: `src/Drm/DrmContext.php`
- Create: `src/Drm/DrmResolver.php`
- Create: `tests/Feature/DrmEmbedDataTest.php`
- Modify: `src/Streaming/PendingStream.php`
- Modify: `src/Streaming/VideoStreamer.php`
- Modify: `src/StreamServiceProvider.php`

**Interfaces:**
- Consumes: `DrmConfiguration` from Task 2.
- Produces: `DrmProvider::configuration(DrmContext $context): DrmConfiguration`.
- Produces: `PendingStream::drm(DrmConfiguration|DrmProvider $drm): self` and `drmSource(): DrmConfiguration|DrmProvider|null`.
- Produces: optional `drm` payload and CDN manifest override from `embedData()`.

- [ ] **Step 1: Write failing direct/provider integration tests**

Create `tests/Feature/DrmEmbedDataTest.php`:

```php
<?php

declare(strict_types=1);

use Raju\Streamer\Contracts\DrmProvider;
use Raju\Streamer\Drm\DrmConfiguration;
use Raju\Streamer\Drm\DrmContext;
use Raju\Streamer\Drm\KeySystem;
use Raju\Streamer\Exceptions\DrmConfigurationException;
use Raju\Streamer\Exceptions\UnauthorizedStream;
use Raju\Streamer\Facades\Streamer;
use Raju\Streamer\Http\Controllers\StreamController;
use Illuminate\Support\Facades\Route;

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
```

- [ ] **Step 2: Run the feature test and verify missing integration fails**

Run:

```bash
vendor/bin/pest tests/Feature/DrmEmbedDataTest.php
```

Expected: failure because `DrmProvider`, `DrmContext`, and `PendingStream::drm()` do not exist.

- [ ] **Step 3: Implement provider resolution and optional embed payload**

Define the provider contract exactly as:

```php
interface DrmProvider
{
    public function configuration(DrmContext $context): DrmConfiguration;
}
```

Define `DrmContext` as a final readonly class:

```php
public function __construct(
    public string $disk,
    public string $path,
    public StreamKind $kind,
    public mixed $user,
    public Request $request,
) {}
```

Do not expose `ResolvedVideo::localPath`. Change the context assertion to `expect(property_exists($provider->context, 'localPath'))->toBeFalse()`.

Implement `DrmResolver::resolve()`:

```php
public function resolve(
    DrmConfiguration|DrmProvider $source,
    ResolvedVideo $video,
    Request $request,
): DrmConfiguration {
    $kind = StreamKind::fromPath($video->path);

    if (! in_array($kind, [StreamKind::Hls, StreamKind::Dash], true)) {
        throw new DrmConfigurationException('DRM playback requires an HLS or DASH manifest.');
    }

    if ($source instanceof DrmConfiguration) {
        return $source;
    }

    try {
        return $source->configuration(new DrmContext(
            disk: $video->disk,
            path: $video->path,
            kind: $kind,
            user: $request->user(),
            request: $request,
        ));
    } catch (DrmConfigurationException $exception) {
        throw $exception;
    } catch (Throwable $exception) {
        throw new DrmConfigurationException('Unable to build DRM playback configuration.', previous: $exception);
    }
}
```

Store the union on `PendingStream`. In `VideoStreamer::embedData()`, call `prepare()` first, then resolve DRM. Use `DrmConfiguration::manifestUrl()` as the top-level URL when present and append `'drm' => $configuration->toArray()` only when a DRM source exists. Update its return PHPDoc so `drm` is an optional array key. Bind `DrmResolver` as a singleton and inject it into `VideoStreamer`.

- [ ] **Step 4: Run focused tests and PHPStan**

Run:

```bash
vendor/bin/pest tests/Feature/DrmEmbedDataTest.php tests/Feature/StreamVideoTest.php tests/Feature/MetadataTest.php
vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: all commands succeed; ordinary embed data remains backward-compatible.

- [ ] **Step 5: Commit provider integration**

```bash
git add src/Contracts/DrmProvider.php src/Drm/DrmContext.php src/Drm/DrmResolver.php src/Streaming/PendingStream.php src/Streaming/VideoStreamer.php src/StreamServiceProvider.php tests/Feature/DrmEmbedDataTest.php
git commit -m "feat: resolve per-stream DRM providers"
```

---

### Task 4: Implement Directory-Scoped Playback Tickets

**Files:**
- Create: `src/Drm/PlaybackTicket.php`
- Create: `src/Drm/PlaybackTicketManager.php`
- Create: `tests/Unit/PlaybackTicketManagerTest.php`
- Modify: `src/StreamServiceProvider.php`

**Interfaces:**
- Produces: `PlaybackTicketManager::issue(string $disk, string $manifestPath, DateTimeInterface $expiresAt, mixed $userId): string`.
- Produces: `PlaybackTicketManager::validate(string $token, string $requestedPath, mixed $userId): PlaybackTicket`.
- Produces: `PlaybackTicket::allows(string $path): bool`.

- [ ] **Step 1: Write failing ticket security tests**

Create `tests/Unit/PlaybackTicketManagerTest.php`:

```php
<?php

declare(strict_types=1);

use Raju\Streamer\Drm\PlaybackTicketManager;
use Raju\Streamer\Exceptions\VideoNotFound;

it('issues a ticket scoped to the manifest directory and user', function (): void {
    $manager = app(PlaybackTicketManager::class);
    $token = $manager->issue('videos', 'movies/one/manifest.mpd', now()->addMinute(), 42);

    $ticket = $manager->validate($token, 'movies/one/chunk-1.m4s', 42);

    expect($ticket->disk)->toBe('videos')
        ->and($ticket->scope)->toBe('movies/one')
        ->and($ticket->allows('movies/one/init.m4s'))->toBeTrue()
        ->and($ticket->allows('movies/two/init.m4s'))->toBeFalse();
});

it('rejects tampering expiry traversal and a different user', function (): void {
    $manager = app(PlaybackTicketManager::class);
    $valid = $manager->issue('videos', 'movies/one/manifest.mpd', now()->addMinute(), 42);
    $expired = $manager->issue('videos', 'movies/one/manifest.mpd', now()->subSecond(), 42);

    foreach ([
        [$valid.'x', 'movies/one/chunk-1.m4s', 42],
        [$expired, 'movies/one/chunk-1.m4s', 42],
        [$valid, 'movies/two/chunk-1.m4s', 42],
        [$valid, 'movies/one/../two/chunk-1.m4s', 42],
        [$valid, 'movies/one/chunk-1.m4s', 7],
    ] as [$token, $path, $userId]) {
        expect(fn () => $manager->validate($token, $path, $userId))
            ->toThrow(VideoNotFound::class);
    }
});
```

- [ ] **Step 2: Run the unit test and verify the manager is missing**

Run:

```bash
vendor/bin/pest tests/Unit/PlaybackTicketManagerTest.php
```

Expected: failure because the ticket classes do not exist.

- [ ] **Step 3: Implement encrypted ticket claims and validation**

Use `Illuminate\Contracts\Encryption\Encrypter` rather than implementing cryptography. The claims encoded through `encryptString()` are exactly:

```php
[
    'v' => 1,
    'disk' => $disk,
    'scope' => $this->scopeFor($manifestPath),
    'exp' => $expiresAt->getTimestamp(),
    'uid' => $this->normalizeUserId($userId),
]
```

Normalize paths by converting backslashes to slashes, rejecting NUL, URL-decoded traversal segments, absolute paths, and empty requested paths. `scopeFor()` returns normalized `dirname($manifestPath)` or an empty string for a root-level manifest. `PlaybackTicket::allows()` accepts all normalized descendants of the scope; an empty scope means the ticket is scoped to that disk root.

Validation must:

```php
try {
    $payload = json_decode(
        $this->encrypter->decryptString($token),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
} catch (Throwable $exception) {
    throw new VideoNotFound(previous: $exception);
}
```

Then require version `1`, non-empty disk, integer expiration not earlier than `time()`, scalar/null normalized user ID matching the current user, and an allowed requested path. Return `VideoNotFound` for every invalid condition.

Bind `PlaybackTicketManager` as a singleton.

- [ ] **Step 4: Run ticket tests and static analysis**

Run:

```bash
vendor/bin/pest tests/Unit/PlaybackTicketManagerTest.php tests/Feature/PathTraversalTest.php
vendor/bin/phpstan analyse src/Drm --memory-limit=1G
```

Expected: all commands succeed.

- [ ] **Step 5: Commit playback-ticket primitives**

```bash
git add src/Drm/PlaybackTicket.php src/Drm/PlaybackTicketManager.php src/StreamServiceProvider.php tests/Unit/PlaybackTicketManagerTest.php
git commit -m "feat: add scoped DRM playback tickets"
```

---

### Task 5: Add the Playback Route and Propagate Tickets Through Playlists

**Files:**
- Create: `src/Drm/PlaybackUrlGenerator.php`
- Create: `src/Http/Controllers/PlaybackController.php`
- Create: `tests/Unit/PlaybackUrlGeneratorTest.php`
- Create: `tests/Enabled/DrmPlaybackRouteTest.php`
- Modify: `config/larastreamer.php`
- Modify: `routes/web.php`
- Modify: `src/Streaming/PendingStream.php`
- Modify: `src/Streaming/VideoStreamer.php`
- Modify: `src/StreamServiceProvider.php`
- Modify: `src/Playlist/DashManifestRewriter.php`
- Modify: `tests/Feature/DashManifestRewriteTest.php`
- Modify: `tests/Feature/HlsPlaylistRewriteTest.php`

**Interfaces:**
- Consumes: `PlaybackTicketManager` and optional DRM embed data.
- Produces: route name `larastreamer.playback` at `/{route.prefix}/playback`.
- Produces: `PlaybackUrlGenerator::url(string $path, string $ticket): string` preserving DASH template identifiers.
- Produces: internal `PendingStream::playbackTicket(string $ticket): self` and `playbackTicketValue(): ?string`.

- [ ] **Step 1: Write failing URL-template and route-security tests**

Create `tests/Unit/PlaybackUrlGeneratorTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Raju\Streamer\Drm\PlaybackUrlGenerator;

it('preserves supported DASH template identifiers', function (): void {
    Route::get('/stream/playback', fn () => '')->name('larastreamer.playback');

    $url = app(PlaybackUrlGenerator::class)->url(
        'movie/chunk-$RepresentationID$-$Number%05d$-$Bandwidth$-$Time$.m4s',
        'opaque-ticket',
    );

    expect($url)
        ->toContain('$RepresentationID$')
        ->toContain('$Number%05d$')
        ->toContain('$Bandwidth$')
        ->toContain('$Time$')
        ->toContain('ticket=opaque-ticket');
});
```

Create `tests/Enabled/DrmPlaybackRouteTest.php` so it inherits `RoutedTestCase`, with these assertions:

```php
it('serves a named-disk manifest and segment with one scoped ticket', function (): void {
    config([
        'larastreamer.route.enabled' => true,
        'larastreamer.dash.enabled' => true,
        'larastreamer.storage.disk' => 'local',
        'larastreamer.storage.path' => '',
    ]);

    $this->writeTextFixture('movie/manifest.mpd', <<<'MPD'
<?xml version="1.0"?><MPD><SegmentTemplate media="chunk-$Number$.m4s" initialization="init.m4s"/></MPD>
MPD);
    $this->writeFixture('movie/init.m4s', 256);
    $this->writeFixture('movie/chunk-1.m4s', 256);

    $configuration = new \Raju\Streamer\Drm\DrmConfiguration([
        \Raju\Streamer\Drm\KeySystem::ClearKey->value => 'http://localhost/license',
    ]);

    $data = \Raju\Streamer\Facades\Streamer::disk('videos')
        ->file('movie/manifest.mpd')
        ->drm($configuration)
        ->embedData(300);

    $manifest = $this->get($data['url'])->assertOk();
    $body = (string) $manifest->getContent();

    expect($body)->toContain('$Number$')->toContain('ticket=');

    preg_match('/initialization="([^"]+)"/', $body, $matches);
    $initializationUrl = html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_XML1);
    $this->get($initializationUrl)->assertOk();
});

it('rejects an out-of-scope file using a valid playback ticket', function (): void {
    $manager = app(\Raju\Streamer\Drm\PlaybackTicketManager::class);
    $token = $manager->issue('videos', 'movie/manifest.mpd', now()->addMinute(), null);

    $url = route('larastreamer.playback', [
        'file' => 'other/clip.mp4',
        'ticket' => $token,
    ]);

    $this->get($url)->assertNotFound();
});
```

Add an HLS regression asserting `skd://fairplay.example.test/asset` remains unchanged and is not converted into a local `.key` URL.

- [ ] **Step 2: Run focused tests and verify missing route/generator failures**

Run:

```bash
vendor/bin/pest tests/Unit/PlaybackUrlGeneratorTest.php tests/Enabled/DrmPlaybackRouteTest.php tests/Feature/HlsPlaylistRewriteTest.php
```

Expected: failure because no playback route or URL generator exists.

- [ ] **Step 3: Implement template-safe URL generation**

Before calling Laravel's route generator, replace each match of this pattern with an ASCII marker:

```php
private const DASH_TEMPLATE = '/\$(?:RepresentationID|Number(?:%0\d+d)?|Bandwidth|Time(?:%0\d+d)?)\$/';
```

Use markers such as `__LARASTREAMER_DASH_0__`, generate the route with `file` and `ticket`, then replace each marker in the completed URL with its original template identifier. Throw `StreamException` if the configured playback route does not exist.

- [ ] **Step 4: Implement the playback controller and route**

Add configuration:

```php
'drm' => [
    'enabled' => true,
    'shaka_src' => 'https://cdn.jsdelivr.net/npm/shaka-player@5.2.9/dist/shaka-player.compiled.js',
    'fallback_message' => 'Protected playback is not supported on this device.',
    'playback_route_name' => 'larastreamer.playback',
    'playback_middleware' => [],
],
```

Register this route after the existing signed route:

```php
Route::get($prefix.'/playback', PlaybackController::class)
    ->middleware(is_array($playbackMiddleware) ? $playbackMiddleware : [])
    ->name($playbackName);
```

The controller reads non-empty string `file` and `ticket`, derives the authenticated user's identifier when available, validates the ticket, and delegates:

```php
$claims = $tickets->validate($ticket, $file, $userId);

return $streamer->disk($claims->disk)
    ->file($file)
    ->playbackTicket($ticket)
    ->stream();
```

- [ ] **Step 5: Generate local DRM manifest URLs and propagate the ticket**

In `VideoStreamer::embedData()`, when DRM exists, has no `manifestUrl`, and the resolved video is local:

```php
$ticket = $this->playbackTickets->issue(
    $video->disk,
    $video->path,
    $expiration,
    $this->userId(),
);
$url = $this->playbackUrls->url($video->path, $ticket);
```

Change playlist callback construction so `segmentUrl()` receives `PendingStream`. When `playbackTicketValue()` is non-null, generate every relative URI with `PlaybackUrlGenerator`; otherwise retain named-disk signed URLs for local clear playlists and temporary URLs for remote playlists.

Keep absolute `skd://` FairPlay URIs untouched. Do not add `.key` to the MIME allowlist; raw AES-128 key serving is not DRM and remains application-owned.

- [ ] **Step 6: Run playback, playlist, and security regressions**

Run:

```bash
vendor/bin/pest tests/Unit/PlaybackUrlGeneratorTest.php tests/Enabled/DrmPlaybackRouteTest.php tests/Feature/DashManifestRewriteTest.php tests/Feature/HlsPlaylistRewriteTest.php tests/Feature/PathTraversalTest.php tests/Feature/SignedUrlTest.php
vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: all commands succeed.

- [ ] **Step 7: Commit scoped route delivery**

```bash
git add src/Drm/PlaybackUrlGenerator.php src/Http/Controllers/PlaybackController.php config/larastreamer.php routes/web.php src/Streaming/PendingStream.php src/Streaming/VideoStreamer.php src/StreamServiceProvider.php src/Playlist/DashManifestRewriter.php tests/Unit/PlaybackUrlGeneratorTest.php tests/Enabled/DrmPlaybackRouteTest.php tests/Feature/DashManifestRewriteTest.php tests/Feature/HlsPlaylistRewriteTest.php
git commit -m "feat: deliver DRM assets with scoped tickets"
```

---

### Task 6: Initialize Shaka Conditionally in the Existing Blade Player

**Files:**
- Create: `resources/views/partials/shaka-player.blade.php`
- Create: `tests/Feature/DrmPlayerTest.php`
- Modify: `resources/views/components/player.blade.php`

**Interfaces:**
- Consumes: optional top-level `drm` data from `embedData()`.
- Produces: existing `<x-larastreamer::player>` props plus optional `disk` and `drm`.
- Produces: DOM events `larastreamer:drm-ready` and `larastreamer:drm-error`.

- [ ] **Step 1: Write failing player compatibility and DRM tests**

Create `tests/Feature/DrmPlayerTest.php`:

```php
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
```

- [ ] **Step 2: Run player tests and verify Shaka assertions fail**

Run:

```bash
vendor/bin/pest tests/Feature/DrmPlayerTest.php tests/Feature/PlayerEmptyStateTest.php
```

Expected: DRM tests fail while existing player tests remain green.

- [ ] **Step 3: Extend the existing component without changing ordinary output**

Add props:

```blade
@props([
    'disk' => null,
    'drm' => null,
])
```

When resolving `src`, select the named disk when provided and attach DRM only when it implements `DrmConfiguration` or `DrmProvider`:

```php
$pending = is_string($disk) && $disk !== ''
    ? \Raju\Streamer\Facades\Streamer::disk($disk)->file($src)
    : \Raju\Streamer\Facades\Streamer::file($src);

if ($drm instanceof \Raju\Streamer\Drm\DrmConfiguration
    || $drm instanceof \Raju\Streamer\Contracts\DrmProvider) {
    $pending->drm($drm);
}

$embed = $pending->embedData();
```

Rethrow `DrmConfigurationException`; retain the current empty-state catch for other `StreamException` instances. Set `$drmPayload` only when `$embed['drm']` is an array and render the Shaka partial only when it is non-empty.

- [ ] **Step 4: Implement the isolated Shaka loader and request filters**

Use Blade `@once` to define a page-global promise that loads the exact configured script once:

```javascript
window.__larastreamerShaka = window.__larastreamerShaka || new Promise(function (resolve, reject) {
    var script = document.createElement('script');
    script.src = shakaSource;
    script.onload = function () { resolve(window.shaka); };
    script.onerror = function () { reject(new Error('shaka_load_failed')); };
    document.head.appendChild(script);
});
```

For each player, deserialize configuration with `Illuminate\Support\Js::from()`, install polyfills, create `new shaka.Player()`, attach the media element, and configure:

```javascript
player.configure({
    drm: {
        servers: drm.servers,
        advanced: drm.advanced
    }
});
```

Register a networking request filter:

```javascript
player.getNetworkingEngine().registerRequestFilter(function (type, request) {
    var requestType = shaka.net.NetworkingEngine.RequestType;
    var headers = {};

    if (type === requestType.LICENSE) {
        headers = drm.license_headers;
    } else if (type === requestType.MANIFEST || type === requestType.SEGMENT) {
        headers = drm.content_headers;
    }

    Object.keys(headers || {}).forEach(function (name) {
        request.headers[name] = headers[name];
    });
});
```

If `certificate_url` is present, fetch it with only `certificate_headers`, require an OK response, convert it to `Uint8Array`, and set `drm.advanced['com.apple.fps'].serverCertificate` before `player.load(manifestUrl)`.

On success, dispatch `new CustomEvent('larastreamer:drm-ready')`. On failure, reveal the accessible fallback status and dispatch `larastreamer:drm-error` with exactly `{code, stage, shakaCode}`; never include configuration, URLs, headers, or error payload bodies.

- [ ] **Step 5: Run all player and view tests**

Run:

```bash
vendor/bin/pest tests/Feature/DrmPlayerTest.php tests/Feature/PlayerEmptyStateTest.php tests/Feature/CaptionsTest.php tests/Feature/StreamVideoTest.php
vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: all commands succeed and non-DRM output contains no Shaka script.

- [ ] **Step 6: Commit the conditional player engine**

```bash
git add resources/views/components/player.blade.php resources/views/partials/shaka-player.blade.php tests/Feature/DrmPlayerTest.php
git commit -m "feat: play DRM streams with Shaka Player"
```

---

### Task 7: Document Provider Integration and Security Boundaries

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Documents: `DrmConfiguration`, `DrmProvider`, `PendingStream::drm()`, Blade `disk`/`drm` props, playback tickets, CDN manifest override, and player events.

- [ ] **Step 1: Add README examples that match the tested API**

Add a DRM section containing this minimal provider-neutral example:

```php
$drm = new DrmConfiguration(
    licenseServers: [
        KeySystem::Widevine->value => route('licenses.widevine', $movie),
        KeySystem::PlayReady->value => route('licenses.playready', $movie),
    ],
    licenseHeaders: ['Authorization' => 'Bearer '.$shortLivedPlaybackToken],
    manifestUrl: $cdn->signedManifestUrl($movie),
);

return Streamer::disk($movie->disk)
    ->file($movie->manifest_path)
    ->drm($drm)
    ->embedData();
```

Add the Blade example:

```blade
<x-larastreamer::player
    disk="videos"
    src="protected/movie.mpd"
    :drm="$drm"
/>
```

State explicitly that Widevine, PlayReady, and FairPlay require externally encrypted packages and licensed external services; ClearKey is development-only; HTTPS is required; and the package never accepts raw keys or vendor server credentials.

- [ ] **Step 2: Document CDN-first and local-fallback delivery**

Document private S3-compatible origin plus CDN as the production recommendation. Explain that `manifestUrl` keeps segment bytes outside PHP, while the local fallback creates an expiring directory-scoped bearer ticket. Explain that DRM protects decryption keys and does not prevent screen capture.

Document player events exactly:

```javascript
video.addEventListener('larastreamer:drm-ready', function () {
    console.log('Protected playback is ready');
});

video.addEventListener('larastreamer:drm-error', function (event) {
    console.error(event.detail.code, event.detail.stage, event.detail.shakaCode);
});
```

- [ ] **Step 3: Add an unreleased changelog entry**

At the top of `CHANGELOG.md`, add:

```markdown
## [Unreleased]

### Added

- Provider-neutral DRM playback configuration for Widevine, PlayReady, FairPlay, and development-only ClearKey
- Conditional Shaka Player 5.2.9 integration in the existing Blade player
- Directory-scoped playback tickets for local encrypted HLS/DASH assets
- CDN manifest overrides and separated license/content/certificate request headers

### Fixed

- Preserve named disks in signed local stream URLs
- Accept documented integer expiration values across pending stream URL methods
- Preserve dynamic DASH template identifiers in local playback URLs
```

- [ ] **Step 4: Verify documentation references real symbols and contains no secrets**

Run:

```bash
rg -n "DrmConfiguration|DrmProvider|larastreamer:drm|shaka-player@5.2.9|ClearKey" README.md CHANGELOG.md
rg -n -i "private[_ -]?key|client[_ -]?secret|raw[_ -]?key" README.md CHANGELOG.md
```

Expected: the first command finds every documented API; the second finds only warnings that such secrets are not accepted, never credential values.

- [ ] **Step 5: Commit documentation**

```bash
git add README.md CHANGELOG.md
git commit -m "docs: explain provider-neutral DRM playback"
```

---

### Task 8: Full Verification and Branch Review

**Files:**
- Review: every file changed since `master`.

**Interfaces:**
- Verifies: all public APIs, security boundaries, formatting, static types, and regressions defined by Tasks 1–7.

- [ ] **Step 1: Run the complete test suite**

```bash
vendor/bin/pest
```

Expected: every existing and new Pest test passes.

- [ ] **Step 2: Run formatting verification**

```bash
vendor/bin/pint --test
```

Expected: exit code 0 with no formatting changes required.

- [ ] **Step 3: Run static analysis**

```bash
vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: exit code 0 at configured level 9.

- [ ] **Step 4: Inspect repository integrity and the complete branch diff**

```bash
git diff --check master...HEAD
git status --short --branch
git diff --stat master...HEAD
git log --oneline --decorate master..HEAD
```

Expected: no whitespace errors, no uncommitted implementation files, and focused commits for compatibility, domain model, provider integration, tickets, player, and documentation.

- [ ] **Step 5: Review security-sensitive flows manually**

Confirm from the diff that:

```text
authorization -> storage resolution -> DRM provider -> browser-safe serialization
playback ticket -> decrypt -> expiry/user/scope validation -> existing jail -> stream
Shaka request type -> matching header map only -> external endpoint
```

Confirm no package event, exception message, rendered fallback, or DOM error detail contains headers, tokens, query strings, license payloads, certificates, absolute filesystem paths, or content keys.

- [ ] **Step 6: Record the verification result in the final task response**

Report exact Pest test count, Pint result, PHPStan result, branch name, commit list, and any limitation that requires an external licensed DRM service. Do not claim real Widevine, PlayReady, or FairPlay playback was exercised unless an actual licensed test service and compatible CDM were used.
