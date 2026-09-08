<?php

declare(strict_types=1);

namespace Raju\Streamer\Metadata;

use ArrayAccess;
use LogicException;

/**
 * @implements ArrayAccess<string, int|string|float|null>
 */
final readonly class VideoMeta implements ArrayAccess
{
    public function __construct(
        public int $size,
        public string $mime,
        public ?int $lastModified,
        public string $etag,
        public ?float $duration = null,
        public ?string $codec = null,
    ) {}

    /**
     * @return array{size: int, mime: string, last_modified: int|null, etag: string, duration: float|null, codec: string|null}
     */
    public function toArray(): array
    {
        return [
            'size' => $this->size,
            'mime' => $this->mime,
            'last_modified' => $this->lastModified,
            'etag' => $this->etag,
            'duration' => $this->duration,
            'codec' => $this->codec,
        ];
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->toArray());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('VideoMeta is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('VideoMeta is immutable.');
    }
}
