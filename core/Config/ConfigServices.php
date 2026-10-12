<?php

declare(strict_types=1);

namespace Arix\Config;

use Arix\Container\Container;
use Arix\Container\ContainerContext;

class ConfigServices
{
    public static function register(Container $container, string|null $directory = null): Repository
    {
        $container->singleton(Loader::class, static fn (): Loader => new Loader());
        $repository = ContainerContext::run($container, static fn (): Repository => $container->get(Loader::class)->load($directory));
        $container->instance(Repository::class, $repository);

        return $repository;
    }
}
