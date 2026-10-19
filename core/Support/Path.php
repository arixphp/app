<?php

declare(strict_types=1);

namespace Arix\Support;

class Path extends ForbidInstance
{
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
