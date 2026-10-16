<?php

declare(strict_types=1);

namespace Arix\Routing;

use Arix\Constants\HttpMethod;
use Arix\Constants\HttpStatusCode;
use Arix\FastRoute\Dispatcher;
use Arix\Http\HttpException;

use function in_array;

class Router
{
    public function __construct(private readonly RouteRegistry $registry) {}

    public function resolve(string $method, string $path): RouteMatch
    {
        $dispatcher = $this->registry->dispatcher();
        $result = $dispatcher->dispatch($method, $path);

        if ($result[0] === Dispatcher::FOUND) {
            return new RouteMatch($result[1], $result[2], []);
        }

        $allowed = $this->allowedMethods($path);

        if ($allowed === []) {
            throw new HttpException(HttpStatusCode::NOT_FOUND, 'Route not found.');
        }

        if ($method === HttpMethod::OPTIONS->value) {
            return new RouteMatch(null, [], $allowed);
        }

        throw new HttpException(HttpStatusCode::METHOD_NOT_ALLOWED, 'Method not allowed.', ['Allow' => implode(', ', $allowed)]);
    }

    private function allowedMethods(string $path): array
    {
        $methods = [];
        $dispatcher = $this->registry->dispatcher();

        foreach ($this->registry->methods() as $method) {
            if ($dispatcher->dispatch($method, $path)[0] === Dispatcher::FOUND) {
                $methods[] = $method;
            }
        }

        if ($methods === []) {
            return [];
        }

        if (in_array(HttpMethod::GET->value, $methods, true)) {
            $methods[] = HttpMethod::HEAD->value;
        }

        $methods[] = HttpMethod::OPTIONS->value;
        $methods = array_values(array_unique($methods));
        $order = array_flip([
            HttpMethod::GET->value,
            HttpMethod::HEAD->value,
            HttpMethod::POST->value,
            HttpMethod::PUT->value,
            HttpMethod::PATCH->value,
            HttpMethod::DELETE->value,
            HttpMethod::OPTIONS->value,
            HttpMethod::QUERY->value,
        ]);

        usort($methods, static fn (string $a, string $b): int => (($order[$a] ?? 99) <=> ($order[$b] ?? 99)) ?: strcmp($a, $b));

        return $methods;
    }
}
