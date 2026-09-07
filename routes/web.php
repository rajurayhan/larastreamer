<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Raju\Streamer\Http\Controllers\StreamController;

$prefix = trim((string) config('larastreamer.route.prefix', 'stream'), '/');
$middleware = config('larastreamer.route.middleware', ['signed']);
$name = (string) config('larastreamer.route.name', 'larastreamer.stream');

Route::get($prefix, StreamController::class)
    ->middleware(is_array($middleware) ? $middleware : ['signed'])
    ->name($name);
