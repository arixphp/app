<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80401) {
    throw new RuntimeException('Arix core requires PHP >= 8.4.1.');
}

if (! extension_loaded('mbstring')) {
    throw new RuntimeException('Arix core requires ext-mbstring.');
}

spl_autoload_register(static function (string $class): void {
    if (! str_starts_with($class, 'Arix\\')) {
        return;
    }

    $relative = substr($class, 5);

    if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $relative)) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/functions.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'FastRoute' . DIRECTORY_SEPARATOR . 'functions.php';
