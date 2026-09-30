<?php

declare(strict_types=1);

namespace Raju\Streamer\Streaming;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Route;
use Raju\Streamer\Contracts\Authorization;
use Raju\Streamer\Contracts\Probe;
use Raju\Streamer\Contracts\StorageResolver;
use Raju\Streamer\Contracts\Streamer;
use Raju\Streamer\Drm\DrmConfiguration;
use Raju\Streamer\Drm\DrmResolver;
use Raju\Streamer\Drm\PlaybackTicketManager;
use Raju\Streamer\Drm\PlaybackUrlGenerator;
use Raju\Streamer\Events\VideoStreamCompleted;
use Raju\Streamer\Events\VideoStreamFailed;
use Raju\Streamer\Events\VideoStreamStarted;
use Raju\Streamer\Events\VideoStreamUnauthorized;
use Raju\Streamer\Exceptions\InvalidRange;
use Raju\Streamer\Exceptions\StreamException;
use Raju\Streamer\Exceptions\UnauthorizedStream;
use Raju\Streamer\Exceptions\VideoNotFound;
use Raju\Streamer\Http\Responses\VideoStreamResponse;
use Raju\Streamer\Metadata\VideoMeta;
use Raju\Streamer\Playlist\DashManifestRewriter;
use Raju\Streamer\Playlist\HlsPlaylistRewriter;
use Raju\Streamer\Storage\ResolvedVideo;
use Raju\Streamer\Support\CallableAuthorization;
use Symfony\Component\HttpFoundation\Response;

final class VideoStreamer implements Streamer
{
    public function __construct(
        private readonly StorageResolver $storage,
        private readonly RangeParser $ranges,
        private readonly VideoStreamResponse $responses,
        private readonly Dispatcher $events,
        private readonly UrlGenerator $urls,
        private readonly Authorization $defaultAuthorization,
        private readonly Application $app,
        private readonly HlsPlaylistRewriter $hlsRewriter,
        private readonly DashManifestRewriter $dashRewriter,
        private readonly DrmResolver $drmResolver,
        private readonly PlaybackTicketManager $playbackTickets,
        private readonly PlaybackUrlGenerator $playbackUrls,
    ) {}

    public function disk(?string $disk = null): PendingStream
    {
        $pending = new PendingStream($this);

        if ($disk !== null) {
            $pending->disk($disk);
        }

        return $pending;
    }

    public function file(string $path): PendingStream
    {
        return (new PendingStream($this))->file($path);
    }

    public function signedUrl(
        string $path,
        DateTimeInterface|int|null $expires = null,
        ?string $disk = null,
    ): string {
        if (! $this->signedUrlsEnabled()) {
            throw new StreamException('Signed stream URLs are disabled.');
        }

        $name = $this->stringConfig('larastreamer.route.name', 'larastreamer.stream');

        if (! Route::has($name)) {
            throw new StreamException('Signed stream routes are disabled.');
        }

        $parameters = ['file' => $path];

        if (is_string($disk) && $disk !== '') {
            $parameters['disk'] = $disk;
        }

        return $this->urls->temporarySignedRoute(
            $name,
            $this->expiration($expires),
            $parameters,
        );
    }

    public function authorize(callable|Authorization $callback): static
    {
        $this->context()->setAuthorization(
            $callback instanceof Authorization
                ? $callback
                : new CallableAuthorization($callback),
        );

        return $this;
    }

    public function stream(PendingStream $pending): Response
    {
        return $this->deliver($pending, attachment: false);
    }

    public function download(PendingStream $pending): Response
    {
        return $this->deliver($pending, attachment: true);
    }

    public function redirect(PendingStream $pending, DateTimeInterface|int|null $expires = null): Response
    {
        try {
            $video = $this->prepare($pending);
            $url = $this->storage->temporaryUrl($video->disk, $video->path, $this->expiration($expires));

            $this->fireStarted($video, null, DeliveryStrategy::Redirect);
            $this->fireCompleted($video, null, DeliveryStrategy::Redirect);

            return redirect()->away($url);
        } catch (UnauthorizedStream $exception) {
            return $this->unauthorized($pending, $exception);
        } catch (StreamException $exception) {
            return $this->failed($pending, $exception);
        }
    }

