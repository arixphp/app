<?php

declare(strict_types=1);

namespace Arix\Scheduling;

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

    /**
     * @deprecated Format the report with ScheduleReportFormatter instead.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return (new ScheduleReportFormatter())->lines($this);
    }

    /**
     * @deprecated Format the report with ScheduleReportFormatter instead.
     *
     * @return list<string>
     */
    public function failureLines(): array
    {
        return (new ScheduleReportFormatter())->failureLines($this);
    }

    /** @deprecated Format the report with ScheduleReportFormatter instead. */
    public function summary(): string
    {
        return (new ScheduleReportFormatter())->summary($this);
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
