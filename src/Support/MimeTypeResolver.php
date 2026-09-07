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
        'm3u8' => 'application/vnd.apple.mpegurl',
        'ts' => 'video/mp2t',
        'm4s' => 'video/iso.segment',
        'mpd' => 'application/dash+xml',
        'vtt' => 'text/vtt',
        'srt' => 'application/x-subrip',
    ];

    /**
     * @var list<string>
     */
    private const GENERIC_MIMES = [
        'application/octet-stream',
        'inode/x-empty',
        'application/x-empty',
    ];

    /**
     * @var list<string>
     */
    private const TEXTUAL_EXTENSIONS = ['m3u8', 'mpd', 'vtt', 'srt'];

    /**
     * @var array<string, list<string>>
     */
    private const HLS_MIMES = [
        'm3u8' => ['application/vnd.apple.mpegurl', 'audio/mpegurl'],
        'ts' => ['video/mp2t'],
        'm4s' => ['video/iso.segment'],
        'mp4' => ['video/mp4'],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const DASH_MIMES = [
        'mpd' => ['application/dash+xml'],
        'm4s' => ['video/iso.segment'],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const CAPTION_MIMES = [
        'vtt' => ['text/vtt'],
        'srt' => ['application/x-subrip'],
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
            if (
                $mapped !== null
                && in_array($extension, self::TEXTUAL_EXTENSIONS, true)
                && (str_starts_with($detectedMime, 'text/') || str_starts_with($detectedMime, 'application/xml'))
            ) {
                return $mapped;
            }

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

        $resolved = array_values(array_map(static fn (mixed $mime): string => (string) $mime, $mimes));

        return array_values(array_unique([...$resolved, ...$this->extraMimes()]));
    }

    /**
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        /** @var list<string>|array<int|string, string> $extensions */
        $extensions = config('larastreamer.allowed_extensions', []);

        $resolved = array_values(array_map(static fn (mixed $extension): string => strtolower((string) $extension), $extensions));

        return array_values(array_unique([...$resolved, ...$this->extraExtensions()]));
    }

    /**
     * @return list<string>
     */
    private function extraExtensions(): array
    {
        $extra = [];

        if ((bool) config('larastreamer.hls.enabled', false)) {
            $extra = [...$extra, 'm3u8', 'ts', 'm4s'];
        }

        if ((bool) config('larastreamer.dash.enabled', false)) {
            $extra = [...$extra, 'mpd', 'm4s'];
        }

        if ((bool) config('larastreamer.captions.enabled', true)) {
            /** @var list<string>|array<int|string, string> $captions */
            $captions = config('larastreamer.captions.allowed_extensions', ['vtt', 'srt']);
            foreach ($captions as $extension) {
                $extra[] = strtolower((string) $extension);
            }
        }

        return $extra;
    }

    /**
     * @return list<string>
     */
    private function extraMimes(): array
    {
        $extra = [];

        if ((bool) config('larastreamer.hls.enabled', false)) {
            foreach (self::HLS_MIMES as $mimes) {
                $extra = [...$extra, ...$mimes];
            }
        }

        if ((bool) config('larastreamer.dash.enabled', false)) {
            foreach (self::DASH_MIMES as $mimes) {
                $extra = [...$extra, ...$mimes];
            }
        }

        if ((bool) config('larastreamer.captions.enabled', true)) {
            foreach (self::CAPTION_MIMES as $mimes) {
                $extra = [...$extra, ...$mimes];
            }
        }

        return $extra;
    }
}
