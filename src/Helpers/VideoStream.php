<?php

declare(strict_types=1);

namespace Raju\Streamer\Helpers;

use Raju\Streamer\Facades\Streamer;
use Symfony\Component\HttpFoundation\Response;

/**
 * @deprecated Use Streamer::file($path)->stream()
 */
class VideoStream
{
    public function __construct(private readonly string $filePath = '') {}

    /**
     * Start streaming video content.
     *
     * @deprecated Use Streamer::file($path)->stream()
     */
    public function start(): Response
    {
        return Streamer::file($this->filePath)->stream();
    }
}
