<?php

declare(strict_types=1);

namespace Arix\Routing;

use function preg_match;
use function str_contains;

/**
 * The policy of a route parameter.
 *
 * A route parameter is exactly one path segment. Its value is controlled by
 * the visitor, so it must never cross a segment boundary and must never
 * carry a character the framework rejects in a path.
 */
class RouteParameter
{
    public static function isSafe(string $value): bool
    {
        if ($value === '' || $value === '.' || $value === '..') {
            return false;
        }

        if (str_contains($value, '/') || str_contains($value, '\\')) {
            return false;
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1 || preg_match('//u', $value) !== 1) {
            return false;
        }

        return true;
    }
}
