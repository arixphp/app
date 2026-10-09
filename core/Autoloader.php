<?php

declare(strict_types=1);

namespace Arix;

final class Autoloader
{
    private static array $registered = [];

    public static function registerPages(string $directory): void
    {
        $base = realpath($directory);
        if ($base === false || isset(self::$registered[$base])) {
            return;
        }
        self::$registered[$base] = true;
        spl_autoload_register(static function (string $class) use ($base): void {
            if (!str_starts_with($class, 'App\\Pages\\')) {
                return;
            }
            $relative = substr($class, strlen('App\\Pages\\'));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $relative)) {
                return;
            }
            $file = realpath($base . '/' . str_replace('\\', '/', $relative) . '.php');
            if ($file !== false && is_file($file) && str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
                require_once $file;
            }
        });
    }
}
