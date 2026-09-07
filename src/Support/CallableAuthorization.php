<?php

declare(strict_types=1);

namespace Raju\Streamer\Support;

use Raju\Streamer\Contracts\Authorization;

final class CallableAuthorization implements Authorization
{
    /**
     * @param  callable(string, mixed): bool  $callback
     */
    public function __construct(private readonly mixed $callback) {}

    public function authorize(string $path, mixed $user): bool
    {
        return (bool) ($this->callback)($path, $user);
    }
}
