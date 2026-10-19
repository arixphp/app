<?php

declare(strict_types=1);

namespace Arix\Scheduling;

use function count;
use function in_array;
use function number_format;
use function sprintf;

class ScheduleReport
{
    /** @param list<TaskResult> $results */
    public function __construct(private readonly array $results = []) {}

    /** @return list<TaskResult> */
    public function results(): array
    {
        return $this->results;
    }

    /** @return array<string, int> */
    public function totals(): array
    {
        $totals = [];

        foreach (TaskStatus::cases() as $status) {
            $count = $this->count($status);

            if ($count > 0) {
                $totals[$status->value] = $count;
            }
        }

        return $totals;
    }

    public function failed(): bool
    {
        return $this->count(TaskStatus::Failed) > 0;
    }

    /** @return list<string> */
    public function lines(): array
    {
        $lines = [];

        foreach ($this->results as $result) {
            if (! in_array($result->status, [TaskStatus::Completed, TaskStatus::Failed], true)) {
                continue;
            }

            $lines[] = $result->status === TaskStatus::Completed
                ? sprintf('  %-4s %-20s%s', 'OK', $result->name, sprintf('completed in %ss', number_format($result->duration, 2, '.', '')))
                : sprintf('  %-4s %-20s%s', 'FAIL', $result->name, $result->message === null ? 'failed' : sprintf('failed: %s', $result->message));
        }

        return $lines;
    }

    public function summary(): string
    {
        $count = count($this->results);

        if ($count === 0) {
            return '0 events';
        }

        $parts = [];
        $completed = $this->count(TaskStatus::Completed);
        $failed = $this->count(TaskStatus::Failed);
        $skipped = $this->count(TaskStatus::NotDue) + $this->count(TaskStatus::AlreadyCompleted) + $this->count(TaskStatus::Locked);

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

    private function count(TaskStatus $status): int
    {
        $count = 0;

        foreach ($this->results as $result) {
            if ($result->status === $status) {
                $count++;
            }
        }

        return $count;
    }
}
