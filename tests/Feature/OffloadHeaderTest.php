<?php

declare(strict_types=1);

it('sets X-Accel-Redirect for nginx offload', function (): void {
    config([
        'larastreamer.offload.enabled' => true,
        'larastreamer.offload.driver' => 'nginx',
        'larastreamer.offload.prefix' => '/internal-videos/',
    ]);

    $response = $this->get('/__stream?file=clip.mp4');

    $response->assertOk()
        ->assertHeader('X-Accel-Redirect', '/internal-videos/clip.mp4')
        ->assertHeader('Content-Type', 'video/mp4')
        ->assertHeader('Accept-Ranges', 'bytes');

    expect($this->responseBody($response))->toBe('');
});

it('forwards Range on nginx offload without consuming the body', function (): void {
    config([
        'larastreamer.offload.enabled' => true,
        'larastreamer.offload.driver' => 'nginx',
        'larastreamer.offload.prefix' => '/internal-videos/',
    ]);

    $response = $this->withHeaders(['Range' => 'bytes=0-99'])->get('/__stream?file=clip.mp4');

    $response->assertOk()
        ->assertHeader('X-Accel-Redirect', '/internal-videos/clip.mp4')
        ->assertHeader('Accept-Ranges', 'bytes');

    expect($this->responseBody($response))->toBe('')
        ->and($response->headers->get('X-Accel-Buffering'))->toBe('no');
});

it('sets X-Sendfile for apache offload', function (): void {
    config([
        'larastreamer.offload.enabled' => true,
        'larastreamer.offload.driver' => 'apache',
    ]);

    $response = $this->get('/__stream?file=clip.mp4');

    $response->assertOk()
        ->assertHeader('X-Sendfile', (string) realpath($this->diskRoot.DIRECTORY_SEPARATOR.'clip.mp4'));
});

it('sets attachment disposition on downloads', function (): void {
    $response = $this->get('/__stream?file=clip.mp4&download=1');

    $response->assertOk();

    expect((string) $response->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain('clip.mp4');
});