    public function temporaryUrl(PendingStream $pending, DateTimeInterface|int|null $expires = null): string
    {
        $video = $this->prepare($pending);

        return $this->storage->temporaryUrl($video->disk, $video->path, $this->expiration($expires));
    }

    public function meta(PendingStream $pending): VideoMeta
    {
        $video = $this->prepare($pending);
        $duration = null;
        $codec = null;

        if ($this->app->bound(Probe::class)) {
            $probed = $this->app->make(Probe::class)->inspect($video);
            $duration = isset($probed['duration']) && is_numeric($probed['duration']) ? (float) $probed['duration'] : null;
            $codec = isset($probed['codec']) && is_string($probed['codec']) ? $probed['codec'] : null;
        }

        return new VideoMeta(
            size: $video->size,
            mime: $video->mime,
            lastModified: $video->lastModified,
            etag: $video->etag,
            duration: $duration,
            codec: $codec,
        );
    }

    /**
     * @return array{url: string, type: string, mime: string, expires_at: string|null, kind: string, captions: list<array{src: string, srclang?: string, label?: string, default?: bool}>, drm?: array<string, mixed>}
     */
    public function embedData(PendingStream $pending, DateTimeInterface|int|null $expires = null): array
    {
        $video = $this->prepare($pending);
        $expiration = $this->expiration($expires);
        $drm = $pending->drmSource() !== null
            ? $this->drmResolver->resolve($pending->drmSource(), $video, $this->request())
            : null;

        $url = $drm?->manifestUrl();

        if ($drm instanceof DrmConfiguration && $url === null && $video->isLocal) {
            $ticket = $this->playbackTickets->issue(
                $video->disk,
                $video->path,
                $expiration,
                $this->userId(),
            );
            $pending->playbackTicket($ticket);
            $url = $this->playbackUrls->url($video->path, $ticket);
        }

        $data = [
            'url' => $url ?? $this->publicUrl($video, $pending, $expiration),
            'type' => 'video',
            'mime' => $video->mime,
            'expires_at' => $expiration->format(DATE_ATOM),
            'kind' => StreamKind::fromPath($video->path)->value,
            'captions' => $this->embedCaptions($pending, $expiration),
        ];

        if ($drm instanceof DrmConfiguration) {
            $data['drm'] = $drm->toArray();
        }

        return $data;
    }

    private function deliver(PendingStream $pending, bool $attachment): Response
    {
        try {
            $video = $this->prepare($pending);
            $options = StreamOptions::fromConfig()->withDisposition($attachment ? 'attachment' : 'inline');
            $validators = $this->responses->validatorHeaders($video->etag, $video->lastModified);

            $disposition = $attachment ? 'attachment; filename="'.basename($video->path).'"' : null;

            if (! $video->isLocal && $this->remoteStrategy() === DeliveryStrategy::Redirect) {
                if ($attachment) {
                    return $this->redirectDownload($pending, $video, 'attachment; filename="'.basename($video->path).'"');
                }

                return $this->redirect($pending);
            }

            if ($this->shouldRewritePlaylist($video) && ! $attachment) {
                return $this->playlist($pending, $video);
            }

            $range = $this->parseRange($video->size);
            $range = $this->applyIfRange($range, $video);

            if ($range === null && $this->noneMatch($video)) {
                $this->fireStarted($video, null, $this->strategy($video));
                $this->fireCompleted($video, null, $this->strategy($video));

                return $this->responses->notModified($options, $validators);
            }

            $strategy = $this->strategy($video);
            $extra = $disposition !== null ? ['Content-Disposition' => $disposition, ...$validators] : $validators;

            $this->fireStarted($video, $range, $strategy);

            if ($this->request()->isMethod('HEAD') && $strategy !== DeliveryStrategy::Offload) {
                $response = $this->responses->head($video->mime, $options, $range, $video->size, $extra);
                $this->fireCompleted($video, $range, $strategy);

                return $response;
            }

            if ($strategy === DeliveryStrategy::Offload && is_string($video->localPath)) {
                $response = $this->offload($video, $options, $extra, $range);
                $this->fireCompleted($video, $range, $strategy);

                return $response;
            }

            if ($video->isLocal && is_string($video->localPath)) {
                if ($range === null) {
                    $this->request()->headers->remove('Range');
                }

                $response = $this->responses->file($video->localPath, $video->mime, $options, $extra);
                $this->completeAfterSend($video, $range, DeliveryStrategy::File);

                return $response;
            }

            $stream = $this->storage->readStream($video->disk, $video->path);

            return $this->responses->stream(
                $stream,
                $video->size,
                $video->mime,
                $options,
                $range,
                $this->request()->isMethod('HEAD'),
                fn () => $this->fireCompleted($video, $range, DeliveryStrategy::Proxy),
                $extra,
            );
        } catch (InvalidRange $exception) {
            return $exception->toResponse();
        } catch (UnauthorizedStream $exception) {
            return $this->unauthorized($pending, $exception);
        } catch (StreamException $exception) {
            return $this->failed($pending, $exception);
        }
    }

