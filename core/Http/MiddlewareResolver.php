<?php

declare(strict_types=1);

namespace Arix\Http;

use Arix\Container\Container;
use Arix\Contracts\Middleware;
use Arix\Support\Path;
use ReflectionClass;
use RuntimeException;

use function count;
use function in_array;
use function strlen;

class MiddlewareResolver
{
    public function __construct(private readonly string|null $directory = null) {}

    public function resolve(string $middleware, Container $container): Middleware
    {
        if ($container->has($middleware)) {
            $resolved = $container->get($middleware);

            if (! $resolved instanceof Middleware) {
                throw new RuntimeException("Middleware binding returned an incompatible instance: {$middleware}");
            }

            return $resolved;
        }

        $this->load($middleware);

        if (! class_exists($middleware, false)) {
            throw new RuntimeException("Middleware is not registered: {$middleware}");
        }

        $reflection = new ReflectionClass($middleware);
        $constructor = $reflection->getConstructor();

        if (! $reflection->isInstantiable() || ($constructor !== null && array_filter($constructor->getParameters(), static fn ($parameter): bool => ! $parameter->isOptional()) !== [])) {
            throw new RuntimeException("Middleware {$middleware} requires an explicit binding.");
        }

        $resolved = $reflection->newInstance();

        if (! $resolved instanceof Middleware) {
            throw new RuntimeException("Middleware class is incompatible: {$middleware}");
        }

        return $resolved;
    }

    private function load(string $middleware): void
    {
        if ($this->directory === null || ! str_starts_with($middleware, 'App\\Middleware\\')) {
            return;
        }

        $relative = substr($middleware, strlen('App\\Middleware\\'));

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $relative)) {
            throw new RuntimeException('Invalid middleware class.');
        }

        if (! is_dir($this->directory)) {
            throw new RuntimeException('Middleware file is missing, linked, or has incorrect case.');
        }

        if (! Path::hasExactCase($this->directory)) {
            throw new RuntimeException('Middleware directory must match its configured case exactly.');
        }

        $path = $this->directory;

        foreach (explode('\\', $relative) as $index => $segment) {
            $name = $index === count(explode('\\', $relative)) - 1 ? "{$segment}.php" : $segment;

            if (is_link($path) || ! in_array($name, scandir($path) ?: [], true)) {
                throw new RuntimeException('Middleware file is missing, linked, or has incorrect case.');
            }

            $path .= DIRECTORY_SEPARATOR . $name;
        }

        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException('Middleware file is missing or linked.');
        }

        require_once $path;
    }
}
