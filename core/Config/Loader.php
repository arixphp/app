<?php

declare(strict_types=1);

namespace Arix\Config;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

use function is_array;

class Loader
{
    public function load(string|null $directory = null): Repository
    {
        $directory ??= dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config';

        if (! file_exists($directory)) {

            return new Repository([]);
        }

        if (! is_dir($directory) || ! is_readable($directory)) {
            throw new RuntimeException("Configuration directory is not readable: {$directory}");
        }

        $files = scandir($directory);

        if ($files === false) {
            throw new RuntimeException("Cannot list configuration directory: {$directory}");
        }

        $values = [];

        foreach ($files as $name) {
            $file = $directory . DIRECTORY_SEPARATOR . $name;

            if (! str_ends_with($name, '.php') || ! is_file($file)) {
                continue;
            }

            $key = substr($name, 0, -4);

            if ($key === '' || str_contains($key, '.')) {
                throw new InvalidArgumentException("Invalid configuration filename: {$file}");
            }

            if (! is_readable($file)) {
                throw new RuntimeException("Configuration file is not readable: {$file}");
            }

            try {
                $configuration = (static function (string $file): mixed {

                    return require $file;
                })($file);
            } catch (Throwable $exception) {
                throw new InvalidArgumentException("Cannot load configuration file: {$file}", previous: $exception);
            }

            if (! is_array($configuration)) {
                throw new InvalidArgumentException("Configuration file must return an array: {$file}");
            }

            Repository::validate($configuration, $file);
            $values[$key] = $configuration;
        }

        return new Repository($values);
    }
}
