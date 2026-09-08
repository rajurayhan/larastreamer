<?php

declare(strict_types=1);

namespace Raju\Streamer\Contracts;

use Raju\Streamer\Storage\ResolvedVideo;

interface Probe
{
    /**
     * @return array<string, mixed>
     */
    public function inspect(ResolvedVideo $video): array;
}
