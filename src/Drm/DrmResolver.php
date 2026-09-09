<?php

declare(strict_types=1);

namespace Raju\Streamer\Drm;

use Illuminate\Http\Request;
use Raju\Streamer\Contracts\DrmProvider;
use Raju\Streamer\Exceptions\DrmConfigurationException;
use Raju\Streamer\Storage\ResolvedVideo;
use Raju\Streamer\Streaming\StreamKind;
use Throwable;

final class DrmResolver
{
    public function resolve(
        DrmConfiguration|DrmProvider $source,
        ResolvedVideo $video,
        Request $request,
    ): DrmConfiguration {
        if (! (bool) config('larastreamer.drm.enabled', true)) {
            throw new DrmConfigurationException('DRM playback is disabled.');
        }

        $kind = StreamKind::fromPath($video->path);

        if (! in_array($kind, [StreamKind::Hls, StreamKind::Dash], true)) {
            throw new DrmConfigurationException('DRM playback requires an HLS or DASH manifest.');
        }

        if ($source instanceof DrmConfiguration) {
            return $source;
        }

        try {
            return $source->configuration(new DrmContext(
                disk: $video->disk,
                path: $video->path,
                kind: $kind,
                user: $request->user(),
                request: $request,
            ));
        } catch (DrmConfigurationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DrmConfigurationException('Unable to build DRM playback configuration.');
        }
    }
}
