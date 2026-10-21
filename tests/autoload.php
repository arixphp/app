<?php

declare(strict_types=1);

namespace Tests;

use function in_array;

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php';

spl_autoload_register(static function (string $class): void {
    if (! str_starts_with($class, 'Tests\\')) {

        return;
    }

    $relative = substr($class, 6);

    if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $relative)) {

        return;
    }

    $segments = explode('\\', $relative);
    $segments[array_key_last($segments)] .= '.php';
    $path = __DIR__;

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
