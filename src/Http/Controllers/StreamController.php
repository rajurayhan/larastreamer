<?php

declare(strict_types=1);

namespace Raju\Streamer\Http\Controllers;

use Illuminate\Http\Request;
use Raju\Streamer\Contracts\Streamer;
use Raju\Streamer\Exceptions\VideoNotFound;
use Symfony\Component\HttpFoundation\Response;

final class StreamController
{
    public function __invoke(Request $request, Streamer $streamer): Response
    {
        $file = $request->query('file');

        if (! is_string($file) || $file === '') {
            return (new VideoNotFound)->toResponse();
        }

        return $streamer->file($file)->stream();
    }
}
