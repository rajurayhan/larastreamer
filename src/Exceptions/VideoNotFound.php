<?php

declare(strict_types=1);

namespace Raju\Streamer\Exceptions;

use Throwable;

final class VideoNotFound extends StreamException
{
    public function __construct(string $message = 'The requested video could not be found.', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function statusCode(): int
    {
        return 404;
    }
}
