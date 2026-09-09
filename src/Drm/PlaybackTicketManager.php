<?php

declare(strict_types=1);

namespace Raju\Streamer\Drm;

use DateTimeInterface;
use Illuminate\Contracts\Encryption\StringEncrypter;
use JsonException;
use Raju\Streamer\Exceptions\VideoNotFound;
use Throwable;

final readonly class PlaybackTicketManager
{
    public function __construct(private StringEncrypter $encrypter) {}

    public function issue(
        string $disk,
        string $manifestPath,
        DateTimeInterface $expiresAt,
        mixed $userId,
    ): string {
        if ($disk === '') {
            throw new VideoNotFound;
        }

        try {
            $payload = json_encode([
                'v' => 1,
                'disk' => $disk,
                'scope' => $this->scopeFor($manifestPath),
                'exp' => $expiresAt->getTimestamp(),
                'uid' => $this->normalizeUserId($userId),
            ], JSON_THROW_ON_ERROR);

            return $this->encrypter->encryptString($payload);
        } catch (VideoNotFound $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new VideoNotFound(previous: $exception);
        }
    }

    public function validate(string $token, string $requestedPath, mixed $userId): PlaybackTicket
    {
        $decodedToken = base64_decode($token, true);

        if ($decodedToken === false || ! hash_equals(base64_encode($decodedToken), $token)) {
            throw new VideoNotFound;
        }

        try {
            $payload = json_decode(
                $this->encrypter->decryptString($token),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (Throwable $exception) {
            throw new VideoNotFound(previous: $exception);
        }

        if (! is_array($payload)
            || ($payload['v'] ?? null) !== 1
            || ! is_string($payload['disk'] ?? null)
            || $payload['disk'] === ''
            || ! is_string($payload['scope'] ?? null)
            || ! is_int($payload['exp'] ?? null)
            || $payload['exp'] < time()
            || ! array_key_exists('uid', $payload)
            || ! $this->validUserId($payload['uid'] ?? null, $userId)) {
            throw new VideoNotFound;
        }

        $scope = $payload['scope'];

        if ($scope !== '' && $this->normalizePath($scope) !== $scope) {
            throw new VideoNotFound;
        }

        $ticket = new PlaybackTicket(
            disk: $payload['disk'],
            scope: $scope,
            expiresAt: $payload['exp'],
            userId: $payload['uid'],
        );

        if (! $ticket->allows($requestedPath)) {
            throw new VideoNotFound;
        }

        return $ticket;
    }

    private function scopeFor(string $manifestPath): string
    {
        $path = $this->normalizePath($manifestPath);
        $separator = strrpos($path, '/');

        return $separator === false ? '' : substr($path, 0, $separator);
    }

    private function normalizePath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new VideoNotFound;
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

        if (str_contains($normalized, "\0")
            || str_starts_with($normalized, '/')
            || str_ends_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1
            || preg_match('/(^|\/)\.\.(\/|$)/', $normalized) === 1) {
            throw new VideoNotFound;
        }

        return $normalized;
    }

    private function normalizeUserId(mixed $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        if (! is_scalar($userId)) {
            throw new VideoNotFound;
        }

        try {
            return get_debug_type($userId).':'.json_encode($userId, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new VideoNotFound(previous: $exception);
        }
    }

    private function validUserId(mixed $storedUserId, mixed $currentUserId): bool
    {
        if ($storedUserId !== null && ! is_string($storedUserId)) {
            return false;
        }

        try {
            $normalized = $this->normalizeUserId($currentUserId);
        } catch (VideoNotFound) {
            return false;
        }

        if ($storedUserId === null || $normalized === null) {
            return $storedUserId === $normalized;
        }

        return hash_equals($storedUserId, $normalized);
    }
}
