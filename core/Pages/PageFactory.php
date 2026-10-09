<?php

declare(strict_types=1);

namespace Arix\Pages;

use Arix\Pages\Contracts\Page;
use Arix\Routing\PageContext;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

final class PageFactory
{
    public static function reflect(string $class, string $file): ReflectionClass
    {
        require_once $file;
        if (!class_exists($class, false)) {
            throw new RuntimeException('Page file must declare ' . $class);
        }
        $reflection = new ReflectionClass($class);
        if (realpath((string) $reflection->getFileName()) !== realpath($file)) {
            throw new RuntimeException('Page class belongs to a different application: ' . $class);
        }

        return $reflection;
    }

    public static function make(string $class, string $file, PageContext $context): Page
    {
        $reflection = self::reflect($class, $file);
        if (!$reflection->isInstantiable() || !$reflection->implementsInterface(Page::class)) {
            throw new RuntimeException('Invalid page class: ' . $class);
        }
        $constructor = $reflection->getConstructor();
        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return $reflection->newInstance();
        }
        $parameters = $constructor->getParameters();
        $type = $parameters[0]->getType();
        if (count($parameters) !== 1 || !$type instanceof ReflectionNamedType || $type->getName() !== PageContext::class || $parameters[0]->isVariadic()) {
            throw new RuntimeException('Page constructor must accept only PageContext: ' . $class);
        }

        return $reflection->newInstance($context);
    }
}
