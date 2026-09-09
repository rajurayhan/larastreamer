<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Raju\Streamer\Http\Controllers\PlaybackController;
use Raju\Streamer\Http\Controllers\StreamController;

$prefix = trim((string) config('larastreamer.route.prefix', 'stream'), '/');
$middleware = config('larastreamer.route.middleware', ['signed']);
$name = (string) config('larastreamer.route.name', 'larastreamer.stream');

Route::get($prefix, StreamController::class)
    ->middleware(is_array($middleware) ? $middleware : ['signed'])
    ->name($name);

$playbackName = config('larastreamer.drm.playback_route_name', 'larastreamer.playback');
$playbackMiddleware = config('larastreamer.drm.playback_middleware', []);

Route::get($prefix.'/playback', PlaybackController::class)
    ->middleware(is_array($playbackMiddleware) ? $playbackMiddleware : [])
    ->name(is_string($playbackName) ? $playbackName : 'larastreamer.playback');
