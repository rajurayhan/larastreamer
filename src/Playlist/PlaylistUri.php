<?php

declare(strict_types=1);

namespace Raju\Streamer\Playlist;

use Raju\Streamer\Exceptions\VideoNotFound;

final class PlaylistUri
{
    public static function isAbsolute(string $uri): bool
    {
        $uri = trim($uri);

        if ($uri === '') {
            return true;
        }

        if (str_starts_with($uri, '//') || str_starts_with($uri, '/')) {
            return true;
        }

        return preg_match('#^[a-z][a-z0-9+.-]*:#i', $uri) === 1;
    }

    public static function resolve(string $playlistPath, string $uri): string
    {
        $uri = trim($uri);

        if ($uri === '' || str_contains($uri, "\0")) {
            throw new VideoNotFound;
        }

        $normalized = str_replace('\\', '/', $uri);

        for ($i = 0; $i < 3; $i++) {
            $decoded = rawurldecode($normalized);

            if ($decoded === $normalized) {
                break;
            }

            $normalized = $decoded;
        }

        if (preg_match('/(^|\/)\.\.(\/|$)/', $normalized) === 1) {
            throw new VideoNotFound;
        }

        $base = str_replace('\\', '/', dirname($playlistPath));
        $joined = ($base === '.' || $base === '') ? $normalized : $base.'/'.$normalized;

        $parts = [];

        foreach (explode('/', $joined) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                throw new VideoNotFound;
            }

            $parts[] = $part;
        }

        $resolved = implode('/', $parts);

        if ($resolved === '') {
            throw new VideoNotFound;
        }

        return $resolved;
    }
}
