<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Raju\Streamer\Facades\Streamer;
use Raju\Streamer\Http\Controllers\StreamController;

it('accepts integer seconds for pending embed data', function (): void {
    config([
        'larastreamer.storage.disk' => 'videos',
        'larastreamer.storage.path' => '',
    ]);

    Route::get('stream', StreamController::class)
        ->middleware('signed')
        ->name('larastreamer.stream');

    $data = Streamer::disk('videos')->file('clip.mp4')->embedData(120);

    expect($data['expires_at'])->toBeString()
        ->and($data['url'])->toContain('expires=');
});
