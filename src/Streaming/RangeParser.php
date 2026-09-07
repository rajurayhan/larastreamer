<?php

declare(strict_types=1);

namespace Raju\Streamer\Streaming;

use Raju\Streamer\Exceptions\InvalidRange;

final class RangeParser
{
    public function parse(?string $header, int $fileSize): ?Range
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        if ($fileSize <= 0) {
            throw new InvalidRange($fileSize);
        }

        $header = trim($header);

        if (! str_starts_with(strtolower($header), 'bytes=')) {
            throw new InvalidRange($fileSize);
        }

        $spec = substr($header, 6);

        if ($spec === '' || str_contains($spec, ',')) {
            throw new InvalidRange($fileSize);
        }

        if (! str_contains($spec, '-')) {
            throw new InvalidRange($fileSize);
        }

        [$start, $end] = explode('-', $spec, 2);

        if ($start === '' && $end === '') {
            throw new InvalidRange($fileSize);
        }

        if ($start === '') {
            return $this->suffixRange($end, $fileSize);
        }

        if (! ctype_digit($start)) {
            throw new InvalidRange($fileSize);
        }

        $rangeStart = (int) $start;

        if ($end === '') {
            $rangeEnd = $fileSize - 1;
        } else {
            if (! ctype_digit($end)) {
                throw new InvalidRange($fileSize);
            }

            $rangeEnd = (int) $end;
        }

        if ($rangeStart > $rangeEnd || $rangeStart >= $fileSize) {
            throw new InvalidRange($fileSize);
        }

        if ($rangeEnd >= $fileSize) {
            $rangeEnd = $fileSize - 1;
        }

        return new Range($rangeStart, $rangeEnd, $fileSize);
    }

    private function suffixRange(string $end, int $fileSize): Range
    {
        if (! ctype_digit($end) || (int) $end === 0) {
            throw new InvalidRange($fileSize);
        }

        $suffix = (int) $end;
        $rangeStart = max(0, $fileSize - $suffix);

        return new Range($rangeStart, $fileSize - 1, $fileSize);
    }
}
