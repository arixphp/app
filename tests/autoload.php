<?php

declare(strict_types=1);

namespace Tests;

use function in_array;

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php';

spl_autoload_register(static function (string $class): void {
    $root = dirname(__DIR__);

    if (str_starts_with($class, 'App\\Controllers\\')) {
        $base = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'controllers';
        $relative = substr($class, 16);
    } elseif (str_starts_with($class, 'App\\')) {
        $base = $root . DIRECTORY_SEPARATOR . 'app';
        $relative = substr($class, 4);

        if (strtolower(explode('\\', $relative)[0]) === 'controllers') {

            return;
        }
    } elseif (str_starts_with($class, 'Tests\\')) {
        $base = __DIR__;
        $relative = substr($class, 6);
    } else {

        return;
    }

    if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $relative)) {

        return;
    }

    $segments = explode('\\', $relative);
    $segments[array_key_last($segments)] .= '.php';
    $path = $base;

    foreach ($segments as $segment) {
        if (! is_dir($path) || is_link($path) || ! in_array($segment, scandir($path) ?: [], true)) {

            return;
        }

        $path .= DIRECTORY_SEPARATOR . $segment;

        if (is_link($path)) {

            return;
        }
    }

    if (is_file($path)) {
        require_once $path;
    }
});
