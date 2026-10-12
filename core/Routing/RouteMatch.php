<?php

declare(strict_types=1);

namespace Arix\Routing;

class RouteMatch
{
    /** @param array<string, string> $parameters @param list<string> $allowedMethods */
    public function __construct(
        public readonly RouteDefinition|null $route,
        public readonly array $parameters,
        public readonly array $allowedMethods,
    ) {}
}
