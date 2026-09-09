<?php

declare(strict_types=1);

namespace Raju\Streamer;

use Illuminate\Support\ServiceProvider;
use Raju\Streamer\Contracts\Authorization;
use Raju\Streamer\Contracts\StorageResolver;
use Raju\Streamer\Contracts\Streamer;
use Raju\Streamer\Drm\DrmResolver;
use Raju\Streamer\Drm\PlaybackTicketManager;
use Raju\Streamer\Http\Responses\VideoStreamResponse;
use Raju\Streamer\Playlist\DashManifestRewriter;
use Raju\Streamer\Playlist\HlsPlaylistRewriter;
use Raju\Streamer\Storage\LaravelFilesystem;
use Raju\Streamer\Streaming\RangeParser;
use Raju\Streamer\Streaming\StreamContext;
use Raju\Streamer\Streaming\VideoStreamer;
use Raju\Streamer\Support\AllowAllAuthorization;
use Raju\Streamer\Support\MimeTypeResolver;

final class StreamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/larastreamer.php', 'larastreamer');

        $this->app->singleton(MimeTypeResolver::class);
        $this->app->singleton(RangeParser::class);
        $this->app->singleton(VideoStreamResponse::class);
        $this->app->singleton(HlsPlaylistRewriter::class);
        $this->app->singleton(DashManifestRewriter::class);
        $this->app->singleton(DrmResolver::class);
        $this->app->singleton(PlaybackTicketManager::class);
        $this->app->singleton(StorageResolver::class, LaravelFilesystem::class);
        $this->app->singleton(Authorization::class, AllowAllAuthorization::class);
        $this->app->scoped(StreamContext::class);
        $this->app->singleton(Streamer::class, VideoStreamer::class);
        $this->app->alias(Streamer::class, 'larastreamer');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/larastreamer.php' => config_path('larastreamer.php'),
        ], 'larastreamer');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/larastreamer'),
        ], 'larastreamer-views');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'larastreamer');

        if ((bool) config('larastreamer.route.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }
    }
}
