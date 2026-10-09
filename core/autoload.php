<?php

declare(strict_types=1);

// Project-specific PSR-4 mapping, following the PHP-FIG closure example.
// Keep configuration local: requiring this file must not overwrite caller variables.
(static function (): void {
    $prefix = 'Arix\\';
    $prefixLength = strlen($prefix);
    $baseDirectory = realpath(__DIR__);

    if ($baseDirectory === false) {
        return;
    }

    $baseDirectory .= DIRECTORY_SEPARATOR;

    spl_autoload_register(static function (string $class) use ($prefix, $prefixLength, $baseDirectory): void {
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relativeClass = substr($class, $prefixLength);

        if (!preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/D', $relativeClass)) {
            return;
        }


        $candidate = $baseDirectory . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
        $file = @realpath($candidate);

        if ($file === false || !str_starts_with($file, $baseDirectory) || !@is_file($file) || !@is_readable($file)) {
            return;
        }

        require_once $file;
    });
})();

require_once __DIR__ . '/functions.php';
