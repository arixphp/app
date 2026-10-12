<?php

declare(strict_types=1);

namespace Arix\Routing;

use Arix\Constants\HttpMethod;
use Arix\FastRoute\DataGenerator\GroupCountBased;
use Arix\FastRoute\Dispatcher;
use Arix\FastRoute\Dispatcher\GroupCountBased as CompiledDispatcher;
use Arix\FastRoute\RouteCollector;
use Arix\FastRoute\RouteParser\Std;
use InvalidArgumentException;
use LogicException;

use function count;
use function in_array;
use function is_array;
use function is_string;

class RouteRegistry
{
    /** @var list<RouteDefinition> */
    private array $routes = [];

    private Dispatcher|null $compiled = null;

    public function get(string $path, array $handler): RouteDefinition
    {
        return $this->match([HttpMethod::GET->value], $path, $handler);
    }

    public function head(string $path, array $handler): RouteDefinition
    {
        return $this->match([HttpMethod::HEAD->value], $path, $handler);
    }

    public function post(string $path, array $handler): RouteDefinition
    {
        return $this->match([HttpMethod::POST->value], $path, $handler);
    }

    public function put(string $path, array $handler): RouteDefinition
    {
        return $this->match([HttpMethod::PUT->value], $path, $handler);
    }

    public function patch(string $path, array $handler): RouteDefinition
    {
        return $this->match([HttpMethod::PATCH->value], $path, $handler);
    }

    public function delete(string $path, array $handler): RouteDefinition
    {
        return $this->match([HttpMethod::DELETE->value], $path, $handler);
    }

    public function options(string $path, array $handler): RouteDefinition
    {
        return $this->match([HttpMethod::OPTIONS->value], $path, $handler);
    }

    public function query(string $path, array $handler): RouteDefinition
    {
        return $this->match([HttpMethod::QUERY->value], $path, $handler);
    }

    /** @param list<string> $methods @param array{class-string, string} $handler */
    public function match(array $methods, string $path, array $handler): RouteDefinition
    {
        if ($this->compiled !== null) {
            throw new LogicException('Routes cannot be registered after compilation.');
        }

        if (! str_starts_with($path, '/')) {
            throw new InvalidArgumentException('Route must be an absolute path without a query or fragment.');
        }

        if (! array_is_list($handler) || count($handler) !== 2 || ! is_string($handler[0]) || ! is_string($handler[1])
            || ! preg_match('/^App\\\\Controllers\\\\[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $handler[0])
            || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $handler[1]) || str_starts_with($handler[1], '__')) {
            throw new InvalidArgumentException('Route handler must identify an application controller and action.');
        }

        if ($methods === [] || ! array_is_list($methods)) {
            throw new InvalidArgumentException('Route methods must be a nonempty list.');
        }

        $names = [];

        foreach ($methods as $method) {
            if (! is_string($method) || $method === '*' || ! preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $method)) {
                throw new InvalidArgumentException('Invalid HTTP method token.');
            }

            $name = strtoupper($method);

            if (in_array($name, $names, true)) {
                throw new InvalidArgumentException('Duplicate HTTP method.');
            }

            $names[] = $name;
        }

        foreach (new Std()->parse($path) as $variant) {
            foreach ($variant as $part) {
                if (is_string($part) && (str_contains($part, '?') || str_contains($part, '#'))) {
                    throw new InvalidArgumentException('Literal route paths cannot contain a query or fragment.');
                }

                if (is_array($part) && str_starts_with($part[0], '_arix_')) {
                    throw new InvalidArgumentException('Route parameter names beginning with _arix_ are reserved.');
                }
            }
        }

        $route = new RouteDefinition($names, $path, $handler[0], $handler[1]);
        // Build before publishing so a conflict cannot leave partial registration.
        $this->collector([...$this->routes, $route])->getData();
        $this->routes[] = $route;

        return $route;
    }

    public function dispatcher(): Dispatcher
    {
        return $this->compiled ??= new CompiledDispatcher($this->collector($this->routes)->getData());
    }

    /** @return list<string> */
    public function methods(): array
    {
        $methods = [];

        foreach ($this->routes as $route) {
            $methods = [...$methods, ...$route->methods];
        }

        return array_values(array_unique($methods));
    }

    private function collector(array $routes): RouteCollector
    {
        $collector = new RouteCollector(new Std(), new GroupCountBased());

        foreach ($routes as $route) {
            $collector->addRoute($route->methods, $route->path, $route);
        }

        return $collector;
    }
}
