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
        public ?int $lastModified,
        public string $etag,
    ) {}

    public static function etagFor(int $size, ?int $lastModified, string $path): string
    {
        $token = $lastModified !== null ? (string) $lastModified : hash('sha256', $path);

        return 'W/"'.$size.'-'.$token.'"';
    }
}
