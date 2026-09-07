<?php

declare(strict_types=1);

it('answers HEAD with the same headers as GET and no body', function (): void {
    $get = $this->get('/__stream?file=clip.mp4');
    $head = $this->head('/__stream?file=clip.mp4');

    $head->assertOk()
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('Content-Type', 'video/mp4')
        ->assertHeader('Content-Length', '2000');

    expect($head->headers->get('ETag'))->toBe($get->headers->get('ETag'))
        ->and($head->headers->get('Last-Modified'))->toBe($get->headers->get('Last-Modified'))
        ->and($this->responseBody($head))->toBe('');
});

it('answers HEAD for a valid range without a body', function (): void {
    $response = $this->withHeaders(['Range' => 'bytes=0-99'])->head('/__stream?file=clip.mp4');

    $response->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 0-99/2000')
        ->assertHeader('Content-Length', '100');

    expect($this->responseBody($response))->toBe('');
});
