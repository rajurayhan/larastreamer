# Larastreamer

A lightweight, storage-agnostic video streaming engine for Laravel — HTTP Range, signed URLs, authorization, and cloud disks. **Not a transcoding platform.**

Requires **PHP 8.3+** and **Laravel 12 or 13**.

## Installation

```bash
composer require rajurayhan/larastreamer
```

Publish the configuration (optional — values are merged even if you skip this):

```bash
php artisan vendor:publish --tag=larastreamer
```

Publish the player views (optional):

```bash
php artisan vendor:publish --tag=larastreamer-views
```

## Usage

```php
use Raju\Streamer\Facades\Streamer;

return Streamer::file($absoluteOrRelative)->stream();

return Streamer::disk('videos')
    ->file('courses/lesson-01.mp4')
    ->stream();

return Streamer::disk('s3')
    ->file($video->path)
    ->authorize(fn ($path, $user) => $user?->can('viewVideo', $path))
    ->stream();

$url = Streamer::disk('s3')
    ->file($video->path)
    ->temporaryUrl(now()->addMinutes(30));

return Streamer::disk('s3')
    ->file($video->path)
    ->redirect();

return Streamer::disk('videos')
    ->file($path)
    ->download();

$data = Streamer::disk('videos')
    ->file($path)
    ->embedData(); // url, type, mime, expires_at
```

`Streamer::file($path)` uses the default disk and prefixes `storage.path` (`uploads` by default). `Streamer::disk('videos')->file($path)` uses that path on the given disk as-is.

## Disks

Any Laravel filesystem disk works. Local disks are streamed (or offloaded). S3, R2, Spaces, MinIO, and Wasabi go through Flysystem — no custom SDKs.

Remote objects **redirect** by default (`storage.remote.strategy = redirect`). Do not proxy gigabytes through PHP unless you set the strategy to `proxy`.

```php
return Streamer::disk('s3')->file($path)->redirect();
return Streamer::disk('s3')->file($path)->temporaryUrl(now()->addMinutes(30));
```

## Signed URLs

Built-in routes are **off by default**. When you enable them, Laravel's `signed` middleware is required:

```php
// config/larastreamer.php
'route' => [
    'enabled' => true,
    'prefix' => 'stream',
    'middleware' => ['signed'],
    'name' => 'larastreamer.stream',
],
```

The route is `GET /stream?file=courses/lesson-01.mp4` (not `/{filename}`).

```php
Streamer::signedUrl('courses/lesson-01.mp4', expires: now()->addMinutes(30));
```

## Authorization

Default: allow (the app already decided to call `stream()`, or the signed route already validated the signature).

```php
Streamer::authorize(fn (string $path, $user) => $user?->can('viewVideo', $path));

return Streamer::disk('videos')
    ->file($path)
    ->authorize(fn (string $path, $user) => $user?->can('viewVideo', $path))
    ->stream();
```

Denied requests return **403** and dispatch `VideoStreamUnauthorized`.

## Range and HEAD

| Request | Status | Headers |
| --- | --- | --- |
| Full GET | 200 | `Content-Type`, `Content-Length`, `Accept-Ranges: bytes` |
| Valid Range | 206 | `Content-Length` of the range, `Content-Range: bytes {start}-{end}/{size}` |
| Invalid Range | 416 | `Content-Range: bytes */{size}` |
| HEAD | same headers as GET | **no body** |

Supported ranges: `bytes=0-999`, `bytes=1000-`, `bytes=-500`.

## Download

```php
return Streamer::disk('videos')->file($path)->download();
```

Sends `Content-Disposition: attachment`.

## Offload (local only)

```php
'offload' => [
    'enabled' => true,
    'driver' => 'nginx', // nginx | apache
    'prefix' => '/internal-videos/',
],
```

Larastreamer returns an empty body plus `X-Accel-Redirect` or `X-Sendfile`. Example nginx location:

```nginx
location /internal-videos/ {
    internal;
    alias /var/www/storage/app/private/uploads/;
}
```

## Player

```blade
<x-larastreamer::player src="clip.mp4" />
<x-larastreamer::player url="https://cdn.example.test/clip.mp4" mime="video/mp4" poster="thumb.jpg" />
```

Native `<video>` only. No JavaScript is vendored. HLS.js is reserved for a later release.

## Events

| Event | When |
| --- | --- |
| `VideoStreamStarted` | Delivery begins |
| `VideoStreamCompleted` | Response is ready (or the proxy callback finishes) |
| `VideoStreamFailed` | Missing file, bad MIME, or other stream error |
| `VideoStreamUnauthorized` | `authorize()` returned false |

Payloads are small: path, disk, user id, range, strategy (`file` / `offload` / `proxy` / `redirect`).

## Security

- Paths are resolved through the disk. Local files are `realpath`'d and must stay under the disk root.
- Traversal (`../`, encoded) is rejected with **404**.
- MIME and extension allowlists apply. Client `Content-Type` is ignored.
- Missing, empty, directory, and disallowed files are **404**. Responses never include absolute server paths.
- Default cache for private/signed content: `Cache-Control: private, max-age={n}`. Public cache is opt-in.

## Configuration

See `config/larastreamer.php` after publishing. Important defaults:

- `route.enabled` = `false`
- `storage.disk` = `local`
- `storage.path` = `uploads` (v1 `basepath` equivalent)
- `storage.remote.strategy` = `redirect`
- `streaming.buffer_size` = `1_048_576`
- `streaming.cache` = `private`

## Migrating from v1

| v1 | v2 |
| --- | --- |
| `config('larastreamer.basepath')` | `storage.disk` + `storage.path` (`uploads` on `local`) |
| Unpublished config resolved to `/` | Config is always merged |
| `GET /stream/{filename}` always on | Routes off; enable and use `?file=` + `signed` |
| `GET /streamer` hello route | Removed |
| `new VideoStream($path)` + `header()` / `exit()` | `@deprecated` shim that returns a response |

v1 controller pattern:

```php
$stream = new VideoStream($filePath);
return response()->stream(fn () => $stream->start());
```

v2:

```php
return Streamer::file($filePath)->stream();

// still works, but deprecated — start() now returns a Response
return (new \Raju\Streamer\Helpers\VideoStream($filePath))->start();
```

`VideoStream` will be removed in v3.

## Production notes

- Prefer `redirect()` / `temporaryUrl()` for S3-compatible disks.
- Enable offload behind nginx or Apache for large local files.
- Keep `route.enabled` false unless you need the built-in signed endpoint.
- Do not expose a public cache for private lessons.

## Testing

```bash
composer test
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

## License

MIT
