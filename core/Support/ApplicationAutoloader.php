<?php

declare(strict_types=1);

namespace Arix\Support;

use Closure;
use RuntimeException;

use function in_array;

class ApplicationAutoloader
{
    private static Closure|null $loader = null;

    private static string|null $root = null;

    public static function register(string $root): void
    {
        self::$root = RuntimePaths::fromApplicationRoot($root)->root;

        if (self::$loader === null) {
            self::$loader = self::load(...);

            spl_autoload_register(self::$loader, prepend: true);
        }
    }

    public static function run(string $root, Closure $callback): mixed
    {
        $previous = self::$root;
        $registered = self::$loader !== null;
        self::register($root);

        try {
            return $callback();
        } finally {
            self::$root = $previous;

            if (! $registered) {
                spl_autoload_unregister(self::$loader);
                self::$loader = null;
            }
        }
    }

    private static function load(string $class): void
    {
        if (self::$root === null || ! str_starts_with($class, 'App\\')) {
            return;
        }

        $relative = substr($class, 4);

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $relative)) {
            return;
        }

        $segments = explode('\\', $relative);

        if (self::isResource($segments[0])) {
            return;
        }

        $segments[array_key_last($segments)] .= '.php';
        $path = self::classFile($segments);

        if ($path !== null) {
            require_once $path;
        }
    }

    private static function isResource(string $segment): bool
    {
        return in_array($segment, ['config', 'lang', 'views', 'bootstrap', 'routes', 'services', 'schedules'], true);
    }

    /** @param list<string> $segments */
    private static function classFile(array $segments): string|null
    {
        try {
            $path = RuntimePaths::fromStrictRoot(self::$root)->resolve(['app', ...$segments]);
        } catch (RuntimeException) {
            return null;
        }

        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        return $path;
    }
}
