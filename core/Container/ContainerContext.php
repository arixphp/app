<?php

declare(strict_types=1);

namespace Arix\Container;

use LogicException;

class ContainerContext
{
    private static null|Container $current = null;

    public static function current(): Container
    {
        return self::$current ?? throw new LogicException('No active container.');
    }

    public static function run(Container $container, callable $callback): mixed
    {
        $previous = self::$current;
        self::$current = $container;

        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }
}
