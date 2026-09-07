<?php

declare(strict_types=1);

namespace Raju\Streamer\Support;

use Raju\Streamer\Concerns\Streamable;

/**
 * @internal Host so PHPStan analyses {@see Streamable}.
 */
final class StreamableHost
{
    use Streamable;

    public string $path = '';

    public ?string $disk = null;

    /**
     * @var list<array{src: string, srclang?: string, label?: string, default?: bool}>|null
     */
    public ?array $captions = null;
}
