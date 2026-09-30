<?php

declare(strict_types=1);

namespace Raju\Streamer\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Raju\Streamer\Contracts\Streamer;
use Raju\Streamer\Drm\PlaybackTicketManager;
use Raju\Streamer\Exceptions\VideoNotFound;
use Symfony\Component\HttpFoundation\Response;

final class PlaybackController
{
    public function __invoke(
        Request $request,
        Streamer $streamer,
        PlaybackTicketManager $tickets,
    ): Response {
        $file = $request->query('file');
        $ticket = $request->query('ticket');

        if (! is_string($file) || $file === '' || ! is_string($ticket) || $ticket === '') {
            throw new VideoNotFound;
        }

        $user = $request->user();
        $userId = $user instanceof Authenticatable ? $user->getAuthIdentifier() : null;
        $claims = $tickets->validate($ticket, $file, $userId);

        return $streamer->disk($claims->disk)
            ->file($file)
            ->playbackTicket($ticket)
            ->stream();
    }
}
