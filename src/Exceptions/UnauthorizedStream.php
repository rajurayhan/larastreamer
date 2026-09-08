<?php

declare(strict_types=1);

namespace Raju\Streamer\Exceptions;

use Throwable;

final class UnauthorizedStream extends StreamException
{
    public function __construct(string $message = 'You are not authorized to stream this video.', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function statusCode(): int
    {
        return 403;
    }
}
