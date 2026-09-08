# Larastreamer

A lightweight, storage-agnostic video streaming engine for Laravel — HTTP Range, signed URLs, authorization, and cloud disks. **Not a transcoding platform.**

Requires **PHP 8.3+** and **Laravel 12 or 13**.

**Status:** 3.0 on `feature/mordenize`. Delivery only: Range, disks, signed URLs, Streamable, metadata, captions, and opt-in HLS/DASH *serving*. Not Mux. Not a transcoder. See [`docs/v3.md`](docs/v3.md).

The v1 `Raju\Streamer\Helpers\VideoStream` class was **removed in 3.0**. Use `Streamer::file($path)->stream()`.

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
    ->captions([
        ['src' => 'courses/lesson-01.en.vtt', 'srclang' => 'en', 'label' => 'English', 'default' => true],
    ])
    ->stream();

$meta = Streamer::disk('videos')->file('courses/lesson-01.mp4')->meta();
// size, mime, last_modified, etag — duration/codec only if an optional Probe is bound

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
    ->embedData(); // url, type, mime, expires_at, kind, captions
```

`Streamer::file($path)` uses the default disk and prefixes `storage.path` (`uploads` by default). `Streamer::disk('videos')->file($path)` uses that path on the given disk as-is.

## Streamable

No migrations. The model already has a path column.

```php
use Raju\Streamer\Concerns\Streamable;

class Lesson extends Model
{
    use Streamable;

    // defaults: `path` column, config default disk
    public function streamDisk(): string
    {
        return $this->disk ?? 'videos';
    }

    public function streamPath(): string
    {
        return $this->path;
    }

    public function streamCaptions(): array
    {
        return $this->captions ?? [];
    }
}

return $lesson->stream();
return $lesson->download();
$lesson->streamUrl(now()->addMinutes(30));
$lesson->embedData();
```

## Disks

Any Laravel filesystem disk works. Local disks are streamed (or offloaded). S3, R2, Spaces, MinIO, and Wasabi go through Flysystem — no custom SDKs.

Remote objects **redirect** by default (`storage.remote.strategy = redirect`), including `download()`. Do not proxy gigabytes through PHP unless you set the strategy to `proxy`.

```php
return Streamer::disk('s3')->file($path)->redirect();
return Streamer::disk('s3')->file($path)->temporaryUrl(now()->addMinutes(30));
return Streamer::disk('s3')->file($path)->download(); // 302 + Content-Disposition on the signed URL
```

## Signed URLs

Built-in routes are **off by default**. When you enable them, Laravel's `signed` middleware is required. `security.signed_urls` is enforced: if it is `false`, `signedUrl()` throws and the built-in route 404s.

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

`embedData()` returns a signed URL when routes are on. It never returns a raw disk path. If routes are off, pass `url:` to the player or catch `StreamException`.

## Authorization

Default: allow (the app already decided to call `stream()`, or the signed route already validated the signature).

Bind a process-wide policy in a service provider:

```php
$this->app->bind(\Raju\Streamer\Contracts\Authorization::class, LessonPolicyAuthorization::class);
```

`Streamer::authorize()` is **request-scoped** and does not leak to the next HTTP request:

```php
Streamer::authorize(fn (string $path, $user) => $user?->can('viewVideo', $path));

return Streamer::disk('videos')
    ->file($path)
    ->authorize(fn (string $path, $user) => $user?->can('viewVideo', $path))
    ->stream();
```

Denied requests return **403** and dispatch `VideoStreamUnauthorized`.

## Range, HEAD, and validators

| Request | Status | Headers |
| --- | --- | --- |
| Full GET | 200 | `Content-Type`, `Content-Length`, `Accept-Ranges: bytes`, `ETag`, `Last-Modified` |
| Valid Range | 206 | `Content-Length` of the range, `Content-Range: bytes {start}-{end}/{size}` |
| `If-None-Match` matches, no Range | 304 | Empty body, `ETag` / `Last-Modified` |
| `If-Range` mismatch | 200 | Full body, Range ignored |
| Invalid Range | 416 | `Content-Range: bytes */{size}` |
| HEAD | same headers as GET | **no body** |

Supported ranges: `bytes=0-999`, `bytes=1000-`, `bytes=-500`.

## Captions

`.vtt` and `.srt` are served through the same jail and signed route. The player renders `<track kind="subtitles">`.

```php
return Streamer::disk('videos')
    ->file('lesson.mp4')
    ->captions([
        ['src' => 'lesson.en.vtt', 'srclang' => 'en', 'label' => 'English', 'default' => true],
    ])
    ->stream();
