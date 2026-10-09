<?php

declare(strict_types=1);

namespace Arix\Container;

use LogicException;

/** Active scope for the synchronous PHP request lifecycle; not an async runtime. */
final class ContainerContext
{
    private static ?Container $current = null;

    public static function current(): Container
    {
        return self::$current ?? throw new LogicException('No active container. Call helpers inside a request or ContainerContext::run().');
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
