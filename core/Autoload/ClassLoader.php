<?php

declare(strict_types=1);

namespace Arix\Autoload;

use Closure;
use RuntimeException;
use Throwable;

use function in_array;
use function strlen;

class ClassLoader
{
    /** @var array<string, string> */
    private readonly array $prefixes;

    private readonly string $root;

    private Closure|null $callback = null;

    public function __construct(
        public readonly AutoloadConfiguration $configuration,
        public readonly bool $development = false,
    ) {
        $this->root = $configuration->root();
        $prefixes = $configuration->prefixes();

        if ($development) {
            $prefixes = array_replace($prefixes, $configuration->devPrefixes());
        }

        uksort(
            $prefixes,
            static fn (string $left, string $right): int => strlen($right) <=> strlen($left),
        );

        $this->prefixes = $prefixes;
    }

    public function register(): void
    {
        if ($this->callback !== null) {
            return;
        }

        $this->callback = $this->loadClass(...);

        spl_autoload_register($this->callback, prepend: true);

        try {
            $this->loadFiles();
        } catch (Throwable $exception) {
            $this->unregister();

            throw $exception;
        }
    }

    public function unregister(): void
    {
        if ($this->callback !== null) {
            spl_autoload_unregister($this->callback);
            $this->callback = null;
        }
    }

    public function findFile(string $class): string|null
    {
        if (! preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/D', $class)) {
            return null;
        }

        foreach ($this->prefixes as $prefix => $directory) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            try {
                $file = $this->resolveFile(rtrim($directory, '/') . "/{$relative}");
            } catch (RuntimeException) {
                continue;
            }

            if ($file !== null) {

                return $file;
            }
        }

        return null;
    }

    public function loadClass(string $class): void
    {
        $file = $this->findFile($class);

        if ($file !== null) {
            require_once $file;
        }
    }

    public function loadFiles(): void
    {
        $files = [];

        foreach ($this->configuration->files() as $relative) {
            $file = $this->resolveFile($relative);

            if ($file === null) {
                throw new RuntimeException("Autoload file is missing, unreadable or has incorrect case: {$relative}");
            }

            $files[] = $file;
        }

        foreach ($files as $file) {
            require_once $file;
        }
    }

    private function resolveFile(string $relative): string|null
    {
        if (! $this->rootIsValid()) {
            throw new RuntimeException('Autoload root is missing, linked or changed.');
        }

        $path = $this->root;

        foreach (explode('/', $relative) as $segment) {
            if (! $this->segmentIsValid($segment)) {
                throw new RuntimeException('Invalid relative autoload path.');
            }

            if (! is_dir($path) || ! is_readable($path)) {

                return null;
            }

            $entries = @scandir($path);

            if ($entries === false) {
                throw new RuntimeException('Autoload directory cannot be inspected.');
            }

            if (! in_array($segment, $entries, true)) {
                return null;
            }

            $path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $segment;

            if (! $this->pathIsValid($path)) {
                throw new RuntimeException("Autoload path is linked or changed: {$relative}");
            }
        }

        return is_file($path) && is_readable($path) ? $path : null;
    }

    private function rootIsValid(): bool
    {
        clearstatcache(true, $this->root);

        $rootStat = @lstat($this->root);

        return $rootStat !== false && ! is_link($this->root)
            && ($rootStat['mode'] & 0xF000) === 0x4000
            && realpath($this->root) === $this->root;
    }

    private function segmentIsValid(string $segment): bool
    {
        return $segment !== '' && $segment !== '.' && $segment !== '..'
            && ! preg_match('~[\x00-\x1f\x7f\\\\:]~', $segment)
            && rtrim($segment, '. ') === $segment;
    }

    private function pathIsValid(string $path): bool
    {
        clearstatcache(true, $path);

        $stat = @lstat($path);

        return $stat !== false && ! is_link($path)
            && realpath($path) === $path
            && (($stat['mode'] & 0xF000) === 0x4000 || ($stat['mode'] & 0xF000) === 0x8000);
    }
}
