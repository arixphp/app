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
use function preg_match;
use function restore_error_handler;
use function set_error_handler;
use function str_contains;
use function str_starts_with;

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

                if (! is_array($part)) {
                    continue;
                }

                if (str_starts_with($part[0], '_arix_')) {
                    throw new InvalidArgumentException('Route parameter names beginning with _arix_ are reserved.');
                }

                self::assertConstraint($part[1]);
            }
        }

        $route = new RouteDefinition($names, $path, $handler[0], $handler[1]);
        // Build before publishing so a conflict cannot leave partial registration.
        $this->collector([...$this->routes, $route])->getData();
        $this->routes[] = $route;

        return $route;
    }

    /**
     * A constraint is compiled into the route pattern of the router, so it
     * must be a valid pattern without capturing groups.
     */
    private static function assertConstraint(string $constraint): void
    {
        if ($constraint === '') {
            throw new InvalidArgumentException('Route parameter constraints cannot be empty.');
        }

        if (str_contains($constraint, '~')) {
            throw new InvalidArgumentException('Route parameter constraints cannot contain the pattern delimiter.');
        }

        // Every group would shift the position of the captured values.
        if (preg_match('~\((?!\?(?:[:=!]|<[=!]))~', $constraint) === 1) {
            throw new InvalidArgumentException('Route parameter constraints cannot contain capturing groups.');
        }

        if (! self::isValidPattern('~^(?:' . $constraint . ')$~D')) {
            throw new InvalidArgumentException('Route parameter constraints must be a valid pattern.');
        }
    }

    /** Compile a pattern without letting an invalid one reach the router. */
    private static function isValidPattern(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    public function dispatcher(): Dispatcher
    {
        if ($this->compiled === null) {
            $this->assertUniqueNames($this->routes);

            foreach ($this->routes as $route) {
                $route->lock();
            }

            $this->compiled = new CompiledDispatcher($this->collector($this->routes)->getData());
        }

        return $this->compiled;
    }

    public function named(string $name): RouteDefinition|null
    {
        foreach ($this->routes as $route) {
            if ($route->name === $name) {

                return $route;
            }
        }

        return null;
    }

    public function has(string $name): bool
    {
        return $this->named($name) !== null;
    }

    public function ensureUniqueNames(): void
    {
        $this->assertUniqueNames($this->routes);
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

    /** @param list<RouteDefinition> $routes */
    private function assertUniqueNames(array $routes): void
    {
        $seen = [];

        foreach ($routes as $route) {
            if ($route->name === null) {
                continue;
            }

            if (isset($seen[$route->name])) {
                throw new LogicException("Duplicate route name: {$route->name}");
            }

            $seen[$route->name] = true;
        }
    }
}
