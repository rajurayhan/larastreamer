# Changelog

All notable changes to this project will be documented in this file.

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

- `Raju\Streamer\Helpers\VideoStream` — use `Streamer::file($path)->stream()`. The shim no longer calls `header()` or `exit()`. Remove in v3.

### Removed

- Unauthenticated `GET /stream/{filename}`
- `/streamer` hello route
- FFmpeg / HLS / metadata / Streamable trait (not in v2.0)

### Security

- Query `?file=` instead of `/{filename}`
- Realpath prefix jail against path traversal
- Client MIME is ignored
- Error responses never include absolute server paths
