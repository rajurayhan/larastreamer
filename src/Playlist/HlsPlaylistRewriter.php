<?php

declare(strict_types=1);

namespace Raju\Streamer\Playlist;

use Raju\Streamer\Exceptions\VideoNotFound;

final class HlsPlaylistRewriter
{
    /**
     * @param  callable(string): string  $toUrl
     */
    public function rewrite(string $contents, string $playlistPath, callable $toUrl): string
    {
        $contents = $this->stripBom($contents);
        $trimmed = ltrim($contents);

        if ($trimmed === '' || ! str_starts_with($trimmed, '#EXTM3U')) {
            throw new VideoNotFound;
        }

        $lines = preg_split('/\r\n|\n|\r/', $contents);

        if ($lines === false) {
            throw new VideoNotFound;
        }

        $rewritten = [];

        foreach ($lines as $line) {
            if ($this->isUriLine($line)) {
                $rewritten[] = $this->rewriteRawUri($line, $playlistPath, $toUrl);

                continue;
            }

            if ($this->hasUriAttribute($line)) {
                $rewritten[] = $this->rewriteUriAttribute($line, $playlistPath, $toUrl);

                continue;
            }

            $rewritten[] = $line;
        }

        return implode("\n", $rewritten);
    }

    /**
     * @param  callable(string): string  $toUrl
     */
    private function rewriteRawUri(string $line, string $playlistPath, callable $toUrl): string
    {
        $uri = trim($line);

        if (PlaylistUri::isAbsolute($uri)) {
            return $line;
        }

        return $toUrl(PlaylistUri::resolve($playlistPath, $uri));
    }

    /**
     * @param  callable(string): string  $toUrl
     */
    private function rewriteUriAttribute(string $line, string $playlistPath, callable $toUrl): string
    {
        $replaced = preg_replace_callback(
            '/URI=("([^"]*)"|\'([^\']*)\'|([^,]*))/',
            function (array $matches) use ($playlistPath, $toUrl): string {
                $uri = ($matches[2] ?? '') !== ''
                    ? $matches[2]
                    : (($matches[3] ?? '') !== '' ? $matches[3] : ($matches[4] ?? ''));

                if (PlaylistUri::isAbsolute($uri)) {
                    return $matches[0];
                }

                $url = $toUrl(PlaylistUri::resolve($playlistPath, $uri));

                return 'URI="'.$url.'"';
            },
            $line,
        );

        if (! is_string($replaced)) {
            throw new VideoNotFound;
        }

        return $replaced;
    }

    private function isUriLine(string $line): bool
    {
        $line = trim($line);

        return $line !== '' && ! str_starts_with($line, '#');
    }

    private function hasUriAttribute(string $line): bool
    {
        return str_starts_with($line, '#EXT-X-KEY')
            || str_starts_with($line, '#EXT-X-MAP')
            || str_starts_with($line, '#EXT-X-MEDIA')
            || str_starts_with($line, '#EXT-X-SESSION-KEY')
            || str_starts_with($line, '#EXT-X-I-FRAME-STREAM-INF');
    }

    private function stripBom(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            return substr($contents, 3);
        }

        return $contents;
    }
}
