<?php

declare(strict_types=1);

namespace Raju\Streamer\Facades;

use DateTimeInterface;
use Illuminate\Support\Facades\Facade;
use Raju\Streamer\Contracts\Authorization;
use Raju\Streamer\Contracts\Streamer as StreamerContract;
use Raju\Streamer\Streaming\PendingStream;

/**
 * @method static PendingStream disk(?string $disk = null)
 * @method static PendingStream file(string $path)
 * @method static string signedUrl(string $path, DateTimeInterface|int|null $expires = null, ?string $disk = null)
 * @method static StreamerContract authorize(callable|Authorization $callback)
 */
final class Streamer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StreamerContract::class;
    }
}
