<?php

declare(strict_types=1);

namespace Arix\Markdown;

use RuntimeException;

use function in_array;
use function is_array;

class DocumentReader
{
    public function __construct(private readonly string $directory) {}

    public function read(string $name): string
    {
        if (! preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\.md$~D', $name)) {
            throw new RuntimeException('Invalid Markdown document name.');
        }

        $root = realpath($this->directory);

        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException('Content directory is missing.');
        }

        $path = $root;

        foreach (explode('/', $name) as $segment) {
            $entries = is_dir($path) ? scandir($path) : false;

            if (! is_array($entries) || ! in_array($segment, $entries, true)) {
                throw new RuntimeException('Markdown document is missing.');
            }

            $path .= DIRECTORY_SEPARATOR . $segment;
        }

        $file = realpath($path);

        if ($file === false || ! str_starts_with($file, $root . DIRECTORY_SEPARATOR) || ! is_file($file) || ! is_readable($file)) {
            throw new RuntimeException('Markdown document is not readable.');
        }

        $content = file_get_contents($file);

        if ($content === false) {
            throw new RuntimeException('Cannot read Markdown document.');
        }

        return $content;
    }
}
