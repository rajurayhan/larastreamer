<?php

declare(strict_types=1);

it('serves webvtt captions through the jail', function (): void {
    $this->writeTextFixture('clip.en.vtt', "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nHello");

    $response = $this->get('/__stream?file=clip.en.vtt');

    $response->assertOk();
    expect((string) $response->headers->get('Content-Type'))->toStartWith('text/vtt');

    expect($this->responseBody($response))->toContain('WEBVTT');
});

it('serves srt captions', function (): void {
    $this->writeTextFixture('clip.en.srt', "1\n00:00:00,000 --> 00:00:01,000\nHello");

    $this->get('/__stream?file=clip.en.srt')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/x-subrip');
});

it('returns 404 for a missing caption', function (): void {
    $this->get('/__stream?file=missing.vtt')->assertNotFound();
});

it('rejects caption traversal', function (): void {
    $this->get('/__stream?file=../clip.en.vtt')->assertNotFound();
});

it('renders player tracks', function (): void {
    $html = $this->blade('<x-larastreamer::player url="https://cdn.example.test/clip.mp4" mime="video/mp4" :captions="[[\'src\' => \'https://cdn.example.test/en.vtt\', \'srclang\' => \'en\', \'label\' => \'English\]]" />');

    expect((string) $html)
        ->toContain('<track')
        ->toContain('kind="subtitles"')
        ->toContain('https://cdn.example.test/en.vtt')
        ->toContain('srclang="en"');
});
