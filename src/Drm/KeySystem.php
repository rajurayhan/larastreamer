<?php

declare(strict_types=1);

namespace Raju\Streamer\Drm;

enum KeySystem: string
{
    case Widevine = 'com.widevine.alpha';
    case PlayReady = 'com.microsoft.playready';
    case FairPlay = 'com.apple.fps';
    case ClearKey = 'org.w3.clearkey';
}
