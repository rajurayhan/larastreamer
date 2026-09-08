<?php

declare(strict_types=1);

namespace Raju\Streamer\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class InvalidRange extends StreamException
{
    public function __construct(public readonly int $size = 0, string $message = 'The requested range is not satisfiable.', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function toResponse(): Response
    {
        return new Response('', 416, [
            'Content-Range' => 'bytes */'.$this->size,
            'Accept-Ranges' => 'bytes',
        ]);
    }

    public function statusCode(): int
    {
        return 416;
    }
}
