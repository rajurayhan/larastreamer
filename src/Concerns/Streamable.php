<?php

declare(strict_types=1);

namespace Raju\Streamer\Concerns;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Raju\Streamer\Exceptions\StreamException;
use Raju\Streamer\Facades\Streamer;
use Raju\Streamer\Streaming\PendingStream;
use Symfony\Component\HttpFoundation\Response;

trait Streamable
{
    public function stream(): Response
    {
        return $this->pendingStream()->stream();
    }

    public function download(): Response
    {
        return $this->pendingStream()->download();
    }

    public function streamUrl(DateTimeInterface|int|null $expires = null): string
    {
        return $this->pendingStream()->embedData($this->normalizeExpiration($expires))['url'];
    }

    /**
     * @return array{url: string, type: string, mime: string, expires_at: string|null, kind: string, captions: list<array{src: string, srclang?: string, label?: string, default?: bool}>}
     */
    public function embedData(?DateTimeInterface $expires = null): array
    {
        return $this->pendingStream()->embedData($expires);
    }

    public function streamDisk(): string
    {
        $disk = $this->streamableAttribute('disk');

        if (is_string($disk) && $disk !== '') {
            return $disk;
        }

        $configured = config('larastreamer.storage.disk', 'local');

        return is_string($configured) ? $configured : 'local';
    }

    public function streamPath(): string
    {
        $path = $this->streamableAttribute('path');

        if (! is_string($path) || $path === '') {
            throw new StreamException('Streamable models must define a path.');
        }

        return $path;
    }

    /**
     * @return list<array{src: string, srclang?: string, label?: string, default?: bool}>
     */
    public function streamCaptions(): array
    {
        $captions = $this->streamableAttribute('captions');

        if (! is_array($captions)) {
            return [];
        }

        /** @var list<array{src: string, srclang?: string, label?: string, default?: bool}> $normalized */
        $normalized = array_values($captions);

        return $normalized;
    }

    private function pendingStream(): PendingStream
    {
        return Streamer::disk($this->streamDisk())
            ->file($this->streamPath())
            ->captions($this->streamCaptions());
    }

    private function streamableAttribute(string $key): mixed
    {
        $values = get_object_vars($this);

        if (array_key_exists($key, $values)) {
            return $values[$key];
        }

        if (isset($this->{$key})) {
            return $this->{$key};
        }

        return null;
    }

    private function normalizeExpiration(DateTimeInterface|int|null $expires): ?DateTimeInterface
    {
        if (is_int($expires)) {
            return (new DateTimeImmutable)->add(new DateInterval('PT'.$expires.'S'));
        }

        return $expires;
    }
}
