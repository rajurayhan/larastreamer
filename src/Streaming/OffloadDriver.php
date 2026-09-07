<?php

declare(strict_types=1);

namespace Raju\Streamer\Streaming;

enum OffloadDriver: string
{
    case Nginx = 'nginx';
    case Apache = 'apache';

    public function headerName(): string
    {
        return match ($this) {
            self::Nginx => 'X-Accel-Redirect',
            self::Apache => 'X-Sendfile',
        };
    }
}
