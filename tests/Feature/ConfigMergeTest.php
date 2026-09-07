<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Raju\Streamer\Facades\Streamer;

it('merges unpublished config so basepath never resolves to slash', function (): void {
    expect(config('larastreamer.storage.disk'))->toBe('local')
        ->and(config('larastreamer.storage.path'))->toBe('uploads')
        ->and(config('larastreamer.route.enabled'))->toBeFalse();

    $resolved = Storage::disk('local')->path('uploads/clip.mp4');

    expect($resolved)->not->toBe('/clip.mp4')
        ->and($resolved)->not->toStartWith('/clip.mp4')
        ->and(realpath(dirname($resolved)))->not->toBe('/');
});

it('streams through the uploads prefix instead of the filesystem root', function (): void {
    $this->writeFixture('uploads/clip.mp4', 1024);

    $response = Streamer::file('clip.mp4')->stream();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toBe('video/mp4');
});

it('does not register package routes when they are disabled', function (): void {
    expect(Route::has('larastreamer.stream'))->toBeFalse();

    $this->get('/stream?file=clip.mp4')->assertNotFound();
    $this->get('/streamer')->assertNotFound();
});
