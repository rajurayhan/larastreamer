<?php

declare(strict_types=1);

namespace Raju\Streamer\Support;

use finfo;

final class MimeTypeResolver
{
    /**
     * @var array<string, string>
     */
    private const EXTENSION_MAP = [
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogv' => 'video/ogg',
        'ogg' => 'video/ogg',
        'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo',
        'mpeg' => 'video/mpeg',
        'mpg' => 'video/mpeg',
    ];

    /**
     * @var list<string>
     */
    private const GENERIC_MIMES = [
        'application/octet-stream',
        'inode/x-empty',
        'application/x-empty',
    ];

    public function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    public function guess(?string $detectedMime, string $path): ?string
    {
        $extension = $this->extension($path);
        $mapped = self::EXTENSION_MAP[$extension] ?? null;

        if (is_string($detectedMime) && $detectedMime !== '' && ! in_array($detectedMime, self::GENERIC_MIMES, true)) {
            return $detectedMime;
        }

        return $mapped;
    }

    public function detectFromFile(string $localPath): ?string
    {
        if (! is_file($localPath)) {
            return null;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($localPath);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    public function isAllowed(string $path, ?string $mime): bool
    {
        $extension = $this->extension($path);
        $allowedExtensions = $this->allowedExtensions();
        $allowedMimes = $this->allowedMimes();

        if ($extension === '' || ! in_array($extension, $allowedExtensions, true)) {
            return false;
        }

        if (! is_string($mime) || $mime === '') {
            return false;
        }

        return in_array($mime, $allowedMimes, true);
    }

    /**
     * @return list<string>
     */
    public function allowedMimes(): array
    {
        /** @var list<string>|array<int|string, string> $mimes */
        $mimes = config('larastreamer.allowed_mimes', []);

        return array_values(array_map(static fn (mixed $mime): string => (string) $mime, $mimes));
    }

    /**
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        /** @var list<string>|array<int|string, string> $extensions */
        $extensions = config('larastreamer.allowed_extensions', []);

        return array_values(array_map(static fn (mixed $extension): string => strtolower((string) $extension), $extensions));
    }
}
