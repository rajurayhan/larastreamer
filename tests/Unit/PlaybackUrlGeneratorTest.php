<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Raju\Streamer\Drm\PlaybackUrlGenerator;

it('preserves supported DASH template identifiers', function (): void {
    Route::get('/stream/playback', fn () => '')->name('larastreamer.playback');

    $url = app(PlaybackUrlGenerator::class)->url(
        'movie/chunk-$RepresentationID$-$Number%05d$-$Bandwidth$-$Time$.m4s',
        'opaque-ticket',
    );

    expect($url)
        ->toContain('$RepresentationID$')
        ->toContain('$Number%05d$')
        ->toContain('$Bandwidth$')
        ->toContain('$Time$')
        ->toContain('ticket=opaque-ticket');
});
