<?php

declare(strict_types=1);

namespace Raju\Streamer\Drm;

use Illuminate\Http\Request;
use Raju\Streamer\Streaming\StreamKind;

final readonly class DrmContext
{
    public function __construct(
        public string $disk,
        public string $path,
        public StreamKind $kind,
        public mixed $user,
        public Request $request,
    ) {}
}
