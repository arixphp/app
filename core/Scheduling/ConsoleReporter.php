<?php

declare(strict_types=1);

namespace Arix\Scheduling;

use DateTimeImmutable;

use function implode;
use function sprintf;

class ConsoleReporter
{
    public function __construct(private readonly ScheduleReportFormatter $formatter = new ScheduleReportFormatter()) {}

    public function render(ScheduleReport $report, DateTimeImmutable $now): string
    {
        $lines = [
            sprintf('Arix scheduler  %s', $now->format('Y-m-d H:i:s T')),
            ...$this->formatter->lines($report),
            $this->formatter->summary($report),
        ];

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /** @return list<string> */
    public function failures(ScheduleReport $report): array
    {
        return $this->formatter->failureLines($report);
    }
}
