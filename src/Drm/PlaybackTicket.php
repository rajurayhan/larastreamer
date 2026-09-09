<?php

declare(strict_types=1);

namespace Raju\Streamer\Drm;

final readonly class PlaybackTicket
{
    public function __construct(
        public string $disk,
        public string $scope,
        public int $expiresAt,
        public ?string $userId,
    ) {}

    public function allows(string $path): bool
    {
        $path = self::normalizePath($path);

        if ($path === null) {
            return false;
        }

        return $this->scope === '' || str_starts_with($path, $this->scope.'/');
    }

    private static function normalizePath(string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $decoded = $path;

        for ($i = 0; $i < 3; $i++) {
            $next = rawurldecode($decoded);

            if ($next === $decoded) {
                break;
            }

            $decoded = $next;
        }

        $normalized = str_replace('\\', '/', $decoded);

        if (str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1
            || preg_match('/(^|\/)\.\.(\/|$)/', $normalized) === 1) {
            return null;
        }

        return $normalized;
    }
}
