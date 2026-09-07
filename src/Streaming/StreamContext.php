<?php

declare(strict_types=1);

namespace Raju\Streamer\Streaming;

use Raju\Streamer\Contracts\Authorization;

final class StreamContext
{
    public const ATTRIBUTE = 'larastreamer.authorization';

    public function setAuthorization(Authorization $authorization): void
    {
        request()->attributes->set(self::ATTRIBUTE, $authorization);
    }

    public function authorization(): ?Authorization
    {
        $value = request()->attributes->get(self::ATTRIBUTE);

        return $value instanceof Authorization ? $value : null;
    }
}
