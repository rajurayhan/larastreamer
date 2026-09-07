<?php

declare(strict_types=1);

namespace Raju\Streamer\Streaming;

use DateTimeInterface;
use Raju\Streamer\Contracts\Authorization;
use Raju\Streamer\Support\CallableAuthorization;
use Symfony\Component\HttpFoundation\Response;

final class PendingStream
{
    private ?string $disk = null;

    private ?string $path = null;

    private bool $usesDefaultPrefix = true;

    private ?Authorization $authorizer = null;

    public function __construct(private readonly VideoStreamer $streamer) {}

    public function disk(string $disk): self
    {
        $this->disk = $disk;
        $this->usesDefaultPrefix = false;

        return $this;
    }

    public function file(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function authorize(callable|Authorization $callback): self
    {
        $this->authorizer = $callback instanceof Authorization
            ? $callback
            : new CallableAuthorization($callback);

        return $this;
    }

    public function stream(): Response
    {
        return $this->streamer->stream($this);
    }

    public function download(): Response
    {
        return $this->streamer->download($this);
    }

    public function redirect(?DateTimeInterface $expires = null): Response
    {
        return $this->streamer->redirect($this, $expires);
    }

    public function temporaryUrl(?DateTimeInterface $expires = null): string
    {
        return $this->streamer->temporaryUrl($this, $expires);
    }

    /**
     * @return array{url: string, type: string, mime: string, expires_at: string|null}
     */
    public function embedData(?DateTimeInterface $expires = null): array
    {
        return $this->streamer->embedData($this, $expires);
    }

    public function diskName(): ?string
    {
        return $this->disk;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    public function usesDefaultPrefix(): bool
    {
        return $this->usesDefaultPrefix;
    }

    public function authorizer(): ?Authorization
    {
        return $this->authorizer;
    }
}
