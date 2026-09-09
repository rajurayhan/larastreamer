# Provider-Neutral DRM Playback Design

**Date:** 2026-09-09

**Branch:** `rahat/drm-shaka-player`

**Status:** Approved in conversation; awaiting written-spec review

## Purpose

Add provider-neutral playback of externally packaged DRM content to Larastreamer while preserving its role as a delivery-only Laravel package.

Larastreamer will configure and authorize playback of encrypted HLS and DASH assets. It will not encrypt media, generate or store content keys, issue DRM licenses, proxy license challenges, or embed DRM vendor credentials.

## Goals

- Preserve the existing `<x-larastreamer::player>` component and all non-DRM behavior.
- Initialize Shaka Player only for streams with DRM configuration.
- Support browser configuration for Widevine, PlayReady, FairPlay, and ClearKey without coupling the package to a DRM vendor.
- Allow applications to generate short-lived, viewer-specific license and content-delivery authorization.
- Prefer private object storage behind a CDN for production delivery.
- Provide a secure local-storage fallback that supports dynamic DASH segment templates.
- Keep secrets, content keys, license payloads, and vendor credentials outside Larastreamer.

## Non-goals

- Media encoding, transcoding, encryption, or packaging.
- A Widevine, PlayReady, FairPlay, or ClearKey license server.
- A generic HTTP proxy for DRM licenses.
- Key generation, key rotation, KMS integration, or key persistence.
- Offline playback or persistent licenses in the first release.
- DRM-specific billing, concurrency limits, device registration, or HDCP policy enforcement.
- A guarantee against screen recording or capture after decryption.

## Compatibility

The existing player remains the public component:

```blade
<x-larastreamer::player src="protected/movie.mpd" :drm="$drm" />
```

Playback selection is conditional:

- Progressive MP4/WebM continues to use native `<video>`.
- Clear HLS continues to use the existing native or HLS.js path.
- Clear DASH retains the current behavior.
- DRM HLS/DASH initializes Shaka Player.

Calls that do not attach DRM configuration keep their current `embedData()` shape and rendered HTML. The optional `drm` field is emitted only for DRM-enabled streams.

## Public API

### Per-call configuration

```php
use Raju\Streamer\Drm\DrmConfiguration;
use Raju\Streamer\Drm\KeySystem;
use Raju\Streamer\Facades\Streamer;

$drm = new DrmConfiguration(
    licenseServers: [
        KeySystem::Widevine->value => route('drm.widevine', $movie),
        KeySystem::PlayReady->value => route('drm.playready', $movie),
    ],
    licenseHeaders: [
        'Authorization' => 'Bearer '.$playbackToken,
    ],
    manifestUrl: $cdn->signedManifestUrl($movie),
);

return Streamer::disk($movie->disk)
    ->file($movie->manifest_path)
    ->authorize(fn (string $path, $user): bool => $user?->can('watch', $movie) === true)
    ->drm($drm)
    ->embedData();
```

### Provider-generated configuration

Applications that need per-user or per-asset configuration can attach a provider:

```php
return Streamer::disk($movie->disk)
    ->file($movie->manifest_path)
    ->drm(app(MovieDrmProvider::class))
    ->embedData();
```

`PendingStream::drm()` accepts `DrmConfiguration|DrmProvider`. The provider is evaluated lazily after storage resolution and authorization, and only when DRM data is needed for player/embed output.

## New Components

### `Contracts\DrmProvider`

```php
interface DrmProvider
{
    public function configuration(DrmContext $context): DrmConfiguration;
}
```

The provider is application-owned. It can consult subscriptions, entitlements, a CDN signer, or an external DRM vendor, but it must return only browser-safe, short-lived values.

### `Drm\DrmContext`

An immutable value object containing:

- Disk name
- Resolved, jailed path
- Stream kind
- Authenticated user
- Current request

It does not expose an absolute server filesystem path.

### `Drm\DrmConfiguration`

An immutable value object containing:

