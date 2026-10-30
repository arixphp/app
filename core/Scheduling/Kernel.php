<?php

declare(strict_types=1);

namespace Arix\Scheduling;

use Arix\Scheduling\Contracts\ScheduleLock;
use DateTimeImmutable;

class Kernel
{
    public function __construct(
        private readonly Schedule $schedule,
        private readonly ScheduleLock $lock,
        private readonly int $grace = 3600,
        private readonly ScheduleLogger|null $logger = null,
    ) {}

    public function handle(DateTimeImmutable $now): ScheduleReport
    {
        $report = (new ScheduleRunner($this->schedule, $this->lock, $this->grace))->run($now);

        $this->logger?->write($report, $now);

        return $report;
    }
}
