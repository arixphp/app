<?php

declare(strict_types=1);

namespace Arix\Storage;

use Arix\Container\Container;

class StorageServices
{
    public static function register(Container $container, string $root): void
    {
        $container->instance(Storage::class, new Storage($root));
    }
}
