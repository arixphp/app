<?php

declare(strict_types=1);

namespace Arix\Routing;

use Arix\Constants\HttpMethod;
use InvalidArgumentException;

final class RouteCollector
{
    /** @var list<RouteDefinition> */
    private array $collection = [];
    /** @var array<string, array<string, true>> */
    private array $registered = [];

    /** @param array{0: class-string, 1: non-empty-string} $handler */
    public function get(string $path, array $handler): RouteDefinition
    {
        return $this->match([HttpMethod::GET], $path, $handler);
    }

    /** @param array{0: class-string, 1: non-empty-string} $handler */
    public function post(string $path, array $handler): RouteDefinition
    {
        return $this->match([HttpMethod::POST], $path, $handler);
    }

    /** @param array{0: class-string, 1: non-empty-string} $handler */
    public function put(string $path, array $handler): RouteDefinition
    {
        return $this->match([HttpMethod::PUT], $path, $handler);
    }

    /** @param array{0: class-string, 1: non-empty-string} $handler */
    public function patch(string $path, array $handler): RouteDefinition
    {
        return $this->match([HttpMethod::PATCH], $path, $handler);
    }

    /** @param array{0: class-string, 1: non-empty-string} $handler */
    public function delete(string $path, array $handler): RouteDefinition
    {
        return $this->match([HttpMethod::DELETE], $path, $handler);
    }

    /** @param array{0: class-string, 1: non-empty-string} $handler */
    public function head(string $path, array $handler): RouteDefinition
    {
        return $this->match([HttpMethod::HEAD], $path, $handler);
    }

    /** @param array{0: class-string, 1: non-empty-string} $handler */
    public function options(string $path, array $handler): RouteDefinition
    {
        return $this->match([HttpMethod::OPTIONS], $path, $handler);
    }

    /** @param array{0: class-string, 1: non-empty-string} $handler */
    public function query(string $path, array $handler): RouteDefinition
    {
        return $this->match([HttpMethod::QUERY], $path, $handler);
    }

    /** @param list<HttpMethod|string> $methods @param array{0: class-string, 1: non-empty-string} $handler */
    public function match(array $methods, string $path, array $handler): RouteDefinition
    {
        if (!preg_match('~^/(?:[\pL\pN_-]+(?:/[\pL\pN_-]+)*)?$~uD', $path)) {
            throw new InvalidArgumentException('Invalid static route path: ' . $path);
        }
        if (!array_is_list($handler) || count($handler) !== 2 || !is_string($handler[0]) || !is_string($handler[1])
            || !preg_match('/^App\\\\Controllers\\\\[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $handler[0])
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $handler[1]) || str_starts_with($handler[1], '__')) {
            throw new InvalidArgumentException('Route handler must identify an application controller and action.');
        }
        if ($methods === [] || !array_is_list($methods)) {
            throw new InvalidArgumentException('Route methods must be a nonempty list.');
        }
        $names = [];
        foreach ($methods as $method) {
            $name = $method instanceof HttpMethod ? $method->value : $method;
            if (!is_string($name) || !preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name)) {
                throw new InvalidArgumentException('Invalid HTTP method token.');
            }
            if (in_array($name, $names, true) || isset($this->registered[$path][$name])) {
                throw new InvalidArgumentException('Duplicate route: ' . $name . ' ' . $path);
            }
            $names[] = $name;
        }
        $route = new RouteDefinition($names, $path, $handler[0], $handler[1]);
        foreach ($names as $name) {
            $this->registered[$path][$name] = true;
        }
        $this->collection[] = $route;

        return $route;
    }

    /** @return list<RouteDefinition> */
    public function routes(): array
    {
        return $this->collection;
    }
}
