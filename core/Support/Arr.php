<?php

declare(strict_types=1);

namespace Arix\Support;

use function array_key_exists;
use function is_array;

class Arr extends ForbidInstance
{
    public static function get(array $array, string $key, mixed $default = null): mixed
    {
        $value = $array;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public static function has(array $array, string $key): bool
    {
        $value = $array;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return false;
            }

            $value = $value[$segment];
        }

        return true;
    }

    public static function set(array &$array, string $key, mixed $value): array
    {
        $segments = explode('.', $key);
        $last = array_pop($segments);
        $target = &$array;

        foreach ($segments as $segment) {
            if (! isset($target[$segment]) || ! is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }

        $target[$last] = $value;

        return $array;
    }

    public static function forget(array &$array, string $key): void
    {
        $segments = explode('.', $key);
        $last = array_pop($segments);
        $target = &$array;

        foreach ($segments as $segment) {
            if (! isset($target[$segment]) || ! is_array($target[$segment])) {
                return;
            }

            $target = &$target[$segment];
        }

        unset($target[$last]);
    }

    public static function add(array $array, string $key, mixed $value): array
    {
        if (static::has($array, $key)) {
            return $array;
        }

        return static::set($array, $key, $value);
    }

    public static function except(array $array, string|array $keys): array
    {
        foreach ((array) $keys as $key) {
            static::forget($array, $key);
        }

        return $array;
    }

    public static function only(array $array, string|array $keys): array
    {
        $result = [];

        foreach ((array) $keys as $key) {
            if (array_key_exists($key, $array)) {
                $result[$key] = $array[$key];
            }
        }

        return $result;
    }

    public static function dot(array $array, string $prepend = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $path = $prepend === '' ? (string) $key : "{$prepend}.{$key}";

            if (is_array($value) && $value !== []) {
                $result += static::dot($value, $path);
            } else {
                $result[$path] = $value;
            }
        }

        return $result;
    }

    public static function pluck(array $array, string $value, null|string $key = null): array
    {
        $results = [];

        foreach ($array as $item) {
            $itemValue = static::get((array) $item, $value);

            if ($key === null) {
                $results[] = $itemValue;

                continue;
            }

            $results[static::get((array) $item, $key)] = $itemValue;
        }

        return $results;
    }

    public static function first(array $array, null|callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return $array === [] ? $default : reset($array);
        }

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    public static function last(array $array, null|callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return $array === [] ? $default : end($array);
        }

        $result = $default;

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                $result = $value;
            }
        }

        return $result;
    }

    public static function where(array $array, callable $callback, bool $preserveKeys = true): array
    {
        $filtered = [];

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                $filtered[$key] = $value;
            }
        }

        return $preserveKeys ? $filtered : array_values($filtered);
    }

    public static function firstWhere(array $array, string $key, mixed $value): mixed
    {
        foreach ($array as $element) {
            if (static::get((array) $element, $key) === $value) {
                return $element;
            }
        }

        return null;
    }
}
