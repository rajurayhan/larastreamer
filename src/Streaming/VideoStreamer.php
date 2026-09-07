<?php

declare(strict_types=1);

namespace Raju\Streamer\Streaming;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Route;
use Raju\Streamer\Contracts\Authorization;
use Raju\Streamer\Contracts\StorageResolver;
use Raju\Streamer\Contracts\Streamer;
use Raju\Streamer\Events\VideoStreamCompleted;
use Raju\Streamer\Events\VideoStreamFailed;
use Raju\Streamer\Events\VideoStreamStarted;
use Raju\Streamer\Events\VideoStreamUnauthorized;
use Raju\Streamer\Exceptions\InvalidRange;
use Raju\Streamer\Exceptions\StreamException;
use Raju\Streamer\Exceptions\UnauthorizedStream;
use Raju\Streamer\Exceptions\VideoNotFound;
use Raju\Streamer\Http\Responses\VideoStreamResponse;
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
        private Authorization $authorization,
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

    public function signedUrl(string $path, DateTimeInterface|int|null $expires = null): string
    {
        $name = (string) config('larastreamer.route.name', 'larastreamer.stream');

        if (! Route::has($name)) {
            throw new StreamException('Signed stream routes are disabled.');
        }

        return $this->urls->temporarySignedRoute(
            $name,
            $this->expiration($expires),
            ['file' => $path],
        );
    }

    public function authorize(callable|Authorization $callback): static
    {
        $this->authorization = $callback instanceof Authorization
            ? $callback
            : new CallableAuthorization($callback);

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

    public function redirect(PendingStream $pending, ?DateTimeInterface $expires = null): Response
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

    public function temporaryUrl(PendingStream $pending, ?DateTimeInterface $expires = null): string
    {
        $video = $this->prepare($pending);

        return $this->storage->temporaryUrl($video->disk, $video->path, $this->expiration($expires));
    }

    /**
     * @return array{url: string, type: string, mime: string, expires_at: string|null}
     */
    public function embedData(PendingStream $pending, ?DateTimeInterface $expires = null): array
    {
        $video = $this->prepare($pending);
        $expiration = $this->expiration($expires);

        if (! $video->isLocal) {
            $url = $this->storage->temporaryUrl($video->disk, $video->path, $expiration);
        } elseif (Route::has((string) config('larastreamer.route.name', 'larastreamer.stream'))) {
            try {
                $url = $this->signedUrl($this->embedPath($pending), $expiration);
            } catch (UrlGenerationException|StreamException) {
                $url = $video->path;
            }
        } else {
            $url = $video->path;
        }

        return [
            'url' => $url,
            'type' => 'video',
            'mime' => $video->mime,
            'expires_at' => $expiration->format(DATE_ATOM),
        ];
    }

    private function deliver(PendingStream $pending, bool $attachment): Response
    {
        try {
            $video = $this->prepare($pending);
            $options = StreamOptions::fromConfig()->withDisposition($attachment ? 'attachment' : 'inline');

            if ($attachment) {
                $filename = basename($video->path);
                $disposition = 'attachment; filename="'.$filename.'"';
            } else {
                $disposition = null;
            }

            if (! $video->isLocal && $this->remoteStrategy() === DeliveryStrategy::Redirect && ! $attachment) {
                return $this->redirect($pending);
            }

            $range = $this->parseRange($video->size);
            $strategy = $this->strategy($video);
            $extra = $disposition !== null ? ['Content-Disposition' => $disposition] : [];

            $this->fireStarted($video, $range, $strategy);

            if ($this->request()->isMethod('HEAD') && $strategy !== DeliveryStrategy::Offload) {
                $response = $this->responses->head($video->mime, $options, $range, $video->size, $extra);
                $this->fireCompleted($video, $range, $strategy);

                return $response;
            }

            if ($strategy === DeliveryStrategy::Offload && is_string($video->localPath)) {
                $response = $this->offload($video, $options, $extra);
                $this->fireCompleted($video, $range, $strategy);

                return $response;
            }

            if ($video->isLocal && is_string($video->localPath)) {
                $response = $this->responses->file($video->localPath, $video->mime, $options, $extra);
                $this->fireCompleted($video, $range, DeliveryStrategy::File);

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
        $authorization = $pending->authorizer() ?? $this->authorization;

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

        $prefix = trim((string) config('larastreamer.storage.path', ''), '/');

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

    /**
     * @param  array<string, string>  $extra
     */
    private function offload(ResolvedVideo $video, StreamOptions $options, array $extra): Response
    {
        $driver = OffloadDriver::tryFrom((string) config('larastreamer.offload.driver', 'nginx')) ?? OffloadDriver::Nginx;

        if ($driver === OffloadDriver::Nginx) {
            $prefix = rtrim((string) config('larastreamer.offload.prefix', '/internal-videos/'), '/');
            $value = $prefix.'/'.ltrim($video->path, '/');
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

    private function remoteStrategy(): DeliveryStrategy
    {
        $strategy = (string) config('larastreamer.storage.remote.strategy', 'redirect');

        return $strategy === 'proxy' ? DeliveryStrategy::Proxy : DeliveryStrategy::Redirect;
    }

    private function defaultDisk(): string
    {
        return (string) config('larastreamer.storage.disk', 'local');
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

        $seconds = max(1, (int) config('larastreamer.security.default_expiration', 1800));

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
