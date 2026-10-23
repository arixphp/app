<?php

declare(strict_types=1);

namespace Arix\Routing;

use InvalidArgumentException;

class UrlPath
{
    /** Encode decoded segments without interpreting escapes a second time. */
    public static function encode(string $decodedPath): string
    {

        return '/' . implode('/', array_map('rawurlencode', explode('/', trim($decodedPath, '/'))));
    }

    public static function validateMount(string $path): void
    {
        if ($path === '') {

            return;
        }

        if ($path === '/' || str_ends_with($path, '/') || str_contains($path, ':') || str_contains($path, '%')) {
            throw new InvalidArgumentException('Invalid URL mount.');
        }

        self::validateInternal($path);
    }

    public static function validateInternal(string $path): void
    {
        self::validateCurrent($path);

        if (str_contains($path, '?') || str_contains($path, '#')) {
            throw new InvalidArgumentException('Expected an internal path without query or fragment.');
        }

        foreach (explode('/', $path) as $segment) {

            if (str_starts_with($segment, '.')) {
                throw new InvalidArgumentException('Invalid internal path.');
            }

        }

        if (str_contains($path, '//')) {
            throw new InvalidArgumentException('Ambiguous internal path.');
        }
    }

    public static function validateCurrent(string $path): void
    {
        if (! str_starts_with($path, '/') || preg_match('/[\x00-\x1f\x7f\\\\]/', $path) || ! preg_match('//u', $path)) {
            throw new InvalidArgumentException('Invalid internal path.');
        }
    }
}
