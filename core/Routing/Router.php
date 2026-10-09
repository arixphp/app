<?php

declare(strict_types=1);

namespace Arix\Routing;

use Arix\Http\HttpException;

final class Router
{
    public function __construct(private readonly RouteCollector $collector)
    {
    }

    public function resolve(string $method, string $path): RouteDefinition
    {
        $get = null;
        foreach ($this->collector->routes() as $route) {
            if ($route->path !== $path) {
                continue;
            }
            if (in_array($method, $route->methods, true)) {
                return $route;
            }
            if (in_array('GET', $route->methods, true)) {
                $get = $route;
            }
        }
        if ($method === 'HEAD' && $get !== null) {
            return $get;
        }
        $allowed = $this->allowedMethods($path);
        if ($allowed === []) {
            throw new HttpException(404, 'Route not found.');
        }

        throw new HttpException(405, 'Method not allowed.', ['Allow' => implode(', ', $allowed)]);
    }

    /** @return list<string> */
    public function allowedMethods(string $path): array
    {
        $methods = [];
        foreach ($this->collector->routes() as $route) {
            if ($route->path === $path) {
                $methods = [...$methods, ...$route->methods];
            }
        }
        if ($methods === []) {
            return [];
        }
        if (in_array('GET', $methods, true)) {
            $methods[] = 'HEAD';
        }
        $methods[] = 'OPTIONS';
        $methods = array_values(array_unique($methods));
        $order = array_flip(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'QUERY']);
        usort($methods, static fn (string $a, string $b): int => (($order[$a] ?? 99) <=> ($order[$b] ?? 99)) ?: strcmp($a, $b));

        return $methods;
    }
}
