<?php

declare(strict_types=1);

namespace Arix\Support;

use function in_array;
use function is_array;

class Path extends ForbidInstance
{
    public static function hasExactCase(string $path): bool
    {
        $parent = dirname($path);

        while ($parent !== $path) {
            if (! is_dir($parent)) {
                return false;
            }

            if (! in_array(basename($path), scandir($parent) ?: [], true)) {
                return false;
            }

            $path = $parent;
            $parent = dirname($path);
        }

        return true;
    }

    /**
     * Detects symbolic links and Windows junctions/reparse points.
     *
     * PHP's is_link() returns false for NTFS junctions. Their lstat() mode does
     * not carry the directory bit, which distinguishes them from ordinary
     * directories.
     */
    public static function isLink(string $path): bool
    {
        if (is_link($path)) {
            return true;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }

        clearstatcache(true, $path);

        if (! is_dir($path)) {
            return false;
        }

        $stat = @lstat($path);

        return is_array($stat) && ($stat['mode'] & 0xF000) !== 0x4000;
    }

    public static function isAbsolute(string $path): bool
    {

        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('~^[A-Za-z]:[/\\\\]~', $path) === 1;
    }

    public static function join(string ...$segments): string
    {
        return implode(DIRECTORY_SEPARATOR, $segments);
    }
}
