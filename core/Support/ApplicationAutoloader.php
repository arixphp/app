<?php

declare(strict_types=1);

namespace Arix\Support;

use Closure;
use RuntimeException;

use function in_array;

/**
 * Synchronous application scope; PHP declarations remain process-global once loaded.
 */
class ApplicationAutoloader
{
    private static Closure|null $loader = null;

    private static string|null $root = null;

    public static function register(string $root): void
    {
        $ancestor = $root;

        while (true) {

            if (is_link($ancestor)) {
                throw new RuntimeException('Application autoload root is linked.');
            }

            $parent = dirname($ancestor);

            if ($parent === $ancestor) {
                break;
            }

            $ancestor = $parent;
        }

        $base = realpath($root);

        if ($base === false || ! is_dir($base)) {
            throw new RuntimeException('Application autoload root is missing.');
        }

        self::$root = $base;

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
        $path = self::$root;

        // Check the app directory too: realpath alone would hide a linked ancestor.
        foreach (['app', ...$segments] as $segment) {

            if (is_link($path) || ! is_dir($path) || ! in_array($segment, scandir($path) ?: [], true)) {

                return null;
            }

            $path .= DIRECTORY_SEPARATOR . $segment;
        }

        if (is_link($path) || ! is_file($path) || ! is_readable($path)) {

            return null;
        }

        return $path;
    }
}
