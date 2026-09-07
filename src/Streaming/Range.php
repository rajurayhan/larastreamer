<?php

declare(strict_types=1);

namespace Raju\Streamer\Streaming;

final readonly class Range
{
    public function __construct(
        public int $start,
        public int $end,
        public int $size,
    ) {}

    public function length(): int
    {
        return $this->end - $this->start + 1;
    }

    public function contentRange(): string
    {
        return "bytes {$this->start}-{$this->end}/{$this->size}";
    }

    public function header(): string
    {
        return "bytes={$this->start}-{$this->end}";
    }
}
