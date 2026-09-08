<?php

declare(strict_types=1);

namespace Raju\Streamer\Streaming;

enum StreamKind: string
{
    case Progressive = 'progressive';
    case Hls = 'hls';
    case Dash = 'dash';
    case Caption = 'caption';

    public static function fromPath(string $path): self
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'm3u8' => self::Hls,
            'mpd' => self::Dash,
            'vtt', 'srt' => self::Caption,
            default => self::Progressive,
        };
    }
}
