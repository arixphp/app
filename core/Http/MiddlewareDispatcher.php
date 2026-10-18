<?php

declare(strict_types=1);

namespace Arix\Http;

use Arix\Container\Container;
use Arix\HttpFoundation\Request;
use Arix\HttpFoundation\Response;
use Closure;

class MiddlewareDispatcher
{
    public function __construct(private readonly MiddlewareResolver $resolver = new MiddlewareResolver()) {}

    public function dispatch(Request $request, Container $container, array $middleware, Closure $terminal): Response
    {
        $resolver = $container->has(MiddlewareResolver::class)
            ? $container->get(MiddlewareResolver::class)
            : $this->resolver;
        $next = $terminal;

        foreach (array_reverse($middleware) as $name) {
            $current = $resolver->resolve($name, $container);
            $previous = $next;
            $next = static fn (Request $request): Response => $current->handle($request, $previous);
        }

        return $next($request);
    }
}