```

## HLS / DASH serving (opt-in)

Progressive MP4 is the default. HLS/DASH **serving** is off until you enable it. Transcoding is **not** in 3.0 — no FFmpeg.

```php
'hls' => [
    'enabled' => false,
    'rewrite' => true,
    'player' => 'native', // native | hlsjs
    'hlsjs_src' => 'https://cdn.jsdelivr.net/npm/hls.js@1/dist/hls.min.js',
],
'dash' => [
    'enabled' => false,
    'rewrite' => true,
],
```

When enabled, `stream()` on a `.m3u8` / `.mpd` rewrites **relative** segment (and `#EXT-X-KEY` / `#EXT-X-MAP` / `#EXT-X-MEDIA`) URIs:

- local disk → signed `?file=` URL
- remote disk → `temporaryUrl()`

Absolute `https://` URIs are left alone. `../` inside a playlist is **404**. Playlists are `Cache-Control: private, max-age=0, no-store`. Segments are not fetched during rewrite.

```php
return Streamer::disk('videos')->file('courses/lesson-01.m3u8')->stream();
```

## Download

```php
return Streamer::disk('videos')->file($path)->download();
```

Local files send `Content-Disposition: attachment`. Remote disks 302 to a temporary URL with `ResponseContentDisposition` — they do not stream the object through PHP.

## Offload (local only)

```php
'offload' => [
    'enabled' => true,
    'driver' => 'nginx', // nginx | apache
    'prefix' => '/internal-videos/',
],
```

Larastreamer returns an empty body plus `X-Accel-Redirect` or `X-Sendfile`. **nginx** honors the original request `Range` and serves 206; PHP does not consume the file. **Apache `X-Sendfile` does not reliably forward Range** — disable offload or use nginx if clients need byte ranges.

Example nginx location:

```nginx
location /internal-videos/ {
    internal;
    alias /var/www/storage/app/private/uploads/;
}
```

## Player

```blade
<x-larastreamer::player src="clip.mp4" />
<x-larastreamer::player src="lesson.m3u8" />
<x-larastreamer::player url="https://cdn.example.test/clip.mp4" mime="video/mp4" poster="thumb.jpg" />
<x-larastreamer::player src="clip.mp4" :captions="[['src' => 'en.vtt', 'srclang' => 'en', 'label' => 'English']]" />
```

Native `<video>` by default. No JavaScript is vendored. If `hls.player = hlsjs` and the source is a playlist, the component loads HLS.js from `hls.hlsjs_src` (CDN string).

If there is no resolvable public URL, the tag is an empty `<video data-empty="true">` with no `<source>` — never a disk path.

## Events

| Event | When |
| --- | --- |
| `VideoStreamStarted` | Delivery begins |
| `VideoStreamCompleted` | After the body is sent (local `BinaryFileResponse` uses a terminating callback) |
| `VideoStreamFailed` | Missing file, bad MIME, or other stream error |
| `VideoStreamUnauthorized` | `authorize()` returned false |

Payloads are small: path, disk, user id, range, strategy (`file` / `offload` / `proxy` / `redirect` / `playlist`).

## Security

- Paths are resolved through the disk. Local files are `realpath`'d and must stay under the disk root.
- Traversal (`../`, encoded) is rejected with **404**, including URIs inside rewritten playlists.
- MIME and extension allowlists apply. HLS types are **not** in the default list.
- Missing, empty, directory, and disallowed files are **404**. Responses never include absolute server paths.
- Default cache for private/signed content: `Cache-Control: private, max-age={n}`. Public cache is opt-in.
- `security.signed_urls` must stay `true` to use the built-in route.

## Configuration

See `config/larastreamer.php` after publishing. Important defaults:

- `route.enabled` = `false`
- `security.signed_urls` = `true`
- `storage.disk` = `local`
- `storage.path` = `uploads`
- `storage.remote.strategy` = `redirect`
- `hls.enabled` / `dash.enabled` = `false`
- `captions.enabled` = `true`
- `streaming.cache` = `private`

## Migrating from v2 / v1

| Old | 3.0 |
| --- | --- |
| `Raju\Streamer\Helpers\VideoStream` | **Removed.** `Streamer::file($path)->stream()` |
| `Streamer::authorize()` mutates a singleton | Request-scoped `StreamContext` |
| `embedData()['url']` as a disk path | Signed URL, or `StreamException` |
| Unused `security.signed_urls` | Enforced |
| HLS types in a custom allowlist | Enable `hls.enabled` / `dash.enabled` |

## Production notes

- Prefer `redirect()` / `temporaryUrl()` for S3-compatible disks. `download()` on remote disks follows the same rule.
- Enable offload behind nginx for large local files if you need Range; Apache `X-Sendfile` is full-file only.
- Keep `route.enabled` false unless you need the built-in signed endpoint.
- Do not expose a public cache for private lessons.

## Testing

```bash
composer test
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
```

## License

MIT
