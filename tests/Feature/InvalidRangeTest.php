<?php

declare(strict_types=1);

it('returns 416 with a star content range for an unsatisfiable range', function (): void {
    $response = $this->withHeaders(['Range' => 'bytes=5000-6000'])->get('/__stream?file=clip.mp4');

    $response->assertStatus(416)
        ->assertHeader('Content-Range', 'bytes */2000')
        ->assertHeader('Accept-Ranges', 'bytes');

    expect($this->responseBody($response))->toBe('');
});

it('returns 416 for a malformed range', function (): void {
    $this->withHeaders(['Range' => 'bytes=foo'])->get('/__stream?file=clip.mp4')
        ->assertStatus(416)
        ->assertHeader('Content-Range', 'bytes */2000');
});

it('returns 416 for multiple ranges', function (): void {
    $this->withHeaders(['Range' => 'bytes=0-10,20-30'])->get('/__stream?file=clip.mp4')
        ->assertStatus(416);
});
