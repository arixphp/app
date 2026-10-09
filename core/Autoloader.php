<?php

declare(strict_types=1);

namespace Arix;

use function in_array;
use function strlen;

class Autoloader
{
    private static array $registered = [];

    public static function registerControllers(string $directory): void
    {
        $base = realpath($directory);
        if ($base === false || is_link($directory) || isset(self::$registered['controllers:' . $base])) {
            return;
        }
        self::$registered['controllers:' . $base] = true;
        spl_autoload_register(static function (string $class) use ($base): void {
            if (!str_starts_with($class, 'App\\Controllers\\')) {
                return;
            }
            $relative = substr($class, strlen('App\\Controllers\\'));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $relative)) {
                return;
            }
            $path = $base;
            $segments = explode('\\', $relative);
            $segments[array_key_last($segments)] .= '.php';
            foreach ($segments as $segment) {
                if (!is_dir($path) || !in_array($segment, scandir($path) ?: [], true)) {
                    return;
                }
                $path .= '/' . $segment;
                if (is_link($path)) {
                    return;
                }
            }
            $file = realpath($path);
            if ($file !== false && is_file($file) && str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
                require_once $file;
            }
        });
    }

}
