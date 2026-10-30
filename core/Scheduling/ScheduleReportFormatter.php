<?php

declare(strict_types=1);

namespace Arix\Scheduling;

use function count;
use function implode;
use function in_array;
use function number_format;
use function sprintf;

class ScheduleReportFormatter
{
    /** @return list<string> */
    public function lines(ScheduleReport $report): array
    {
        $lines = [];

        foreach ($report->results() as $result) {
            if (! in_array($result->status, [TaskStatus::Completed, TaskStatus::Failed], true)) {
                continue;
            }

            $lines[] = $this->line($result);
        }

        return $lines;
    }

    /** @return list<string> */
    public function failureLines(ScheduleReport $report): array
    {
        $lines = [];

        foreach ($report->results() as $result) {
            if ($result->status !== TaskStatus::Failed) {
                continue;
            }

            $lines[] = $this->line($result);
        }

        return $lines;
    }

    public function summary(ScheduleReport $report): string
    {
        $count = count($report->results());

        if ($count === 0) {

            return '0 events';
        }

        $parts = [];
        $completed = $this->count($report, TaskStatus::Completed);
        $failed = $this->count($report, TaskStatus::Failed);
        $skipped = $this->count($report, TaskStatus::NotDue) + $this->count($report, TaskStatus::AlreadyCompleted) + $this->count($report, TaskStatus::Locked);

        if ($completed > 0) {
            $parts[] = sprintf('%d completed', $completed);
        }

        if ($failed > 0) {
            $parts[] = sprintf('%d failed', $failed);
        }

        if ($skipped > 0) {
            $parts[] = sprintf('%d skipped', $skipped);
        }

        return sprintf('%d events: %s', $count, implode(', ', $parts));
    }

    private function line(TaskResult $result): string
    {
        if ($result->status === TaskStatus::Completed) {

            return sprintf('  %-4s %-20s%s', 'OK', $result->name, sprintf('completed in %ss', number_format($result->duration, 2, '.', '')));
        }

        return sprintf('  %-4s %-20s%s', 'FAIL', $result->name, $result->message === null ? 'failed' : sprintf('failed: %s', $result->message));
    }

    private function count(ScheduleReport $report, TaskStatus $status): int
    {
        $count = 0;

        foreach ($report->results() as $result) {
            if ($result->status === $status) {
                $count++;
            }
        }

        return $count;
    }
}
