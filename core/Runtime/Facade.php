<?php

declare(strict_types=1);

namespace Arix\Runtime;

use Arix\Container\ContainerContext;
use BadMethodCallException;

abstract class Facade
{
    /** @param list<mixed> $arguments */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        $service = ContainerContext::current()->get(static::getKeyName());

        if (! method_exists($service, $method) || ! is_callable([$service, $method])) {
            throw new BadMethodCallException($service::class.' does not have public method '.$method);
        }

        return $service->{$method}(...$arguments);
    }

    abstract protected static function getKeyName(): string;
}
