<?php

declare(strict_types=1);

it('answers HEAD with the same headers as GET and no body', function (): void {
    $response = $this->head('/__stream?file=clip.mp4');

    $response->assertOk()
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('Content-Type', 'video/mp4')
        ->assertHeader('Content-Length', '2000');

    expect($this->responseBody($response))->toBe('');
});

it('answers HEAD for a valid range without a body', function (): void {
    $response = $this->withHeaders(['Range' => 'bytes=0-99'])->head('/__stream?file=clip.mp4');

    $response->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 0-99/2000')
        ->assertHeader('Content-Length', '100');

    expect($this->responseBody($response))->toBe('');
});
