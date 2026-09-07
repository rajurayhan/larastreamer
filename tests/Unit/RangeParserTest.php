<?php

declare(strict_types=1);

use Raju\Streamer\Exceptions\InvalidRange;
use Raju\Streamer\Streaming\RangeParser;

$parser = new RangeParser;

it('returns null when no range header is present', function () use ($parser): void {
    expect($parser->parse(null, 2000))->toBeNull()
        ->and($parser->parse('', 2000))->toBeNull()
        ->and($parser->parse('   ', 2000))->toBeNull();
});

it('parses a closed start range', function () use ($parser): void {
    $range = $parser->parse('bytes=0-99', 2000);

    expect($range?->start)->toBe(0)
        ->and($range?->end)->toBe(99)
        ->and($range?->length())->toBe(100)
        ->and($range?->contentRange())->toBe('bytes 0-99/2000');
});

it('parses a mid-file range', function () use ($parser): void {
    $range = $parser->parse('bytes=500-999', 2000);

    expect($range?->start)->toBe(500)
        ->and($range?->end)->toBe(999)
        ->and($range?->length())->toBe(500);
});

it('parses an open-ended range', function () use ($parser): void {
    $range = $parser->parse('bytes=1500-', 2000);

    expect($range?->start)->toBe(1500)
        ->and($range?->end)->toBe(1999)
        ->and($range?->length())->toBe(500);
});

it('parses a suffix range', function () use ($parser): void {
    $range = $parser->parse('bytes=-500', 2000);

    expect($range?->start)->toBe(1500)
        ->and($range?->end)->toBe(1999)
        ->and($range?->length())->toBe(500);
});

it('clamps a suffix longer than the file to the full representation', function () use ($parser): void {
    $range = $parser->parse('bytes=-5000', 200);

    expect($range?->start)->toBe(0)
        ->and($range?->end)->toBe(199)
        ->and($range?->length())->toBe(200);
});

it('clamps an end that exceeds the file size', function () use ($parser): void {
    $range = $parser->parse('bytes=100-9999', 2000);

    expect($range?->start)->toBe(100)
        ->and($range?->end)->toBe(1999);
});

it('rejects unsatisfiable and malformed ranges', function (string $header) use ($parser): void {
    $parser->parse($header, 2000);
})->throws(InvalidRange::class)->with([
    'bytes=5000-6000',
    'bytes=1500-100',
    'bytes=2000-',
    'bytes=-0',
    'bytes=',
    'bytes=foo-bar',
    'bytes=0-99,100-199',
    'items=0-99',
    'bytes=0',
    'bytes=-',
]);
