<?php

declare(strict_types=1);

namespace Arix\Scheduling;

use Arix\Scheduling\Contracts\ScheduleLock;
use Arix\Storage\Storage;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

use function bin2hex;
use function fclose;
use function file_exists;
use function flock;
use function fopen;
use function ftruncate;
use function fwrite;
use function hash;
use function is_array;
use function is_dir;
use function is_link;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_replace;
use function random_bytes;
use function sprintf;
use function strtolower;
use function trim;

class FileScheduleLock implements ScheduleLock
{
    /** @var array<string, array{handle: resource, token: string}> */
    private array $held = [];

    public function __construct(
        private readonly Storage $storage,
        private readonly string $directory = 'schedule',
    ) {}

    public function acquire(string $event, string $occurrence): string|null
    {
        $path = $this->lockPath($event);

        if (is_link($path)) {
            throw new RuntimeException("Schedule lock is linked: {$path}");
        }

        $handle = @fopen($path, 'c');

        if ($handle === false) {
            throw new RuntimeException("Cannot open the schedule lock: {$path}");
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        if ($this->isCompleted($event, $occurrence)) {
            flock($handle, LOCK_UN);
            fclose($handle);

            return null;
        }

        $token = bin2hex(random_bytes(16));

        ftruncate($handle, 0);
        fwrite($handle, $token);
        fflush($handle);

        $this->held[$event] = ['handle' => $handle, 'token' => $token];

        return $token;
    }

    public function complete(string $event, string $occurrence, string $token): void
    {
        $held = $this->held($event, $token);
        $state = [
            'event' => $event,
            'occurrence' => $occurrence,
            'completed_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        ];

        $this->storage->put(
            $this->statePath($event),
            json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        $this->unlock($event, $held);
    }

    public function release(string $event, string $occurrence, string $token): void
    {
        $held = $this->held($event, $token);

        $this->unlock($event, $held);
    }

    public function isCompleted(string $event, string $occurrence): bool
    {
        $path = $this->statePath($event);

        if (! $this->storage->has($path)) {
            return false;
        }

        $state = json_decode($this->storage->get($path), true);

        if (! is_array($state) || ! is_string($state['occurrence'] ?? null)) {
            return false;
        }

        return $state['occurrence'] >= $occurrence;
    }

    /** @return array{handle: resource, token: string} */
    private function held(string $event, string $token): array
    {
        $held = $this->held[$event] ?? null;

        if ($held === null || $held['token'] !== $token) {
            throw new InvalidArgumentException("Schedule lock is not held: {$event}");
        }

        return $held;
    }

    /** @param array{handle: resource, token: string} $held */
    private function unlock(string $event, array $held): void
    {
        flock($held['handle'], LOCK_UN);
        fclose($held['handle']);
        unset($this->held[$event]);
    }

    private function lockPath(string $event): string
    {
        return $this->directory() . DIRECTORY_SEPARATOR . $this->slug($event) . '.lock';
    }

    private function statePath(string $event): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $this->slug($event) . '.json';
    }

    private function directory(): string
    {
        $directory = $this->storage->path($this->directory);

        if (! is_dir($directory)) {
            if (file_exists($directory) || is_link($directory)) {
                throw new RuntimeException("Cannot use the schedule lock directory: {$directory}");
            }

            @mkdir($directory, 0755, true);
        }

        if (! is_dir($directory) || is_link($directory)) {
            throw new RuntimeException("Cannot use the schedule lock directory: {$directory}");
        }

        return $directory;
    }

    private function slug(string $event): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($event)), '-');

        if ($slug === '') {
            $slug = 'event';
        }

        return sprintf('%s-%s', $slug, substr(hash('sha256', $event), 0, 8));
    }
}
