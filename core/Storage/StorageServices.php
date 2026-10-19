<?php

declare(strict_types=1);

namespace Arix\Storage;

use Arix\Container\Container;
use InvalidArgumentException;

use function is_dir;
use function mkdir;

class StorageServices
{
    public static function register(Container $container, string $root): void
    {
        if (! is_dir($root) && ! mkdir($root, recursive: true)) {
            throw new InvalidArgumentException('Storage root is missing.');
        }

        $container->instance(Storage::class, new Storage($root));
    }
}
