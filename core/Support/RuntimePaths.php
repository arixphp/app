<?php

declare(strict_types=1);

namespace Arix\Support;

use RuntimeException;

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

    /** @param list<string> $segments */
    public function resolve(array $segments): string|null
    {
        self::validateSegments($segments);
        $path = rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);

        return file_exists($path) ? $path : null;
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
}
