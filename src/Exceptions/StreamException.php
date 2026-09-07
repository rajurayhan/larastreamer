<?php

declare(strict_types=1);

namespace Raju\Streamer\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class StreamException extends RuntimeException
{
    public function __construct(string $message = 'Unable to stream the requested video.', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function toResponse(): Response
    {
        return new Response($this->getMessage(), $this->statusCode());
    }

    public function render(): Response
    {
        return $this->toResponse();
    }

    public function statusCode(): int
    {
        return 500;
    }
}
