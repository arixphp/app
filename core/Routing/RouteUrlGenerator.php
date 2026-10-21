<?php

declare(strict_types=1);

namespace Arix\Routing;

use Arix\FastRoute\RouteParser\Std;
use InvalidArgumentException;

use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function preg_match;

class RouteUrlGenerator
{
    public function __construct(
        private readonly RouteRegistry $registry,
        private readonly UrlGenerator $urls,
    ) {}

    /** @param array<string, string|int> $params */
    public function to(string $name, array $params = [], bool $absolute = true): string
    {
        $this->registry->ensureUniqueNames();
        $route = $this->registry->named($name);

        if ($route === null) {
            throw new InvalidArgumentException("Unknown route name: {$name}");
        }

        $path = $this->expand($route->path, $params, $name);

        if (! $absolute) {

            return $this->relative($path);
        }

        return $this->urls->to($path);
    }

    /** @param array<string, string|int> $params */
    private function expand(string $pattern, array $params, string $name): string
    {
        foreach ($params as $key => $value) {
            if (! is_string($key) || (! is_string($value) && ! is_int($value))) {
                throw new InvalidArgumentException("Invalid parameters for route: {$name}");
            }
        }

        $keys = array_keys($params);

        foreach ((new Std())->parse($pattern) as $variant) {
            $placeholders = [];

            foreach ($variant as $part) {
                if (is_array($part)) {
                    $placeholders[$part[0]] = $part[1];
                }
            }

            if (count($placeholders) !== count($params)) {
                continue;
            }

            $missing = false;

            foreach ($placeholders as $key => $regex) {
                if (! in_array($key, $keys, true)) {
                    $missing = true;

                    break;
                }

                $value = (string) $params[$key];

                if (! RouteParameter::isSafe($value)) {
                    throw new InvalidArgumentException("Invalid parameter '{$key}' for route: {$name}");
                }

                if (@preg_match('~^(?:' . $regex . ')$~D', $value) !== 1) {
                    throw new InvalidArgumentException("Invalid parameter '{$key}' for route: {$name}");
                }
            }

            if ($missing) {
                continue;
            }

            $path = '';

            foreach ($variant as $part) {
                if (is_string($part)) {
                    $path .= $part;
                } else {
                    $path .= (string) $params[$part[0]];
                }
            }

            return $path === '' ? '/' : $path;
        }

        throw new InvalidArgumentException("Invalid parameters for route: {$name}");
    }

    private function relative(string $path): string
    {
        $trimmed = trim($path, '/');

        if ($trimmed === '') {

            return '/';
        }

        return '/' . implode('/', array_map('rawurlencode', explode('/', $trimmed)));
    }
}
