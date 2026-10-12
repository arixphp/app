<?php

declare(strict_types=1);

namespace Arix\Routing;

class RouteDefinition
{
    /** @param list<string> $methods */
    public function __construct(
        public readonly array $methods,
        public readonly string $path,
        public readonly string $controller,
        public readonly string $action,
    ) {}
}
