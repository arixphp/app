<?php

declare(strict_types=1);

namespace Arix\Scheduling;

use Arix\Support\Path;
use DateTimeImmutable;
use RuntimeException;

use function dirname;
use function file_put_contents;
use function is_dir;
use function is_link;
use function mkdir;
use function sprintf;
use function trim;

class ScheduleLogger
{
    public function __construct(
        private readonly string $path,
        private readonly string $directory,
        private readonly ScheduleReportFormatter $formatter = new ScheduleReportFormatter(),
    ) {}

    public function write(ScheduleReport $report, DateTimeImmutable $now): void
    {
        $stamp = $now->format('Y-m-d H:i:s T');
        $lines = $this->formatter->lines($report);
        $lines[] = $this->formatter->summary($report);

        foreach ($lines as $line) {
            $this->append(sprintf('[%s] %s', $stamp, trim($line)));
        }
    }

    private function append(string $line): void
    {
        if (dirname($this->path) !== $this->directory) {
            throw new RuntimeException('Schedule log is outside its directory.');
        }

        if (Path::isLink($this->directory)) {
            throw new RuntimeException('Schedule log directory is linked.');
        }

        if (is_link($this->path)) {
            throw new RuntimeException('Schedule log is linked.');
        }

        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0755, true) && ! is_dir($this->directory)) {
            return;
        }

        @file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
