<?php

declare(strict_types=1);

namespace Arix\Views;

use Closure;
use RuntimeException;

use function strlen;

class TemplateCacheMaintenance
{
    private readonly string $directory;

    private readonly Closure $clock;

    public function __construct(string $directory, private readonly TemplateCachePolicy $policy, Closure|null $clock = null)
    {
        $this->directory = (new CompiledTemplateCache($directory, ''))->directory();
        $this->clock = $clock ?? time(...);
    }

    public function run(Closure $render): mixed
    {
        $handle = $this->openLock();

        try {
            $this->share($handle);
            $now = ($this->clock)();

            if ($this->policy->cleanup && $this->due($handle, $now)) {
                // Never wait for an exclusive lock while retaining a shared one.
                flock($handle, LOCK_UN);

                $shouldRecord = $this->acquireLock($handle, LOCK_EX | LOCK_NB)
                    && $this->due($handle, $now)
                    && $this->collect($now);

                if ($shouldRecord) {
                    $this->record($handle, $now);
                }

                $this->share($handle);
            }

            return $render();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param resource $handle */
    protected function acquireLock(mixed $handle, int $operation): bool
    {

        return @flock($handle, $operation);
    }

    /** @return resource|false */
    protected function openDirectory(): mixed
    {

        return @opendir($this->directory);
    }

    protected function deleteCompiled(string $path): bool
    {

        return @unlink($path);
    }

    /** @return resource */
    private function openLock(): mixed
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . '.templates.lock';
        clearstatcache(true, $path);
        $before = file_exists($path) || is_link($path) ? @lstat($path) : false;

        if ($before !== false && ! self::regular($before)) {
            throw new RuntimeException('Template cache lock must be a regular, unlinked file.');
        }

        $handle = @fopen($path, 'c+b');

        if ($handle === false) {
            throw new RuntimeException('Cannot open template cache lock.');
        }

        clearstatcache(true, $path);
        $opened = fstat($handle);
        $current = @lstat($path);

        if ($opened === false || $current === false
            || ! self::regular($opened) || ! self::regular($current)
            || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']
            || ($before !== false && ($before['dev'] !== $opened['dev'] || $before['ino'] !== $opened['ino']))
        ) {
            fclose($handle);
            throw new RuntimeException('Template cache lock changed while opening.');
        }

        return $handle;
    }

    private static function regular(array $stat): bool
    {

        return ($stat['mode'] & 0170000) === 0100000 && $stat['nlink'] === 1;
    }

    /** @param resource $handle */
    private function share(mixed $handle): void
    {

        if (! $this->acquireLock($handle, LOCK_SH)) {
            throw new RuntimeException('Cannot acquire template cache render lock.');
        }

    }

    /** @param resource $handle */
    private function due(mixed $handle, int $now): bool
    {
        rewind($handle);
        $state = @fread($handle, 64);
        $last = $state === false || strlen($state) > 32
            ? false
            : filter_var(trim($state), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $last === false || $last > $now || $now - $last >= $this->policy->interval;
    }

    private function collect(int $now): bool
    {
        $directory = $this->openDirectory();

        if ($directory === false) {

            return false;
        }

        $deleted = 0;

        try {

            while (($name = readdir($directory)) !== false) {

                if (! preg_match('/^[0-9a-f]{64}\.php$/D', $name)) {
                    continue;
                }

                $path = $this->directory . DIRECTORY_SEPARATOR . $name;
                clearstatcache(true, $path);
                $stat = @lstat($path);

                if ($stat === false || ! self::regular($stat) || $stat['mtime'] >= $now - $this->policy->maxAge) {
                    continue;
                }

                // Recheck identity and age immediately before unlinking.
                clearstatcache(true, $path);
                $current = @lstat($path);

                if ($current === false || ! self::regular($current)
                    || $current['dev'] !== $stat['dev'] || $current['ino'] !== $stat['ino']
                    || $current['mtime'] >= $now - $this->policy->maxAge
                ) {
                    continue;
                }

                if ($this->deleteCompiled($path) && ++$deleted >= $this->policy->maxDeletions) {
                    break;
                }

            }

        } finally {
            closedir($directory);
        }

        return true;
    }

    /** @param resource $handle */
    private function record(mixed $handle, int $now): void
    {

        if (@rewind($handle) && @ftruncate($handle, 0)) {
            @fwrite($handle, (string) $now);
            @fflush($handle);
        }

    }
}
