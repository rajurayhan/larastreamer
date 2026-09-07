<?php

declare(strict_types=1);

namespace Raju\Streamer\Contracts;

use DateTimeInterface;
use Raju\Streamer\Storage\ResolvedVideo;

interface StorageResolver
{
    public function resolve(string $disk, string $path, bool $absolute = false): ResolvedVideo;

    /**
     * @return resource
     */
    public function readStream(string $disk, string $path);

    public function temporaryUrl(string $disk, string $path, DateTimeInterface $expiration): string;

    public function isLocal(string $disk): bool;
}
