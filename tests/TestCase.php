<?php

declare(strict_types=1);

namespace Raju\Streamer\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Raju\Streamer\Facades\Streamer;
use Raju\Streamer\StreamServiceProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

abstract class TestCase extends BaseTestCase
{
    protected bool $enableRoutes = false;

    protected string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writeFixture('clip.mp4', 2000);
    }

    protected function tearDown(): void
    {
        $this->cleanupDisk();

        parent::tearDown();
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [StreamServiceProvider::class];
    }

    /**
     * @param  Application  $app
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'Streamer' => Streamer::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $this->diskRoot = sys_get_temp_dir().'/larastreamer-'.spl_object_id($this);

        if (! is_dir($this->diskRoot)) {
            mkdir($this->diskRoot, 0777, true);
        }

        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => $this->diskRoot,
            'throw' => false,
        ]);

        $app['config']->set('filesystems.disks.videos', [
            'driver' => 'local',
            'root' => $this->diskRoot,
            'throw' => false,
        ]);

        $app['config']->set('larastreamer.route.enabled', $this->enableRoutes);

        if ($this->enableRoutes) {
            $app['config']->set('larastreamer.storage.disk', 'videos');
            $app['config']->set('larastreamer.storage.path', '');
        }
    }

    protected function defineRoutes($router): void
    {
        $router->get('/__stream', function () {
            $pending = Streamer::disk('videos')->file((string) request('file', 'clip.mp4'));

            if (request()->boolean('download')) {
                return $pending->download();
            }

            if (request()->query('authorize') === 'deny') {
                return $pending->authorize(fn (): bool => false)->stream();
            }

            if (request()->query('authorize') === 'allow') {
                return $pending->authorize(fn (): bool => true)->stream();
            }

            return $pending->stream();
        });
    }

    protected function writeFixture(string $name, int $size = 2000): string
    {
        $path = $this->diskRoot.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $name);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $header = (string) hex2bin('000000206674797069736f6d0000020069736f6d69736f32617663316d703431');
        $padding = str_repeat("\0", max(0, $size - strlen($header)));

        file_put_contents($path, substr($header.$padding, 0, $size));

        return $path;
    }

    protected function writeTextFixture(string $name, string $contents): string
    {
        $path = $this->diskRoot.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $name);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    protected function responseBody(TestResponse $response): string
    {
        $base = $response->baseResponse;

        if ($base instanceof StreamedResponse || $base instanceof BinaryFileResponse) {
            ob_start();
            $base->sendContent();

            return (string) ob_get_clean();
        }

        return (string) $response->getContent();
    }

    private function cleanupDisk(): void
    {
        if (! isset($this->diskRoot) || ! is_dir($this->diskRoot)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->diskRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->diskRoot);
    }
}
