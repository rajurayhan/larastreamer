<?php

declare(strict_types=1);

namespace Raju\Streamer\Contracts;

use Raju\Streamer\Drm\DrmConfiguration;
use Raju\Streamer\Drm\DrmContext;

interface DrmProvider
{
    public function configuration(DrmContext $context): DrmConfiguration;
}