- `licenseServers`: map of EME key-system identifier to HTTPS license URL
- `licenseHeaders`: short-lived headers added only to license requests
- `manifestUrl`: optional CDN/application manifest URL override
- `contentHeaders`: optional short-lived headers for manifest and segment requests
- `fairPlayCertificateUrl`: optional HTTPS certificate URL
- `certificateHeaders`: optional headers used only for certificate retrieval
- `advanced`: allowlisted Shaka DRM settings

The serialized form contains no PHP objects and is safe for JSON encoding in Blade.

### `Drm\KeySystem`

Known key-system identifiers are represented as an enum:

- `com.widevine.alpha`
- `com.microsoft.playready`
- `com.apple.fps`
- `org.w3.clearkey`

`DrmConfiguration` also accepts syntactically valid custom EME identifiers so the core remains provider-neutral.

### `Drm\PlaybackTicket`

The local-storage fallback uses a signed bearer ticket scoped to:

- One disk
- One normalized asset directory
- An expiration timestamp
- An optional authenticated-user identifier

The signature does not include the individual segment filename. This allows DASH to replace `$Number$` and `$Time$` without invalidating the ticket. Every request still performs ticket validation, path-scope validation, the existing filesystem jail, extension/MIME checks, and application authorization.

The ticket serializer and validator use Laravel's configured application key through an injectable signing service. Comparison is constant-time. Invalid, expired, malformed, wrong-disk, or out-of-scope tickets return 404 without revealing the accepted scope.

## Delivery Model

### Production: object storage and CDN

The recommended topology is a private S3-compatible origin behind a CDN:

1. An external packager writes encrypted HLS/DASH assets to object storage.
2. The application authorizes the viewer.
3. `DrmProvider` returns a short-lived CDN manifest URL or content-request authorization.
4. Shaka loads the manifest and segments from the CDN.
5. Shaka sends the license challenge directly to the external application/provider endpoint.
6. The browser CDM receives the license and decrypts media during playback.

Larastreamer and PHP are not in the segment-byte hot path. CDN signed cookies or path-scoped tokens are preferred over an individually signed URL for each segment.

### Local-storage fallback

For local files, Larastreamer generates a playback-ticket manifest URL. Playlist rewriting carries the same ticket to relative HLS/DASH segment URLs. The route validates the requested filename against the ticket's signed directory scope.

This replaces exact-filename signing only for the new DRM playback route. The existing signed route and `signedUrl()` behavior remain backward-compatible.

## Shaka Player Integration

New configuration values:

```php
'drm' => [
    'enabled' => true,
    'shaka_src' => 'https://cdn.jsdelivr.net/npm/shaka-player@5.2.9/dist/shaka-player.compiled.js',
    'fallback_message' => 'Protected playback is not supported on this device.',
],
```

Version 5.2.9 is the current stable Shaka release at design time and the pinned jsDelivr asset has been verified to exist. Applications can replace the URL with a self-hosted asset. The package will not use a floating major-version URL.

When DRM data is present, the Blade component:

1. Renders the existing `<video>` element without exposing raw storage paths.
2. Loads the configured Shaka script once per page.
3. Installs required Shaka polyfills.
4. Creates a Shaka player for the element.
5. Configures key-system license servers and allowlisted advanced settings.
6. Registers request filters that apply license, content, and certificate headers only to their matching request types.
7. Retrieves and configures the FairPlay certificate when required.
8. Loads the manifest URL.

Multiple DRM players on one page share the script-loading promise but have independent Shaka instances and configuration.

## Browser Events and Errors

Server-side configuration failures throw `DrmConfigurationException`, derived from `StreamException`. Messages are safe for logs and responses and contain no URL query strings, header values, tokens, license challenges, or vendor responses.

The player dispatches DOM events from its `<video>` element:

- `larastreamer:drm-ready`
- `larastreamer:drm-error`

The error event exposes a stable package error code, playback stage, and Shaka numeric code when available. It does not expose request headers, full protected URLs, license bodies, or certificates. The component also renders the configured fallback message in an accessible status element.

