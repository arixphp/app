<?php

declare(strict_types=1);

namespace Arix\Support;

use RuntimeException;

use function in_array;
use function is_dir;
use function is_string;
use function mkdir;
use function realpath;
use function str_starts_with;

/**
 * Validates paths, not their content or provenance. Code directories must be
 * protected from unauthorized writes; check-and-include is not atomic.
 */
class RuntimePaths
{
    private function __construct(public readonly string $root) {}

    public static function fromApplicationRoot(string $root): self
    {
        if ($root === '' || str_contains($root, "\0") || str_contains($root, '://')) {
            throw new RuntimeException('Invalid application runtime root.');
        }

        clearstatcache(true, $root);
        $real = realpath($root);

        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('Application runtime root is missing or unreadable.');
        }

        return new self($real);
    }

    public static function fromStrictRoot(string $root): self
    {
        $root = self::withoutTrailingSeparators($root);
        $ancestor = $root;

        while (true) {
            clearstatcache(true, $ancestor);

            if (Path::isLink($ancestor)) {
                throw new RuntimeException('Runtime directory is linked.');
            }

            $parent = dirname($ancestor);

            if ($parent === $ancestor) {
                break;
            }

            $name = basename($ancestor);

            if ($name !== '.' && $name !== '..' && (! is_dir($parent) || ! in_array($name, @scandir($parent) ?: [], true))) {
                throw new RuntimeException('Runtime directory is missing or has incorrect case.');
            }

            $ancestor = $parent;
        }

        return self::fromApplicationRoot($root);
    }

    public static function fromOptionalStrictRoot(string $root): self|null
    {

        if ($root === '' || str_contains($root, "\0") || str_contains($root, '://')) {
            throw new RuntimeException('Invalid optional runtime root.');
        }

        $ancestor = self::withoutTrailingSeparators($root);
        $segments = [];

        while (true) {
            clearstatcache(true, $ancestor);

            if (file_exists($ancestor) || Path::isLink($ancestor)) {
                break;
            }

            $parent = dirname($ancestor);

            if ($parent === $ancestor) {
                throw new RuntimeException('Optional runtime root cannot be inspected.');
            }

            array_unshift($segments, basename($ancestor));
            $ancestor = $parent;
        }

        $paths = self::fromStrictRoot($ancestor);

        if ($segments === []) {

            return $paths;
        }

        $directory = $paths->resolve($segments);

        return $directory === null ? null : self::fromStrictRoot($directory);
    }

    private static function withoutTrailingSeparators(string $root): string
    {
        $trimmed = rtrim($root, PHP_OS_FAMILY === 'Windows' ? '/\\' : DIRECTORY_SEPARATOR);

        if ($trimmed === '' && $root !== '') {

            return DIRECTORY_SEPARATOR;
        }

        if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:$/D', $trimmed) && $trimmed !== $root) {

            return $trimmed . DIRECTORY_SEPARATOR;
        }

        return $trimmed;
    }

    /** @param list<string> $segments */
    public function resolve(array $segments): string|null
    {
        self::validateSegments($segments);
        clearstatcache(true, $this->root);

        if (Path::isLink($this->root) || realpath($this->root) !== $this->root) {
            throw new RuntimeException('Application runtime root changed.');
        }

        $path = $this->root;

        foreach ($segments as $segment) {
            $path = $this->child($path, $segment);

            if ($path === null) {

                return null;
            }
        }

        return $path;
    }

    /**
     * The framework-owned storage directory of the application.
     */
    public function storageDirectory(): string
    {
        return $this->ensureDirectory(['storage']);
    }

    /**
     * The framework-owned directory of compiled templates.
     *
     * It is never configurable: compiled templates are PHP files and must
     * stay outside every directory the web server publishes. The directory
     * is not created here; an unusable cache must degrade when a template is
     * rendered, not break every request.
     */
    public function cacheDirectory(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
    }

    /**
     * The framework-owned log directory of the scheduler.
     */
    public function scheduleLogDirectory(): string
    {
        return $this->ensureDirectory(['storage', 'logs']);
    }

    /**
     * The framework-owned log file of the scheduler.
     *
     * It is never configurable: the scheduler appends to it and must not be
     * able to write into a served directory.
     */
    public function scheduleLogPath(): string
    {
        return $this->scheduleLogDirectory() . DIRECTORY_SEPARATOR . 'cron.log';
    }

    /**
     * Resolve or create a framework-owned directory inside the application root.
     *
     * @param  list<string>  $segments
     */
    public function ensureDirectory(array $segments): string
    {
        self::validateSegments($segments);
        clearstatcache(true, $this->root);

        if (Path::isLink($this->root) || realpath($this->root) !== $this->root) {
            throw new RuntimeException('Application runtime root changed.');
        }

        $path = $this->root;

        foreach ($segments as $segment) {
            $path .= DIRECTORY_SEPARATOR . $segment;
            clearstatcache(true, $path);

            if (Path::isLink($path)) {
                throw new RuntimeException("Runtime directory is linked: {$path}");
            }

            if (! is_dir($path) && ! @mkdir($path, 0755, true) && ! is_dir($path)) {
                throw new RuntimeException("Cannot create runtime directory: {$path}");
            }

            $real = realpath($path);

            if ($real === false || $real !== $path || ! str_starts_with($real, $this->root . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException("Runtime directory is unreadable, redirected or outside its root: {$path}");
            }
        }

        return $path;
    }

    /** @param list<string> $segments */
    private static function validateSegments(array $segments): void
    {
        if ($segments === [] || ! array_is_list($segments)) {
            throw new RuntimeException('Runtime paths require a nonempty list of segments.');
        }

        foreach ($segments as $segment) {
            if (! is_string($segment) || $segment === '' || $segment === '.' || $segment === '..'
                || preg_match('~[\\x00-\\x1F\\x7F/\\\\\\\\:]~', $segment)) {
                throw new RuntimeException('Invalid runtime path segment.');
            }
        }
    }

    private function child(string $parent, string $segment): string|null
    {
        if (! is_dir($parent) || ! is_readable($parent)) {
            throw new RuntimeException('Runtime path directory cannot be inspected.');
        }

        $entries = @scandir($parent);

        if ($entries === false) {
            throw new RuntimeException('Runtime path directory cannot be inspected.');
        }

        if (! in_array($segment, $entries, true)) {

            return null;
        }

        $path = rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $segment;
        clearstatcache(true, $path);

        if (Path::isLink($path)) {
            throw new RuntimeException('Runtime paths are linked.');
        }

        $real = realpath($path);
        $prefix = rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if ($real === false || $real !== $path || ! str_starts_with($real, $prefix)) {
            throw new RuntimeException('Runtime path is unreadable, redirected or outside its root.');
        }

        return $real;
    }
}
