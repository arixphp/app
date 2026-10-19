<?php

declare(strict_types=1);

namespace Arix\Scheduling;

use DateTimeImmutable;

use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function trim;

class ScheduleLogger
{
    public function __construct(private readonly string $path) {}

    public function write(ScheduleReport $report, DateTimeImmutable $now): void
    {
        $stamp = $now->format('Y-m-d H:i:s T');
        $lines = $report->lines();
        $lines[] = $report->summary();

        foreach ($lines as $line) {
            $this->append(sprintf('[%s] %s', $stamp, trim($line)));
        }
    }

    private function append(string $line): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return;
        }

        @file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
