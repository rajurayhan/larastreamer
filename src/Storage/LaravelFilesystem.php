<?php

declare(strict_types=1);

namespace Raju\Streamer\Storage;

use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Raju\Streamer\Contracts\StorageResolver;
use Raju\Streamer\Exceptions\StreamException;
use Raju\Streamer\Exceptions\VideoNotFound;
use Raju\Streamer\Support\MimeTypeResolver;
use Throwable;

final class LaravelFilesystem implements StorageResolver
{
    public function __construct(
        private readonly FilesystemManager $filesystem,
        private readonly MimeTypeResolver $mimeTypes,
    ) {}

    public function resolve(string $disk, string $path, bool $absolute = false): ResolvedVideo
    {
        if ($absolute) {
            return $this->resolveAbsolute($disk, $path);
        }

        $path = $this->normalize($path);
        $adapter = $this->adapter($disk);

        if (! $adapter->exists($path) || $this->isDirectory($adapter, $path)) {
            throw new VideoNotFound;
        }

        if ($this->isLocal($disk)) {
            $localPath = $this->jailedLocalPath($disk, $path);
        } else {
            $localPath = null;
        }

        $size = (int) $adapter->size($path);

        if ($size <= 0) {
            throw new VideoNotFound;
        }

        $detected = $localPath !== null
            ? $this->mimeTypes->detectFromFile($localPath)
            : $adapter->mimeType($path);

        $mime = $this->mimeTypes->guess(is_string($detected) ? $detected : null, $path);

        if (! $this->mimeTypes->isAllowed($path, $mime)) {
            throw new VideoNotFound;
        }

        return new ResolvedVideo(
            disk: $disk,
            path: $path,
            size: $size,
            mime: $mime ?? '',
            localPath: $localPath,
            isLocal: $this->isLocal($disk),
        );
    }

    public function readStream(string $disk, string $path)
    {
        $stream = $this->adapter($disk)->readStream($path);

        if (! is_resource($stream)) {
            throw new VideoNotFound;
        }

        return $stream;
    }

    public function temporaryUrl(string $disk, string $path, DateTimeInterface $expiration): string
    {
        $adapter = $this->adapter($disk);

        if (! method_exists($adapter, 'temporaryUrl')) {
            throw new StreamException('The configured disk does not support temporary URLs.');
        }

        try {
            return $adapter->temporaryUrl($path, $expiration);
        } catch (Throwable $exception) {
            throw new StreamException('Unable to create a temporary URL for this disk.', previous: $exception);
        }
    }

    public function isLocal(string $disk): bool
    {
        return config("filesystems.disks.{$disk}.driver") === 'local';
    }

    private function resolveAbsolute(string $disk, string $path): ResolvedVideo
    {
        $path = $this->rejectTraversal($path);
        $real = realpath($path);

        if ($real === false || ! is_file($real) || is_dir($real)) {
            throw new VideoNotFound;
        }

        if (! $this->isInsideAllowedRoots($real)) {
            throw new VideoNotFound;
        }

        $size = (int) filesize($real);

        if ($size <= 0) {
            throw new VideoNotFound;
        }

        $mime = $this->mimeTypes->guess($this->mimeTypes->detectFromFile($real), $real);

        if (! $this->mimeTypes->isAllowed($real, $mime)) {
            throw new VideoNotFound;
        }

        return new ResolvedVideo(
            disk: $disk,
            path: $this->relativeDisplayPath($real),
            size: $size,
            mime: $mime ?? '',
            localPath: $real,
            isLocal: true,
        );
    }

    private function jailedLocalPath(string $disk, string $path): string
    {
        $root = $this->diskRoot($disk);
        $full = $this->adapter($disk)->path($path);
        $real = realpath($full);

        if ($root === false || $real === false || ! is_file($real)) {
            throw new VideoNotFound;
        }

        if ($real !== $root && ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            throw new VideoNotFound;
        }

        if (is_dir($real)) {
            throw new VideoNotFound;
        }

        return $real;
    }

    private function diskRoot(string $disk): string|false
    {
        $root = config("filesystems.disks.{$disk}.root");

        if (! is_string($root) || $root === '') {
            return false;
        }

        return realpath($root);
    }

    private function isInsideAllowedRoots(string $realPath): bool
    {
        foreach ($this->allowedRoots() as $root) {
            if ($realPath === $root || str_starts_with($realPath, $root.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function allowedRoots(): array
    {
        $roots = [];

        $storage = realpath(storage_path());

        if ($storage !== false) {
            $roots[] = $storage;
        }

        /** @var array<string, array<string, mixed>> $disks */
        $disks = config('filesystems.disks', []);

        foreach ($disks as $config) {
            if (($config['driver'] ?? null) !== 'local' || ! isset($config['root']) || ! is_string($config['root'])) {
                continue;
            }

            $root = realpath($config['root']);

            if ($root !== false) {
                $roots[] = $root;
            }
        }

        return array_values(array_unique($roots));
    }

    private function normalize(string $path): string
    {
        $path = $this->rejectTraversal($path);
        $path = str_replace('\\', '/', $path);
        $path = trim($path);

        if ($path === '' || str_starts_with($path, '/')) {
            throw new VideoNotFound;
        }

        return $path;
    }

    private function rejectTraversal(string $path): string
    {
        if (str_contains($path, "\0")) {
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

        if (preg_match('/(^|\/)\.\.(\/|$)/', $normalized) === 1) {
            throw new VideoNotFound;
        }

        return $decoded;
    }

    private function relativeDisplayPath(string $realPath): string
    {
        return basename($realPath);
    }

    private function isDirectory(Filesystem $adapter, string $path): bool
    {
        if (method_exists($adapter, 'directoryExists')) {
            return $adapter->directoryExists($path);
        }

        return false;
    }

    private function adapter(string $disk): Filesystem
    {
        return $this->filesystem->disk($disk);
    }
}
