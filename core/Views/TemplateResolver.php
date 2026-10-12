<?php

declare(strict_types=1);

namespace Arix\Views;

use RuntimeException;

use function count;
use function in_array;
use function is_array;

class TemplateResolver
{
    public function __construct(private readonly string $directory) {}

    public function resolve(string $name): string
    {
        if (! preg_match('/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$/D', $name)) {
            throw new RuntimeException("Invalid template name: {$name}");
        }

        $ancestor = $this->directory;

        while (true) {
            if (is_link($ancestor)) {
                throw new RuntimeException('Template directory is linked.');
            }

            $parent = dirname($ancestor);

            if ($parent === $ancestor) {
                break;
            }

            $ancestor = $parent;
        }

        $base = realpath($this->directory);

        if ($base === false || ! is_dir($base)) {
            throw new RuntimeException('Template directory is missing.');
        }

        $segments = explode('.', $name);
        $leaf = array_pop($segments);
        $path = $base;

        foreach ($segments as $segment) {
            $path = $this->child($path, $segment);

            if (! is_dir($path)) {
                throw new RuntimeException("Missing template: {$name}");
            }
        }

        $entries = scandir($path);

        if (! is_array($entries)) {
            throw new RuntimeException("Missing template: {$name}");
        }

        $matches = [];

        foreach (['.arix.php', '.php'] as $extension) {
            $filename = $leaf.$extension;

            if (! in_array($filename, $entries, true)) {
                continue;
            }

            $candidate = $path.DIRECTORY_SEPARATOR.$filename;

            if (is_link($candidate)) {
                throw new RuntimeException("Template symlinks are not supported: {$name}");
            }

            $file = realpath($candidate);

            if ($file !== false && is_file($file) && str_starts_with($file, $base.DIRECTORY_SEPARATOR)) {
                $matches[] = $file;
            }
        }

        if (count($matches) !== 1) {
            throw new RuntimeException("Missing or conflicting template: {$name}");
        }

        return $matches[0];
    }

    private function child(string $path, string $segment): string
    {
        if (! is_dir($path) || ! is_array($entries = scandir($path)) || ! in_array($segment, $entries, true)) {
            throw new RuntimeException("Missing template segment: {$segment}");
        }

        $child = $path.DIRECTORY_SEPARATOR.$segment;

        if (is_link($child)) {
            throw new RuntimeException("Template symlinks are not supported: {$segment}");
        }

        return $child;
    }
}
