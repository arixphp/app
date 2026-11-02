<?php

declare(strict_types=1);

namespace Arix\Routing;

use Arix\Config\ConfigServices;
use Arix\Container\Container;
use Arix\Container\ContainerContext;
use Arix\Runtime\Environment;
use Arix\Support\RuntimePaths;
use LogicException;
use RuntimeException;

class RouteResolver
{
    public static function fromRoot(string $root): RouteUrlGenerator
    {
        $paths = RuntimePaths::fromApplicationRoot($root);

        Environment::load($paths->root);

        return self::load($paths);
    }

    private static function load(RuntimePaths $paths): RouteUrlGenerator
    {
        $configDirectory = $paths->resolve(['app', 'config']);
        $routesFile = $paths->resolve(['app', 'routes.php']);

        if ($configDirectory === null || ! is_dir($configDirectory)) {
            throw new LogicException('No active container.');
        }

        if ($routesFile === null || ! is_file($routesFile) || ! is_readable($routesFile)) {
            throw new LogicException('No active container.');
        }

        $container = new Container();
        $defaults = ConfigServices::register($container, $configDirectory);
        $container->instance(RouteRegistry::class, new RouteRegistry());

        ContainerContext::run($container, static function () use ($routesFile): void {
            require $routesFile;
        });

        $url = ApplicationUrl::fromConfiguration($defaults->get('app', []));

        $registry = $container->get(RouteRegistry::class);

        if (! $registry instanceof RouteRegistry) {
            throw new RuntimeException('RouteRegistry binding returned an incompatible instance.');
        }

        return new RouteUrlGenerator($registry, UrlGenerator::fromApplicationUrl($url->url));
    }
}
