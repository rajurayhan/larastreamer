<?php

declare(strict_types=1);

namespace Raju\Streamer\Http\Responses;

use Closure;
use Illuminate\Http\Response as LaravelResponse;
use Raju\Streamer\Streaming\Range;
use Raju\Streamer\Streaming\StreamOptions;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class VideoStreamResponse
{
    /**
     * @param  array<string, string>  $extraHeaders
     */
    public function file(string $absolutePath, string $mime, StreamOptions $options, array $extraHeaders = []): BinaryFileResponse
    {
        $response = new BinaryFileResponse(
            $absolutePath,
            200,
            $this->headers($mime, $options, extra: $extraHeaders),
            $options->cache === 'public',
            $options->disposition,
        );

        $response->headers->set('Accept-Ranges', 'bytes');
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Cache-Control', $options->cacheControl());

        if ($options->cache === 'private') {
            $response->setPrivate();
        }

        $response->setMaxAge($options->maxAge);

        return $response;
    }

    /**
     * @param  array<string, string>  $extraHeaders
     */
    public function head(string $mime, StreamOptions $options, ?Range $range, int $size, array $extraHeaders = []): Response
    {
        $status = $range instanceof Range ? 206 : 200;

        return new LaravelResponse('', $status, $this->headers($mime, $options, $range, $size, $extraHeaders));
    }

    /**
     * @param  resource  $stream
     * @param  array<string, string>  $extraHeaders
     */
    public function stream(
        mixed $stream,
        int $size,
        string $mime,
        StreamOptions $options,
        ?Range $range,
        bool $isHead,
        ?Closure $onComplete = null,
        array $extraHeaders = [],
    ): StreamedResponse {
        $status = $range instanceof Range ? 206 : 200;
        $headers = $this->headers($mime, $options, $range, $size, $extraHeaders);

        return new StreamedResponse(function () use ($stream, $range, $options, $isHead, $onComplete): void {
            try {
                if ($isHead) {
                    return;
                }

                $this->writeRange($stream, $range, $options->bufferSize);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }

                if ($onComplete instanceof Closure) {
                    $onComplete();
                }
            }
        }, $status, $headers);
    }

    /**
     * @param  array<string, string>  $extraHeaders
     */
    public function offload(string $headerName, string $headerValue, string $mime, StreamOptions $options, array $extraHeaders = []): Response
    {
        return new LaravelResponse('', 200, $this->headers($mime, $options, extra: [
            $headerName => $headerValue,
            ...$extraHeaders,
        ]));
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function headers(string $mime, StreamOptions $options, ?Range $range = null, ?int $size = null, array $extra = []): array
    {
        $headers = [
            'Content-Type' => $mime,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => $options->cacheControl(),
            'Content-Disposition' => $this->disposition($options),
        ];

        if ($range instanceof Range) {
            $headers['Content-Length'] = (string) $range->length();
            $headers['Content-Range'] = $range->contentRange();
        } elseif ($size !== null) {
            $headers['Content-Length'] = (string) $size;
        }

        return [...$headers, ...$extra];
    }

    private function disposition(StreamOptions $options): string
    {
        return $options->disposition === 'attachment' ? 'attachment' : 'inline';
    }

    /**
     * @param  resource  $stream
     */
    private function writeRange(mixed $stream, ?Range $range, int $bufferSize): void
    {
        $start = $range instanceof Range ? $range->start : 0;
        $remaining = $range instanceof Range ? $range->length() : PHP_INT_MAX;

        if ($start > 0) {
            $this->seekOrSkip($stream, $start);
        }

        while ($remaining > 0 && ! feof($stream)) {
            $chunkSize = max(1, min($bufferSize, $remaining));
            $data = fread($stream, $chunkSize);

            if ($data === false || $data === '') {
                break;
            }

            echo $data;

            $remaining -= strlen($data);
        }
    }

    /**
     * @param  resource  $stream
     */
    private function seekOrSkip(mixed $stream, int $offset): void
    {
        $meta = stream_get_meta_data($stream);

        if ($meta['seekable'] === true && fseek($stream, $offset) === 0) {
            return;
        }

        $skipped = 0;

        while ($skipped < $offset && ! feof($stream)) {
            $data = fread($stream, max(1, min(1024 * 1024, $offset - $skipped)));

            if ($data === false || $data === '') {
                break;
            }

            $skipped += strlen($data);
        }
    }
}
