<?php

declare(strict_types=1);

namespace Raju\Streamer\Storage;

final readonly class ResolvedVideo
{
    public function __construct(
        public string $disk,
        public string $path,
        public int $size,
        public string $mime,
        public ?string $localPath,
        public bool $isLocal,
    ) {}
}
