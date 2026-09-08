<?php

declare(strict_types=1);

namespace Raju\Streamer\Support;

use Raju\Streamer\Contracts\Authorization;

final class AllowAllAuthorization implements Authorization
{
    public function authorize(string $path, mixed $user): bool
    {
        return true;
    }
}
