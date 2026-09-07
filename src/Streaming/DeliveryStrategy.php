<?php

declare(strict_types=1);

namespace Raju\Streamer\Streaming;

enum DeliveryStrategy: string
{
    case File = 'file';
    case Offload = 'offload';
    case Proxy = 'proxy';
    case Redirect = 'redirect';
}