    private function playlist(PendingStream $pending, ResolvedVideo $video): Response
    {
        $contents = $this->readContents($video);
        $kind = StreamKind::fromPath($video->path);
        $options = StreamOptions::fromConfig()->withPlaylistCaching();
        $validators = $this->responses->validatorHeaders($video->etag, $video->lastModified);

        $rewritten = match ($kind) {
            StreamKind::Dash => $this->dashRewriter->rewrite(
                $contents,
                $video->path,
                fn (string $path): string => $this->segmentUrl($pending, $video, $path),
            ),
            default => $this->hlsRewriter->rewrite(
                $contents,
                $video->path,
                fn (string $path): string => $this->segmentUrl($pending, $video, $path),
            ),
        };

        $this->fireStarted($video, null, DeliveryStrategy::Playlist);
        $this->fireCompleted($video, null, DeliveryStrategy::Playlist);

        return $this->responses->playlist(
            $rewritten,
            $video->mime,
            $options,
            $this->request()->isMethod('HEAD'),
            $validators,
        );
    }

    private function segmentUrl(PendingStream $pending, ResolvedVideo $playlist, string $path): string
    {
        if ($pending->playbackTicketValue() !== null) {
            return $this->playbackUrls->url($path, $pending->playbackTicketValue());
        }

        if ($playlist->isLocal) {
            return $this->signedUrl($path, disk: $playlist->disk);
        }

        return $this->storage->temporaryUrl($playlist->disk, $path, $this->expiration(null));
    }

    private function readContents(ResolvedVideo $video): string
    {
        if (is_string($video->localPath)) {
            $contents = file_get_contents($video->localPath);

            if (! is_string($contents) || $contents === '') {
                throw new VideoNotFound;
            }

            return $contents;
        }

        return $this->storage->read($video->disk, $video->path);
    }

    private function redirectDownload(PendingStream $pending, ResolvedVideo $video, string $disposition): Response
    {
        $url = $this->storage->temporaryUrl(
            $video->disk,
            $video->path,
            $this->expiration(null),
            ['ResponseContentDisposition' => $disposition],
        );

        $this->fireStarted($video, null, DeliveryStrategy::Redirect);
        $this->fireCompleted($video, null, DeliveryStrategy::Redirect);

        return redirect()->away($url);
    }

    /**
     * @return list<array{src: string, srclang?: string, label?: string, default?: bool}>
     */
    private function embedCaptions(PendingStream $pending, DateTimeInterface $expiration): array
    {
        $tracks = [];

        foreach ($pending->captionTracks() as $caption) {
            $src = $caption['src'];

            if ($src === '') {
                continue;
            }

            $captionPending = new PendingStream($this);

            if ($pending->diskName() !== null) {
                $captionPending->disk($pending->diskName());
            }

            $captionPending->file($src);

            try {
                $resolved = $this->prepare($captionPending);
                $caption['src'] = $this->publicUrl($resolved, $captionPending, $expiration);
                $tracks[] = $caption;
            } catch (StreamException) {
                continue;
            }
        }

        return $tracks;
    }

    private function publicUrl(ResolvedVideo $video, PendingStream $pending, DateTimeInterface $expiration): string
    {
        if (! $video->isLocal) {
            return $this->storage->temporaryUrl($video->disk, $video->path, $expiration);
        }

        if (! $this->signedUrlsEnabled()) {
            throw new StreamException('Unable to build an embed URL. Enable signed stream routes or pass a public url.');
        }

        try {
            return $this->signedUrl($this->embedPath($pending), $expiration, $pending->diskName());
        } catch (StreamException $exception) {
            throw new StreamException('Unable to build an embed URL. Enable signed stream routes or pass a public url.', previous: $exception);
        }
    }

