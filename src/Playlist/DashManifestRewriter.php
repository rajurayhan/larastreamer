<?php

declare(strict_types=1);

namespace Raju\Streamer\Playlist;

use Raju\Streamer\Exceptions\VideoNotFound;

final class DashManifestRewriter
{
    /**
     * @param  callable(string): string  $toUrl
     */
    public function rewrite(string $contents, string $playlistPath, callable $toUrl): string
    {
        $contents = $this->stripBom($contents);
        $trimmed = ltrim($contents);

        if ($trimmed === '' || stripos($trimmed, '<MPD') === false) {
            throw new VideoNotFound;
        }

        $rewritten = preg_replace_callback(
            '/\b(media|initialization|href|sourceURL)="([^"]*)"/i',
            function (array $matches) use ($playlistPath, $toUrl): string {
                $attribute = $matches[1];
                $uri = html_entity_decode($matches[2], ENT_QUOTES | ENT_XML1);

                if ($uri === '' || PlaylistUri::isAbsolute($uri)) {
                    return $matches[0];
                }

                $url = htmlspecialchars($toUrl(PlaylistUri::resolve($playlistPath, $uri)), ENT_QUOTES | ENT_XML1);

                return $attribute.'="'.$url.'"';
            },
            $contents,
        );

        if (! is_string($rewritten)) {
            throw new VideoNotFound;
        }

        return $rewritten;
    }

    private function stripBom(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            return substr($contents, 3);
        }

        return $contents;
    }
}
