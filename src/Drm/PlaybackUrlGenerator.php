<?php

declare(strict_types=1);

namespace Raju\Streamer\Drm;

use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Route;
use Raju\Streamer\Exceptions\StreamException;

final readonly class PlaybackUrlGenerator
{
    private const DASH_TEMPLATE = '/\$(?:RepresentationID|Number(?:%0\d+d)?|Bandwidth|Time(?:%0\d+d)?)\$/';

    public function __construct(private UrlGenerator $urls) {}

    public function url(string $path, string $ticket): string
    {
        $name = config('larastreamer.drm.playback_route_name', 'larastreamer.playback');
        $name = is_string($name) ? $name : 'larastreamer.playback';

        if (! Route::has($name)) {
            throw new StreamException('DRM playback routes are disabled.');
        }

        $templates = [];
        $markedPath = preg_replace_callback(
            self::DASH_TEMPLATE,
            function (array $matches) use (&$templates): string {
                $marker = '__LARASTREAMER_DASH_'.count($templates).'__';
                $templates[$marker] = $matches[0];

                return $marker;
            },
            $path,
        );

        if (! is_string($markedPath)) {
            throw new StreamException('Unable to build a DRM playback URL.');
        }

        $url = $this->urls->route($name, [
            'file' => $markedPath,
            'ticket' => $ticket,
        ]);

        return str_replace(array_keys($templates), array_values($templates), $url);
    }
}
