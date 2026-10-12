<?php

declare(strict_types=1);

namespace Arix\Facades;

use Arix\Routing\RouteDefinition;
use Arix\Routing\RouteRegistry;
use Arix\Runtime\Facade;

/**
 * @method static RouteDefinition get(string $path, array $handler)
 * @method static RouteDefinition head(string $path, array $handler)
 * @method static RouteDefinition post(string $path, array $handler)
 * @method static RouteDefinition put(string $path, array $handler)
 * @method static RouteDefinition patch(string $path, array $handler)
 * @method static RouteDefinition delete(string $path, array $handler)
 * @method static RouteDefinition options(string $path, array $handler)
 * @method static RouteDefinition query(string $path, array $handler)
 * @method static RouteDefinition match(array $methods, string $path, array $handler)
 */
class Route extends Facade
{
    protected static function getKeyName(): string
    {
        return RouteRegistry::class;
    }
}