## Validation and Security Rules

- DRM playback requires HTTPS except on loopback/localhost development origins.
- License, manifest override, and certificate URLs must use HTTPS in non-local environments.
- URLs containing embedded username/password credentials are rejected.
- Header names must satisfy HTTP token syntax.
- Header values containing CR or LF are rejected.
- Only scalar, explicitly supported Shaka settings are serialized.
- The public DTO has no fields for DRM vendor credentials or raw content keys; documentation limits request headers to short-lived browser tokens.
- DRM configuration is generated only after normal storage and authorization checks succeed.
- DRM configuration, headers, query strings, challenges, and responses are excluded from package events.
- Playback tickets use short expirations and are treated as bearer credentials.
- License authorization remains the primary content-access control; encrypted segments alone are not sufficient to decrypt playback.

## Relevant Existing Fixes

The feature requires targeted corrections to existing delivery behavior:

1. Preserve the selected disk in generated local manifest, segment, and caption URLs.
2. Stop embedding exact-file Laravel signatures around dynamic DASH `$Number$` and `$Time$` templates.
3. Carry one consistent playback authorization scope through a rewritten manifest.
4. Define encrypted HLS key URI behavior without broadly allowing arbitrary `.key` or text files.
5. Ensure configured expiration types match the documented `DateTimeInterface|int|null` API.

These fixes are limited to behavior required for reliable DRM delivery; unrelated refactoring is excluded.

## Testing Strategy

All implementation follows test-driven development.

### Unit tests

- Valid and invalid `DrmConfiguration` values
- Known and custom EME key-system identifiers
- Header injection rejection
- URL validation and local-environment exceptions
- Safe serialization with optional fields
- Playback-ticket signing, expiry, tampering, disk binding, and path scope
- DASH `$Number$` and `$Time$` preservation
- HLS encrypted-key URI behavior

### Feature tests

- `PendingStream::drm()` with a direct configuration
- Lazy `DrmProvider` evaluation after authorization
- Denied viewers never receive DRM configuration
- Provider exceptions produce controlled package errors
- Named-disk manifest and segment delivery
- Local manifest rewriting reuses a scoped playback ticket
- Out-of-directory, traversal, wrong-disk, expired, and tampered requests return 404
- Existing progressive player output remains unchanged
- DRM player emits Shaka configuration and request filters safely
- FairPlay certificate configuration
- Multiple players load the Shaka script once
- Existing clear HLS/HLS.js behavior remains intact

### Development fixture

A ClearKey fixture verifies configuration and player wiring without requiring proprietary vendor credentials. Documentation explicitly states that ClearKey is for development/testing and is not production DRM.

Real Widevine, PlayReady, and FairPlay license issuance is covered by provider contract tests and documented manual integration checks because those systems require external licensed services and device CDMs.

## Documentation

The README will document:

- DRM architecture and responsibility boundaries
- Browser/key-system expectations
- Provider implementation example
- Blade and fluent API examples
- CDN-first production topology
- Local fallback limitations and ticket expiration
- Shaka source pinning and self-hosting
- External packaging requirements
- ClearKey's non-production status
- Troubleshooting events and safe logging guidance

The changelog will describe the new optional API without claiming that Larastreamer is a packager or license server.

## Completion Criteria

- Existing non-DRM public behavior and tests remain green.
- DRM configuration is provider-neutral and never contains server-side secrets.
- The Blade player conditionally plays configured encrypted HLS/DASH through Shaka.
- Widevine, PlayReady, and FairPlay endpoints can be configured independently.
- Local fallback supports named disks and dynamic DASH templates with scoped tickets.
- Production delivery can bypass PHP through a CDN manifest URL override.
- Unauthorized users cannot obtain DRM configuration or valid playback tickets.
- ClearKey development coverage and all new security regression tests pass.
- Pest, Pint, PHPStan level 9, and the supported PHP/Laravel CI matrix pass.
