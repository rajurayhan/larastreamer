# Larastreamer

A lightweight, storage-agnostic video streaming engine for Laravel — HTTP Range, signed URLs, authorization, and cloud disks.

**Not a transcoding platform.** It does not generate renditions, package HLS/DASH, or talk to Mux / Cloudflare Stream. Delivery only.

Requires **PHP 8.3+** and **Laravel 12 or 13**.

**Status:** 3.0. Progressive MP4 by default. Opt-in HLS/DASH *serving*, captions, metadata, and a `Streamable` model trait. The v1 `Raju\Streamer\Helpers\VideoStream` class was **removed** — use `Streamer::file($path)->stream()`.

See [`docs/v3.md`](docs/v3.md) for the 3.0 design notes.

---

## Contents

- [Installation](#installation)
- [Quick start](#quick-start)
- [Usage](#usage)
  - [Fluent API](#fluent-api)
  - [Controllers and routes](#controllers-and-routes)
  - [Default disk vs named disk](#default-disk-vs-named-disk)
  - [Streamable models](#streamable-models)
  - [Cloud disks](#cloud-disks)
  - [Signed URLs](#signed-urls)
  - [Authorization](#authorization)
  - [Metadata](#metadata)
  - [Captions](#captions)
  - [HLS and DASH serving](#hls-and-dash-serving)
  - [Downloads](#downloads)
  - [Player](#player)
  - [Events](#events)
  - [Exceptions](#exceptions)
- [HTTP Range, HEAD, and validators](#http-range-head-and-validators)
- [Offload](#offload)
- [Security](#security)
- [Configuration](#configuration)
- [Migrating from v2 / v1](#migrating-from-v2--v1)
- [Production notes](#production-notes)
- [Testing](#testing)
- [License](#license)

---

## Installation

```bash
composer require rajurayhan/larastreamer
```

The service provider and `Streamer` facade alias register automatically.

Publish the configuration (optional — values are merged even if you skip this):

```bash
php artisan vendor:publish --tag=larastreamer
```

Publish the player views (optional):

```bash
php artisan vendor:publish --tag=larastreamer-views
```

Add a disk in `config/filesystems.php` if you keep videos off the default `local` disk:

```php
'videos' => [
    'driver' => 'local',
    'root' => storage_path('app/private/videos'),
    'visibility' => 'private',
    'throw' => false,
],

's3' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
],
```

Point Larastreamer at that disk (or leave the defaults and prefix files under `uploads/`):

```env
LARASTREAMER_DISK=videos
LARASTREAMER_PATH=
```

---

## Quick start

```php
use Raju\Streamer\Facades\Streamer;

// In a controller — Range, HEAD, and ETag are handled for you.
return Streamer::disk('videos')->file('courses/lesson-01.mp4')->stream();
```

```blade
{{-- After enabling the signed route — see Signed URLs --}}
<x-larastreamer::player src="courses/lesson-01.mp4" />
```

---

## Usage

### Fluent API

Every delivery call starts from the `Streamer` facade and a `PendingStream` builder.

| Method | Returns | Purpose |
| --- | --- | --- |
| `Streamer::file($path)` | `PendingStream` | Default disk, prefixed with `storage.path` (`uploads` by default) |
| `Streamer::disk($name)` | `PendingStream` | Named Laravel disk; later `file()` paths are used as-is |
| `->file($path)` | `PendingStream` | Relative disk path, or an absolute local file that exists |
| `->captions($tracks)` | `PendingStream` | VTT/SRT tracks for `stream()` / `embedData()` / the player |
| `->authorize($cb)` | `PendingStream` | Per-call authorization (this request only) |
| `->stream()` | `Response` | Stream, redirect, offload, or rewrite a playlist |
| `->download()` | `Response` | Attachment disposition (remote disks 302) |
| `->redirect($expires)` | `Response` | 302 to a disk `temporaryUrl()` |
| `->temporaryUrl($expires)` | `string` | Cloud signed URL |
| `->meta()` | `VideoMeta` | Size, MIME, mtime, ETag; duration/codec if a `Probe` is bound |
| `->embedData($expires)` | `array` | Public URL payload for a player |
| `Streamer::signedUrl($path, $expires)` | `string` | App-signed built-in route URL |
| `Streamer::authorize($cb)` | `Streamer` | Request-scoped default authorizer |

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
// size, mime, last_modified, etag — duration/codec only if a Probe is bound

return Streamer::disk('s3')
    ->file($video->path)
    ->authorize(fn (string $path, $user) => $user?->can('viewVideo', $path))
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
    ->embedData();
// url, type, mime, expires_at, kind, captions
```

`$expires` on `redirect()`, `temporaryUrl()`, `embedData()`, and `signedUrl()` accepts a `DateTimeInterface`, an integer number of seconds, or `null` (uses `security.default_expiration`, 1800 seconds).

### Controllers and routes

Use your own routes. The package route is off by default.

```php
use App\Models\Lesson;
use Illuminate\Support\Facades\Route;
use Raju\Streamer\Facades\Streamer;

Route::get('/lessons/{lesson}/watch', function (Lesson $lesson) {
    return Streamer::disk($lesson->disk)
        ->file($lesson->path)
        ->authorize(fn (string $path, $user) => $user?->can('view', $lesson))
        ->stream();
})->middleware('auth')->name('lessons.watch');

Route::get('/lessons/{lesson}/download', function (Lesson $lesson) {
    return $lesson->download();
})->middleware('auth')->name('lessons.download');
```

```php
namespace App\Http\Controllers;

use App\Models\Lesson;
use Illuminate\Http\Request;
use Raju\Streamer\Facades\Streamer;
use Symfony\Component\HttpFoundation\Response;

final class LessonStreamController
{
    public function __invoke(Request $request, Lesson $lesson): Response
    {
        $this->authorize('view', $lesson);

        return Streamer::disk($lesson->streamDisk())
            ->file($lesson->streamPath())
            ->captions($lesson->streamCaptions())
            ->stream();
    }
}
```

### Default disk vs named disk

`Streamer::file($path)` uses `storage.disk` and prefixes `storage.path` (`uploads` by default), unless `$path` is already under that prefix or is an existing absolute local file.

```php
// config: disk=local, path=uploads
Streamer::file('lesson-01.mp4')->stream();
// → local disk, path uploads/lesson-01.mp4

Streamer::file('uploads/lesson-01.mp4')->stream();
// → local disk, path uploads/lesson-01.mp4 (prefix not doubled)

Streamer::file('/var/www/storage/app/private/uploads/lesson-01.mp4')->stream();
// → absolute local file, still jailed to the disk root
```

`Streamer::disk('videos')->file($path)` uses that path on the given disk **as-is**. No `uploads/` prefix.

```php
Streamer::disk('videos')->file('courses/lesson-01.mp4')->stream();
// → videos disk, path courses/lesson-01.mp4
```

### Streamable models

No migrations. The model already has a path column (and optionally `disk` / `captions`).

```php
use Illuminate\Database\Eloquent\Model;
use Raju\Streamer\Concerns\Streamable;

class Lesson extends Model
{
    use Streamable;

    // Defaults: `path` attribute, config default disk, `captions` attribute if present.

    public function streamDisk(): string
    {
        return $this->disk ?? 'videos';
    }

    public function streamPath(): string
    {
        return $this->path;
    }

    /**
     * @return list<array{src: string, srclang?: string, label?: string, default?: bool}>
     */
    public function streamCaptions(): array
    {
        return $this->captions ?? [];
    }
}
```

```php
return $lesson->stream();
return $lesson->download();

$lesson->streamUrl(now()->addMinutes(30));
$lesson->streamUrl(1800); // seconds

$data = $lesson->embedData();
```

`streamUrl()` and `embedData()` need a resolvable public URL (signed route for local disks, `temporaryUrl()` for remote). They never return a raw disk path.

### Cloud disks

Any Laravel filesystem disk works. Local disks are streamed (or offloaded). S3, R2, Spaces, MinIO, and Wasabi go through Flysystem — no custom SDKs.

Remote objects **redirect** by default (`storage.remote.strategy = redirect`), including `download()`. Do not proxy gigabytes through PHP unless you set the strategy to `proxy`.

```php
return Streamer::disk('s3')->file($path)->stream();     // 302 to temporaryUrl()
return Streamer::disk('s3')->file($path)->redirect();
return Streamer::disk('s3')->file($path)->temporaryUrl(now()->addMinutes(30));
return Streamer::disk('s3')->file($path)->download();   // 302 + Content-Disposition on the signed URL
```

To proxy a private bucket through PHP (small files, or when every byte must run authorize/events):

```php
// config/larastreamer.php
'storage' => [
    'remote' => [
        'strategy' => 'proxy',
    ],
],
```

### Signed URLs

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

The route is `GET /stream?file=courses/lesson-01.mp4` (not `/{filename}`). Nested paths work because the file is a query parameter.

```php
use Raju\Streamer\Facades\Streamer;

$url = Streamer::signedUrl('courses/lesson-01.mp4', expires: now()->addMinutes(30));

// Blade
<video controls src="{{ Streamer::signedUrl($lesson->path) }}"></video>
```

Unsigned, expired, and tampered signatures are rejected by Laravel (`403`). Missing `file` or `security.signed_urls = false` is `404`.

`embedData()` returns a signed URL when routes are on. If routes are off, pass `url:` to the player or catch `StreamException`.

### Authorization

Default: allow (the app already decided to call `stream()`, or the signed route already validated the signature).

Bind a process-wide policy in a service provider:

```php
use Raju\Streamer\Contracts\Authorization;

final class LessonPolicyAuthorization implements Authorization
{
    public function authorize(string $path, mixed $user): bool
    {
        return $user?->can('viewVideo', $path) === true;
    }
}

// AppServiceProvider::register()
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

Denied requests return **403** and dispatch `VideoStreamUnauthorized`. The per-call hook wins over the request-scoped hook, which wins over the container binding.

### Metadata

```php
$meta = Streamer::disk('videos')->file('courses/lesson-01.mp4')->meta();

$meta->size;          // int
$meta->mime;          // video/mp4
$meta->lastModified;  // unix timestamp or null
$meta->etag;          // W/"size-mtime-or-hash"
$meta->duration;      // float|null
$meta->codec;         // string|null

$meta->toArray();
$meta['size'];        // ArrayAccess, read-only
```

Duration and codec are filled only when you bind an optional `Probe`. There is no FFprobe dependency.

```php
use Raju\Streamer\Contracts\Probe;
use Raju\Streamer\Storage\ResolvedVideo;

final class FfprobeAdapter implements Probe
{
    public function inspect(ResolvedVideo $video): array
    {
        // Run your own probe. Return whatever you have.
        return [
            'duration' => 612.4,
            'codec' => 'avc1',
        ];
    }
}

$this->app->bind(Probe::class, FfprobeAdapter::class);
```

### Captions

`.vtt` and `.srt` are served through the same jail and signed route. The player renders `<track kind="subtitles">`.

```php
return Streamer::disk('videos')
    ->file('lesson.mp4')
    ->captions([
        ['src' => 'lesson.en.vtt', 'srclang' => 'en', 'label' => 'English', 'default' => true],
        ['src' => 'lesson.es.vtt', 'srclang' => 'es', 'label' => 'Español'],
    ])
    ->stream();
```

`embedData()['captions']` rewrites each `src` to a public URL (signed or temporary). Tracks that fail to resolve are omitted.

Disable caption serving with `captions.enabled = false`.

### HLS and DASH serving (opt-in)

Progressive MP4 is the default. HLS/DASH **serving** is off until you enable it. Transcoding is **not** in 3.0 — no FFmpeg.

```php
'hls' => [
    'enabled' => true,
    'rewrite' => true,
    'player' => 'native', // native | hlsjs
    'hlsjs_src' => 'https://cdn.jsdelivr.net/npm/hls.js@1/dist/hls.min.js',
],
'dash' => [
    'enabled' => true,
    'rewrite' => true,
],
```

When enabled, those extensions and MIME types are merged into the allowlist. When disabled, `.m3u8` / `.mpd` are **404**.

| Kind | Extensions | MIME |
| --- | --- | --- |
| HLS playlist | `m3u8` | `application/vnd.apple.mpegurl` (and common aliases) |
| HLS segments | `ts`, `m4s` | `video/mp2t`, `video/iso.segment` |
| DASH | `mpd`, `m4s` | `application/dash+xml` |

`stream()` on a `.m3u8` / `.mpd` rewrites **relative** segment (and `#EXT-X-KEY` / `#EXT-X-MAP` / `#EXT-X-MEDIA`) URIs:

- local disk → signed `?file=` URL
- remote disk → `temporaryUrl()`

Absolute `https://` URIs are left alone. `../` inside a playlist is **404**. Playlists are `Cache-Control: private, max-age=0, no-store`. Segments are not fetched during rewrite.

```php
return Streamer::disk('videos')->file('courses/lesson-01.m3u8')->stream();
```

Safari can play HLS natively. For Chrome/Firefox set `hls.player` to `hlsjs` and use the Blade player — HLS.js is loaded from `hls.hlsjs_src`, not vendored in Composer.

### Downloads

```php
return Streamer::disk('videos')->file($path)->download();
```

Local files send `Content-Disposition: attachment`. Remote disks 302 to a temporary URL with `ResponseContentDisposition` — they do not stream the object through PHP.

### Player

```blade
<x-larastreamer::player src="clip.mp4" />
<x-larastreamer::player src="lesson.m3u8" />
<x-larastreamer::player
    url="https://cdn.example.test/clip.mp4"
    mime="video/mp4"
    poster="thumb.jpg"
    :autoplay="false"
    :controls="true"
/>
<x-larastreamer::player
    src="clip.mp4"
    :captions="[['src' => 'en.vtt', 'srclang' => 'en', 'label' => 'English', 'default' => true]]"
/>
```

| Prop | Type | Notes |
| --- | --- | --- |
| `src` | `string` | Disk-relative path. Resolved through `embedData()` (signed/temporary URL). |
| `url` | `string` | Public URL. Skips disk resolution. Use this when routes are off. |
| `mime` | `string` | `type` on `<source>` |
| `poster` | `string` | Native `poster` |
| `autoplay` | `bool` | Default `false` |
| `controls` | `bool` | Default `true` |
| `captions` | `array` | Override tracks; otherwise uses `embedData()['captions']` |

Extra HTML attributes pass through to `<video>`.

Native `<video>` by default. No JavaScript is vendored. If `hls.player = hlsjs` and the source is a playlist, the component loads HLS.js from `hls.hlsjs_src`.

If there is no resolvable public URL, the tag is an empty `<video data-empty="true">` with no `<source>` — never a disk path.

```php
$data = Streamer::disk('videos')->file($path)->embedData(now()->addHour());

// [
//     'url'        => 'https://…',   // signed or temporary — never a disk path
//     'type'       => 'video',
//     'mime'       => 'video/mp4',
//     'expires_at' => '2026-09-08T12:00:00+00:00',
//     'kind'       => 'progressive', // progressive | hls | dash | caption
//     'captions'   => [ /* public src URLs */ ],
// ]
```

### Events

Listen in your app as usual:

```php
use Raju\Streamer\Events\VideoStreamFailed;
use Raju\Streamer\Events\VideoStreamStarted;

Event::listen(VideoStreamStarted::class, function (VideoStreamStarted $event): void {
    // $event->path, disk, userId, range, strategy
});

Event::listen(VideoStreamFailed::class, function (VideoStreamFailed $event): void {
    report(new RuntimeException($event->reason));
});
```

| Event | When |
| --- | --- |
| `VideoStreamStarted` | Delivery begins |
| `VideoStreamCompleted` | After the body is sent (local `BinaryFileResponse` uses a terminating callback) |
| `VideoStreamFailed` | Missing file, bad MIME, or other stream error |
| `VideoStreamUnauthorized` | `authorize()` returned false |

Payloads are small: path, disk, user id, range, strategy (`file` / `offload` / `proxy` / `redirect` / `playlist`). `VideoStreamFailed` also has a `reason`.

### Exceptions

Responses never include absolute server paths.

| Exception | Status | When |
| --- | --- | --- |
| `VideoNotFound` | 404 | Missing, empty, directory, traversal, or disallowed MIME/extension |
| `UnauthorizedStream` | 403 | Authorizer returned false |
| `InvalidRange` | 416 | Unsatisfiable `Range` (`Content-Range: bytes */{size}`) |
| `StreamException` | 500 | Signed routes disabled, embed URL cannot be built, and other delivery errors |

`signedUrl()` and `embedData()` **throw** `StreamException` when a public URL cannot be built. `stream()` / `download()` / `redirect()` convert package exceptions into HTTP responses.

---

## HTTP Range, HEAD, and validators

| Request | Status | Headers |
| --- | --- | --- |
| Full GET | 200 | `Content-Type`, `Content-Length`, `Accept-Ranges: bytes`, `ETag`, `Last-Modified` |
| Valid Range | 206 | `Content-Length` of the range, `Content-Range: bytes {start}-{end}/{size}` |
| `If-None-Match` matches, no Range | 304 | Empty body, `ETag` / `Last-Modified` |
| `If-Range` mismatch | 200 | Full body, Range ignored |
| Invalid Range | 416 | `Content-Range: bytes */{size}` |
| HEAD | same headers as GET | **no body** |

Supported ranges: `bytes=0-999`, `bytes=1000-`, `bytes=-500`.

ETag is weak: `W/"{size}-{mtime}"` locally, or a path hash when mtime is missing on a remote object.

---

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

The alias must match the disk root (plus `storage.path` if you use the default prefix). Tests assert headers only; nginx must be configured in the environment.

---

## Security

- Paths are resolved through the disk. Local files are `realpath`'d and must stay under the disk root.
- Traversal (`../`, encoded) is rejected with **404**, including URIs inside rewritten playlists.
- MIME and extension allowlists apply. Client `Content-Type` is ignored. HLS types are **not** in the default list.
- Missing, empty, directory, and disallowed files are **404**. Responses never include absolute server paths.
- Default cache for private/signed content: `Cache-Control: private, max-age={n}`. Public cache is opt-in (`streaming.cache = public`).
- `security.signed_urls` must stay `true` to use the built-in route.
- Playlists are never stored in a shared cache (`no-store`).

---

## Configuration

See `config/larastreamer.php` after publishing.

| Key | Default | Notes |
| --- | --- | --- |
| `route.enabled` | `false` | Load `GET /{prefix}?file=` |
| `route.prefix` | `stream` | URL prefix |
| `route.middleware` | `['signed']` | Required when the route is on |
| `route.name` | `larastreamer.stream` | Used by `signedUrl()` |
| `storage.disk` | `env('LARASTREAMER_DISK', 'local')` | Default disk for `Streamer::file()` |
| `storage.path` | `env('LARASTREAMER_PATH', 'uploads')` | Prefix for `Streamer::file()` only |
| `storage.remote.strategy` | `redirect` | `redirect` or `proxy` |
| `streaming.buffer_size` | `1048576` | Proxy read chunk size |
| `streaming.max_age` | `3600` | `Cache-Control` max-age |
| `streaming.cache` | `private` | `private` or `public` |
| `security.signed_urls` | `true` | Enforced for the built-in route |
| `security.default_expiration` | `1800` | Seconds when `$expires` is omitted |
| `allowed_mimes` | progressive video types | Extra HLS/DASH/caption types merge when enabled |
| `allowed_extensions` | `mp4`, `webm`, `ogv`, `mov`, `avi`, `mpeg`, `mpg` | Same merge rules |
| `hls.enabled` / `dash.enabled` | `false` | Opt-in playlist serving |
| `hls.rewrite` / `dash.rewrite` | `true` | Rewrite relative URIs |
| `hls.player` | `native` | `native` or `hlsjs` |
| `hls.hlsjs_src` | jsDelivr HLS.js | CDN string only |
| `captions.enabled` | `true` | Allow `.vtt` / `.srt` |
| `offload.enabled` | `false` | Local sendfile / accel |

---

## Migrating from v2 / v1

| Old | 3.0 |
| --- | --- |
| `Raju\Streamer\Helpers\VideoStream` | **Removed.** `Streamer::file($path)->stream()` |
| `Streamer::authorize()` mutates a singleton | Request-scoped `StreamContext` |
| `embedData()['url']` as a disk path | Signed URL, or `StreamException` |
| Unused `security.signed_urls` | Enforced |
| HLS types in a custom allowlist | Enable `hls.enabled` / `dash.enabled` |

---

## Production notes

- Prefer `redirect()` / `temporaryUrl()` for S3-compatible disks. `download()` on remote disks follows the same rule.
- Enable offload behind nginx for large local files if you need Range; Apache `X-Sendfile` is full-file only.
- Keep `route.enabled` false unless you need the built-in signed endpoint.
- Do not expose a public cache for private lessons.
- Bind `Authorization` in a service provider rather than relying on `Streamer::authorize()` in middleware that might not run on every request.

---

## Testing

```bash
composer test
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
```

---

## License

MIT
