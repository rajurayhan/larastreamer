# Changelog

All notable changes to this project will be documented in this file.

## [3.0.0] - 2026-09-07

Delivery-only breaking release. It is **not** a transcoding platform.

### Added

- `Streamable` Eloquent trait (`stream()`, `download()`, `streamUrl()`, `embedData()`) with no migrations
- `meta()`: size, mime, last_modified, etag (duration/codec only if an optional `Probe` is bound)
- Caption delivery for `.vtt` / `.srt` and player `<track>` elements
- Opt-in HLS/DASH **serving**: relative playlist URI rewrite to signed or temporary URLs
- Player HLS.js from a configurable CDN when `hls.player = hlsjs`
- Request-scoped `StreamContext` for `Streamer::authorize()`
- Conditional GET: `ETag`, `Last-Modified`, `If-None-Match` → 304, `If-Range`
- Player empty state: `<video data-empty="true">` with no `<source>`
- `embedData()` keys `kind` and `captions`
- CI on `feature/**` branches and `v*` tags
- PHPStan level 9

### Changed

- `Streamer::authorize()` is request-scoped and does not leak across HTTP requests
- `embedData()` returns a signed URL when routes are on; it never returns a raw disk path
- Remote `download()` redirects to `temporaryUrl()` with `Content-Disposition` instead of proxying through PHP
- nginx offload forwards `Range` (`X-Accel-Redirect`); PHP does not consume the body
- `VideoStreamCompleted` for local `BinaryFileResponse` fires from a terminating callback after the body
- Playlist responses use `Cache-Control: private, max-age=0, no-store`

### Removed

- `Raju\Streamer\Helpers\VideoStream` (the v1 shim). Use `Streamer::file($path)->stream()`.

### Security

- `security.signed_urls` is enforced: `signedUrl()` throws and the built-in route 404s when it is `false`
- HLS/DASH types are not on the default allowlist; enable `hls.enabled` / `dash.enabled`
- Relative `../` URIs inside playlists are 404
- Empty player never embeds a disk path

## [2.0.0] - 2026-09-07

v2.0 is a delivery-only rewrite. It is **not** a transcoding platform.

### Added

- Fluent API: `Streamer::disk()->file()->stream()`
- HTTP Range (`200` / `206` / `416`), suffix and open-ended ranges, and `HEAD`
- Laravel filesystem disks (local and S3-compatible via Flysystem)
- Redirect and `temporaryUrl()` delivery for remote objects
- Opt-in signed `GET /stream?file=` route (`signed` middleware required)
- Per-call and global `authorize()` hooks
- Path jail, MIME/extension allowlists, and package exceptions
- Optional nginx `X-Accel-Redirect` / Apache `X-Sendfile` offload
- Blade `<x-larastreamer::player />` component
- Domain events: `VideoStreamStarted`, `VideoStreamCompleted`, `VideoStreamFailed`, `VideoStreamUnauthorized`
- Configurable buffer, cache control, and `Content-Disposition`
- Pest + Testbench suite, PHPStan level 8, Pint, and GitHub Actions matrix

### Changed

- PHP `^8.3`, Laravel `^12 || ^13`
- Config is always merged (`mergeConfigFrom`); unpublished apps no longer resolve files from `/`
- Routes are **off** by default
- Default cache is `private`
- Remote disks redirect by default instead of proxying through PHP

### Deprecated

- `Raju\Streamer\Helpers\VideoStream` — removed in 3.0.

### Removed

- Unauthenticated `GET /stream/{filename}`
- `/streamer` hello route
- FFmpeg / HLS / metadata / Streamable trait (not in v2.0)

### Security

- Query `?file=` instead of `/{filename}`
- Realpath prefix jail against path traversal
- Client MIME is ignored
- Error responses never include absolute server paths
