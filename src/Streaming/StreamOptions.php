<?php

declare(strict_types=1);

namespace Raju\Streamer\Streaming;

final readonly class StreamOptions
{
    public function __construct(
        public int $bufferSize = 1024 * 1024,
        public int $maxAge = 3600,
        public string $cache = 'private',
        public string $disposition = 'inline',
        public bool $noStore = false,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array{buffer_size?: int, max_age?: int, cache?: string} $streaming */
        $streaming = config('larastreamer.streaming', []);

        return new self(
            bufferSize: max(1, (int) ($streaming['buffer_size'] ?? 1024 * 1024)),
            maxAge: max(0, (int) ($streaming['max_age'] ?? 3600)),
            cache: ($streaming['cache'] ?? 'private') === 'public' ? 'public' : 'private',
        );
    }

    public function withDisposition(string $disposition): self
    {
        return new self(
            bufferSize: $this->bufferSize,
            maxAge: $this->maxAge,
            cache: $this->cache,
            disposition: $disposition === 'attachment' ? 'attachment' : 'inline',
            noStore: $this->noStore,
        );
    }

    public function withPlaylistCaching(): self
    {
        return new self(
            bufferSize: $this->bufferSize,
            maxAge: 0,
            cache: 'private',
            disposition: $this->disposition,
            noStore: true,
        );
    }

    public function cacheControl(): string
    {
        if ($this->noStore) {
            return 'private, max-age=0, no-store';
        }

        return "{$this->cache}, max-age={$this->maxAge}";
    }
}
