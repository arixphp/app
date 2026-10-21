<?php

declare(strict_types=1);

namespace Arix\Support;

use function in_array;

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