    private function prepare(PendingStream $pending): ResolvedVideo
    {
        $path = $pending->path();

        if ($path === null || $path === '') {
            throw new VideoNotFound;
        }

        $disk = $pending->diskName() ?? $this->defaultDisk();
        $absolute = $this->isAbsoluteLocalPath($path);
        $resolvedPath = $absolute ? $path : $this->prefixedPath($pending, $path);

        $this->guard($pending, $resolvedPath);

        return $this->storage->resolve($disk, $resolvedPath, $absolute);
    }

    private function guard(PendingStream $pending, string $path): void
    {
        $authorization = $pending->authorizer()
            ?? $this->context()->authorization()
            ?? $this->defaultAuthorization;

        $user = $this->request()->user();

        if (! $authorization->authorize($path, $user)) {
            throw new UnauthorizedStream;
        }
    }

    private function prefixedPath(PendingStream $pending, string $path): string
    {
        if (! $pending->usesDefaultPrefix()) {
            return $path;
        }

        $prefix = trim($this->stringConfig('larastreamer.storage.path', ''), '/');

        if ($prefix === '') {
            return $path;
        }

        $normalized = ltrim(str_replace('\\', '/', $path), '/');

        if ($normalized === $prefix || str_starts_with($normalized, $prefix.'/')) {
            return $normalized;
        }

        return $prefix.'/'.$normalized;
    }

    private function parseRange(int $size): ?Range
    {
        $header = $this->rangeHeader();

        if ($header === null) {
            return null;
        }

        return $this->ranges->parse($header, $size);
    }

    private function rangeHeader(): ?string
    {
        $header = $this->request()->headers->get('Range');

        return is_string($header) && $header !== '' ? $header : null;
    }

    private function applyIfRange(?Range $range, ResolvedVideo $video): ?Range
    {
        if (! $range instanceof Range) {
            return null;
        }

        $ifRange = $this->request()->headers->get('If-Range');

        if (! is_string($ifRange) || $ifRange === '') {
            return $range;
        }

        return $this->ifRangeMatches($ifRange, $video) ? $range : null;
    }

    private function noneMatch(ResolvedVideo $video): bool
    {
        $header = $this->request()->headers->get('If-None-Match');

        if (! is_string($header) || $header === '' || $this->rangeHeader() !== null) {
            return false;
        }

        return $this->etagMatches($header, $video->etag);
    }

    private function ifRangeMatches(string $ifRange, ResolvedVideo $video): bool
    {
        $ifRange = trim($ifRange);

        if ($this->looksLikeHttpDate($ifRange)) {
            if ($video->lastModified === null) {
                return false;
            }

            $time = strtotime($ifRange);

            return $time !== false && $video->lastModified <= $time;
        }

        return $this->etagMatches($ifRange, $video->etag);
    }

    private function etagMatches(string $header, string $etag): bool
    {
        if (trim($header) === '*') {
            return true;
        }

        $normalized = $this->normalizeEtag($etag);

        foreach (explode(',', $header) as $candidate) {
            if ($this->normalizeEtag(trim($candidate)) === $normalized) {
                return true;
            }
        }

        return false;
    }

    private function normalizeEtag(string $etag): string
    {
        if (str_starts_with($etag, 'W/')) {
            $etag = substr($etag, 2);
        }

        return trim($etag, " \t\"");
    }

