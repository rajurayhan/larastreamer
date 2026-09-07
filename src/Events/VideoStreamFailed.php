<?php

declare(strict_types=1);

namespace Raju\Streamer\Events;

final readonly class VideoStreamFailed
{
    public function __construct(
        public string $path,
        public string $disk,
        public mixed $userId,
        public ?string $range,
        public string $strategy,
        public string $reason,
    ) {}
}
