<?php

declare(strict_types=1);

namespace Arix\Views;

use Closure;
use RuntimeException;

use function strlen;

class CompiledTemplateCache
{
    private readonly string $directory;

    public function __construct(string $directory, private readonly string $version)
    {
        $ancestor = $directory;

        while (true) {
            if (is_link($ancestor)) {
                throw new RuntimeException('Compiled template directory is linked.');
            }

            $parent = dirname($ancestor);

            if ($parent === $ancestor) {
                break;
            }

            $ancestor = $parent;
        }

        $real = realpath($directory);

        if ($real === false || ! is_dir($real) || ! is_writable($real)) {
            throw new RuntimeException('Compiled template directory is missing or unwritable.');
        }

        $this->directory = $real;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function path(string $source, string $code): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.hash('sha256', $this->version."\0".$source."\0".$code).'.php';
    }

    public function remember(string $source, string $code, Closure $compile): string
    {
        $path = $this->path($source, $code);

        if (is_file($path)) {
            return $path;
        }

        $this->write($path, $compile());

        return $path;
    }

    public function write(string $path, string $compiled): void
    {
        if (dirname($path) !== $this->directory || ! preg_match('/^[0-9a-f]{64}\.php$/D', basename($path))) {
            throw new RuntimeException('Compiled template path is outside its cache.');
        }

        // tempnam creates private files; rename publishes atomically so
        // concurrent requests never execute partially written templates.
        $temporary = tempnam($this->directory, 'compile-');

        if ($temporary === false) {
            throw new RuntimeException('Cannot write compiled template.');
        }

        try {
            if (file_put_contents($temporary, $compiled) !== strlen($compiled)) {
                throw new RuntimeException('Cannot write compiled template.');
            }

            if (! rename($temporary, $path)) {
                throw new RuntimeException('Cannot write compiled template.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
