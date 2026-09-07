<?php

declare(strict_types=1);

it('serves a start range as 206', function (): void {
    $response = $this->withHeaders(['Range' => 'bytes=0-99'])->get('/__stream?file=clip.mp4');

    $response->assertStatus(206)
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('Content-Range', 'bytes 0-99/2000')
        ->assertHeader('Content-Length', '100');

    expect($this->responseBody($response))->toHaveLength(100);
});

it('serves a mid-file range as 206', function (): void {
    $response = $this->withHeaders(['Range' => 'bytes=500-999'])->get('/__stream?file=clip.mp4');

    $response->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 500-999/2000')
        ->assertHeader('Content-Length', '500');

    expect($this->responseBody($response))->toHaveLength(500);
});

it('serves an open-ended range as 206', function (): void {
    $response = $this->withHeaders(['Range' => 'bytes=1500-'])->get('/__stream?file=clip.mp4');

    $response->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 1500-1999/2000')
        ->assertHeader('Content-Length', '500');

    expect($this->responseBody($response))->toHaveLength(500);
});

it('serves a suffix range as 206', function (): void {
    $response = $this->withHeaders(['Range' => 'bytes=-200'])->get('/__stream?file=clip.mp4');

    $response->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 1800-1999/2000')
        ->assertHeader('Content-Length', '200');

    expect($this->responseBody($response))->toHaveLength(200);
});
