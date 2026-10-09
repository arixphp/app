<?php

declare(strict_types=1);

namespace Arix\Cache;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

use function strlen;

class FileCache
{
    private ?string $directory = null;
    private bool $warned = false;

    public function __construct(string $root, array $config)
    {
        if (!($config['enabled'] ?? true)) {
            return;
        }
        $base = $config['path'] ?? sys_get_temp_dir();
        $directory = rtrim($base, '/\\') . '/arix-' . substr(hash('sha256', $root), 0, 24);
        try {
            if ((!is_dir($directory) && !@mkdir($directory, 0700, true)) || is_link($directory) || !is_writable($directory)) {
                $this->warn();
                return;
            }
            @chmod($directory, 0700);
            $permissions = @fileperms($directory);
            if ($permissions === false || (PHP_OS_FAMILY !== 'Windows' && ($permissions & 0077) !== 0)) {
                $this->warn();
                return;
            }
            if (function_exists('posix_geteuid') && @fileowner($directory) !== posix_geteuid()) {
                $this->warn();
                return;
            }
            $this->directory = $directory;
        } catch (Throwable) {
            $this->warn();
        }
    }

    public function read(string $key): ?string
    {
        if ($this->directory === null) {
            return null;
        }
        $path = $this->path($key);
        $content = is_file($path) && !is_link($path) ? @file_get_contents($path) : false;

        return $content === false ? null : $content;
    }

    public function write(string $key, string $content): ?string
    {
        if ($this->directory === null) {
            return null;
        }
        $temporary = @tempnam($this->directory, 'write-');
        if ($temporary === false) {
            $this->warn();
            return null;
        }
        try {
            if (@file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) {
                $this->warn();
                return null;
            }
            @chmod($temporary, 0600);
            if (!@rename($temporary, $this->path($key))) {
                $this->warn();
                return null;
            }

            return $this->path($key);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function existingPath(string $key): ?string
    {
        return $this->directory !== null && is_file($this->path($key)) && !is_link($this->path($key)) ? $this->path($key) : null;
    }

    public function clear(): void
    {
        if ($this->directory === null) {
            return;
        }
        foreach (glob($this->directory . '/*.php') ?: [] as $file) {
            if (!is_link($file)) {
                @unlink($file);
            }
        }
    }

    public static function fingerprint(array $directories): string
    {
        $files = [];
        foreach ($directories as $directory) {
            if (is_file($directory) && !is_link($directory)) {
                $files[$directory] = hash_file('sha256', $directory);
                continue;
            }
            if (!is_dir($directory)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && !$file->isLink()) {
                    $files[$file->getPathname()] = hash_file('sha256', $file->getPathname());
                }
            }
        }
        ksort($files);

        return hash('sha256', serialize($files));
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.php';
    }

    private function warn(): void
    {
        if (!$this->warned) {
            error_log('Arix: cache unavailable; rendering without persistent cache.');
            $this->warned = true;
        }
    }
}
