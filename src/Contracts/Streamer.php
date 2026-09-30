<?php

declare(strict_types=1);

namespace Raju\Streamer\Contracts;

use DateTimeInterface;
use Raju\Streamer\Streaming\PendingStream;

interface Streamer
{
    public function disk(?string $disk = null): PendingStream;

    public function file(string $path): PendingStream;

    public function signedUrl(
        string $path,
        DateTimeInterface|int|null $expires = null,
        ?string $disk = null,
    ): string;

    public function authorize(callable|Authorization $callback): static;
}
