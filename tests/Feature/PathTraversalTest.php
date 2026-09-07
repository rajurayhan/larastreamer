<?php

declare(strict_types=1);

it('rejects relative traversal', function (): void {
    $outside = dirname($this->diskRoot).'/outside.mp4';
    file_put_contents($outside, (string) hex2bin('000000206674797069736f6d0000020069736f6d69736f32617663316d703431').str_repeat("\0", 200));

    $response = $this->get('/__stream?file=../'.basename($outside));

    $response->assertNotFound();
    expect($response->getContent())->not->toContain($this->diskRoot);

    @unlink($outside);
});

it('rejects encoded traversal', function (): void {
    $this->get('/__stream?file='.rawurlencode('../clip.mp4'))->assertNotFound();
    $this->get('/__stream?file=..%2Fclip.mp4')->assertNotFound();
    $this->get('/__stream?file=%2e%2e%2fclip.mp4')->assertNotFound();
    $this->get('/__stream?file=%252e%252e%252fclip.mp4')->assertNotFound();
});

it('rejects a nested escape even when a nested file exists', function (): void {
    $this->writeFixture('courses/lesson-01.mp4', 1024);

    $this->get('/__stream?file=courses/../../clip.mp4')->assertNotFound();
    $this->get('/__stream?file=courses/lesson-01.mp4')->assertOk();
});