    private function looksLikeHttpDate(string $value): bool
    {
        return str_contains($value, 'GMT') || str_contains($value, ',') || strtotime($value) !== false && ! str_contains($value, '"');
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function offload(ResolvedVideo $video, StreamOptions $options, array $extra, ?Range $range): Response
    {
        $driver = OffloadDriver::tryFrom($this->stringConfig('larastreamer.offload.driver', 'nginx')) ?? OffloadDriver::Nginx;

        if ($driver === OffloadDriver::Nginx) {
            $prefix = rtrim($this->stringConfig('larastreamer.offload.prefix', '/internal-videos/'), '/');
            $value = $prefix.'/'.ltrim($video->path, '/');
            $extra['Accept-Ranges'] = 'bytes';

            if ($range instanceof Range) {
                $extra['X-Accel-Buffering'] = 'no';
            }
        } else {
            $value = $video->localPath ?? $video->path;
        }

        return $this->responses->offload($driver->headerName(), $value, $video->mime, $options, $extra);
    }

    private function strategy(ResolvedVideo $video): DeliveryStrategy
    {
        if ($video->isLocal && (bool) config('larastreamer.offload.enabled', false)) {
            return DeliveryStrategy::Offload;
        }

        return $video->isLocal ? DeliveryStrategy::File : DeliveryStrategy::Proxy;
    }

    private function shouldRewritePlaylist(ResolvedVideo $video): bool
    {
        $kind = StreamKind::fromPath($video->path);

        return match ($kind) {
            StreamKind::Hls => (bool) config('larastreamer.hls.enabled', false) && (bool) config('larastreamer.hls.rewrite', true),
            StreamKind::Dash => (bool) config('larastreamer.dash.enabled', false) && (bool) config('larastreamer.dash.rewrite', true),
            default => false,
        };
    }

    private function remoteStrategy(): DeliveryStrategy
    {
        $strategy = $this->stringConfig('larastreamer.storage.remote.strategy', 'redirect');

        return $strategy === 'proxy' ? DeliveryStrategy::Proxy : DeliveryStrategy::Redirect;
    }

    private function defaultDisk(): string
    {
        return $this->stringConfig('larastreamer.storage.disk', 'local');
    }

    private function isAbsoluteLocalPath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return is_file($path);
        }

        return str_starts_with($path, '/') && is_file($path);
    }

    private function expiration(DateTimeInterface|int|null $expires): DateTimeInterface
    {
        if ($expires instanceof DateTimeInterface) {
            return $expires;
        }

        if (is_int($expires)) {
            return (new DateTimeImmutable)->add(new DateInterval('PT'.$expires.'S'));
        }

        $seconds = max(1, $this->intConfig('larastreamer.security.default_expiration', 1800));

        return (new DateTimeImmutable)->add(new DateInterval('PT'.$seconds.'S'));
    }

    private function embedPath(PendingStream $pending): string
    {
        $path = $pending->path() ?? '';

        if ($this->isAbsoluteLocalPath($path)) {
            return basename($path);
        }

        return ltrim($path, '/');
    }

    private function signedUrlsEnabled(): bool
    {
        return $this->boolConfig('larastreamer.security.signed_urls', true);
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }

    private function boolConfig(string $key, bool $default): bool
    {
        $value = config($key, $default);

        return is_bool($value) ? $value : $default;
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private function context(): StreamContext
    {
        return $this->app->make(StreamContext::class);
    }

    private function completeAfterSend(ResolvedVideo $video, ?Range $range, DeliveryStrategy $strategy): void
    {
        $this->app->terminating(function () use ($video, $range, $strategy): void {
            $this->fireCompleted($video, $range, $strategy);
        });
    }

    private function userId(): mixed
    {
        $user = $this->request()->user();

        return $user instanceof Authenticatable ? $user->getAuthIdentifier() : null;
    }

    private function request(): Request
    {
        return request();
    }

    private function fireStarted(ResolvedVideo $video, ?Range $range, DeliveryStrategy $strategy): void
    {
        $this->events->dispatch(new VideoStreamStarted(
            $video->path,
            $video->disk,
            $this->userId(),
            $range?->header(),
            $strategy->value,
        ));
    }

    private function fireCompleted(ResolvedVideo $video, ?Range $range, DeliveryStrategy $strategy): void
    {
        $this->events->dispatch(new VideoStreamCompleted(
            $video->path,
            $video->disk,
            $this->userId(),
            $range?->header(),
            $strategy->value,
        ));
    }

    private function unauthorized(PendingStream $pending, UnauthorizedStream $exception): Response
    {
        $this->events->dispatch(new VideoStreamUnauthorized(
            $pending->path() ?? '',
            $pending->diskName() ?? $this->defaultDisk(),
            $this->userId(),
            $this->rangeHeader(),
            DeliveryStrategy::File->value,
        ));

        return $exception->toResponse();
    }

    private function failed(PendingStream $pending, StreamException $exception): Response
    {
        $this->events->dispatch(new VideoStreamFailed(
            $pending->path() ?? '',
            $pending->diskName() ?? $this->defaultDisk(),
            $this->userId(),
            $this->rangeHeader(),
            DeliveryStrategy::File->value,
            $exception->getMessage(),
        ));

        return $exception->toResponse();
    }
}
