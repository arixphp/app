<?php

declare(strict_types=1);

namespace Arix\Storage;

use InvalidArgumentException;
use RuntimeException;

use function is_dir;
use function is_file;
use function is_link;
use function is_readable;
use function is_writable;
use function mkdir;
use function realpath;
use function rename;
use function tempnam;
use function unlink;

class Storage
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $ancestor = $root;

        while (true) {
            if (is_link($ancestor)) {
                throw new InvalidArgumentException('Linked storage roots are not allowed.');
            }

            $parent = dirname($ancestor);

            if ($parent === $ancestor) {
                break;
            }

            $ancestor = $parent;
        }

        $real = realpath($root);

        if ($real === false) {
            if (! is_dir(dirname($root)) || ! mkdir($root, recursive: true)) {
                throw new InvalidArgumentException('Storage root is missing.');
            }

            $real = realpath($root);

            if ($real === false || ! is_dir($real)) {
                throw new InvalidArgumentException('Storage root is missing.');
            }
        }

        if (! is_readable($real) || ! is_writable($real)) {
            throw new InvalidArgumentException('Storage root is not readable and writable.');
        }

        $this->root = $real;
    }

    public function put(string $path, string $contents): void
    {
        $file = $this->resolvePath($path, createParents: true);
        $temp = tempnam(dirname($file), 'arix_storage_');

        if ($temp === false) {
            throw new RuntimeException('Unable to create temporary storage file.');
        }

        if (file_put_contents($temp, $contents) === false) {
            unlink($temp);

            throw new RuntimeException('Unable to write storage file.');
        }

        if (! rename($temp, $file)) {
            unlink($temp);

            throw new RuntimeException('Unable to finalize storage file.');
        }
    }

    public function append(string $path, string $contents): void
    {
        $file = $this->resolvePath($path, createParents: false);

        if (! is_file($file) || ! is_readable($file)) {
            throw new InvalidArgumentException('Storage file is missing.');
        }

        $current = file_get_contents($file);

        if ($current === false) {
            throw new RuntimeException('Unable to read storage file.');
        }

        $this->put($path, $current . $contents);
    }

    public function prepend(string $path, string $contents): void
    {
        $file = $this->resolvePath($path, createParents: false);

        if (! is_file($file) || ! is_readable($file)) {
            throw new InvalidArgumentException('Storage file is missing.');
        }

        $current = file_get_contents($file);

        if ($current === false) {
            throw new RuntimeException('Unable to read storage file.');
        }

        $this->put($path, $contents . $current);
    }

    public function get(string $path): string
    {
        $file = $this->resolvePath($path, createParents: false);

        if (! is_file($file) || ! is_readable($file)) {
            throw new InvalidArgumentException('Storage file is missing.');
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException('Unable to read storage file.');
        }

        return $contents;
    }

    public function has(string $path): bool
    {
        try {
            $file = $this->resolvePath($path, createParents: false);
        } catch (InvalidArgumentException) {
            return false;
        }

        return is_file($file) && is_readable($file);
    }

    public function delete(string $path): void
    {
        $file = $this->resolvePath($path, createParents: false);

        if (! is_file($file) || ! is_readable($file)) {
            throw new InvalidArgumentException('Storage file is missing.');
        }

        if (! unlink($file)) {
            throw new RuntimeException('Unable to delete storage file.');
        }
    }

    public function missing(string $path): bool
    {
        return ! $this->has($path);
    }

    public function path(string $path): string
    {
        return $this->resolvePath($path, createParents: false);
    }

    private function resolvePath(string $path, bool $createParents): string
    {
        $this->validatePathFormat($path);

        $segments = $this->normalizeSegments($path);
        $this->validateSegments($segments);

        $file = $this->resolveSegments($segments, $createParents);
        $this->validateFinalPath($file);

        return $file;
    }

    private function validatePathFormat(string $path): void
    {
        if ($path === '' || str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('Storage path must be relative.');
        }
    }

    private function normalizeSegments(string $path): array
    {
        return explode('/', str_replace('\\', '/', $path));
    }

    private function validateSegments(array $segments): void
    {
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Storage path contains an invalid segment.');
            }
        }
    }

    private function resolveSegments(array $segments, bool $createParents): string
    {
        $lastIndex = array_key_last($segments);
        $file = $this->root;

        foreach ($segments as $index => $segment) {
            $file .= DIRECTORY_SEPARATOR . $segment;

            if ($index === $lastIndex) {
                break;
            }

            $file = $this->ensureParentDirectory($file, $createParents);
        }

        return $file;
    }

    private function ensureParentDirectory(string $file, bool $createParents): string
    {
        if (! is_dir($file)) {
            if (! $createParents) {
                throw new InvalidArgumentException('Storage path is missing.');
            }

            if (! mkdir($file, recursive: true)) {
                throw new RuntimeException('Unable to create storage directory.');
            }
        }

        clearstatcache(true, $file);

        if (is_link($file) || realpath($file) !== $file) {
            throw new InvalidArgumentException('Linked storage paths are not allowed.');
        }

        return $file;
    }

    private function validateFinalPath(string $file): void
    {
        if (! is_file($file) && ! is_dir($file)) {
            return;
        }

        clearstatcache(true, $file);

        if (is_link($file) || realpath($file) !== $file) {
            throw new InvalidArgumentException('Linked storage paths are not allowed.');
        }
    }
}
