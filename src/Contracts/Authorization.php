<?php

declare(strict_types=1);

namespace Raju\Streamer\Contracts;

interface Authorization
{
    public function authorize(string $path, mixed $user): bool;
}
