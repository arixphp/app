<?php

declare(strict_types=1);

namespace Arix\Routing;

use Arix\Config\ConfigServices;
use Arix\Container\Container;
use Arix\Container\ContainerContext;
use Arix\Support\ApplicationAutoloader;
use Arix\Support\RuntimePaths;
use LogicException;
use RuntimeException;

use function is_string;

class RouteResolver
{
    public static function fromRoot(string $root): RouteUrlGenerator
    {
        $paths = RuntimePaths::fromApplicationRoot($root);

        return ApplicationAutoloader::run($paths->root, static fn (): RouteUrlGenerator => self::load($paths));
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

        $basePath = $defaults->get('app.base_path', '');

        if (! is_string($basePath)) {
            throw new RuntimeException('Application base path is invalid.');
        }

        $registry = $container->get(RouteRegistry::class);

        if (! $registry instanceof RouteRegistry) {
            throw new RuntimeException('RouteRegistry binding returned an incompatible instance.');
        }

        return new RouteUrlGenerator($registry, new UrlGenerator($basePath, '/'));
    }

    public static function fromCwd(): RouteUrlGenerator
    {
        $cwd = getcwd();

        if (! is_string($cwd) || $cwd === '') {
            throw new LogicException('No active container.');
        }

        return self::fromRoot($cwd);
    }
}
