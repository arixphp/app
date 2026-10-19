<?php

declare(strict_types=1);

namespace Arix\Scheduling;

use Arix\Scheduling\Contracts\ScheduleLock;
use DateTimeImmutable;
use Throwable;

use function hrtime;

class ScheduleRunner
{
    public function __construct(
        private readonly Schedule $schedule,
        private readonly ScheduleLock $lock,
        private readonly int $grace = 3600,
    ) {}

    public function run(DateTimeImmutable $now): ScheduleReport
    {
        $results = [];

        foreach ($this->schedule->events() as $event) {
            $results[] = $this->runEvent($event, $now);
        }

        return new ScheduleReport($results);
    }

    private function runEvent(Event $event, DateTimeImmutable $now): TaskResult
    {
        try {
            $occurrence = $event->occurrence($now);
        } catch (Throwable $exception) {
            return new TaskResult($event->name(), TaskStatus::Failed, 0.0, $exception->getMessage());
        }

        $key = $occurrence->format('YmdHi');
        $delay = $now->getTimestamp() - $occurrence->getTimestamp();

        if ($delay > $this->grace) {
            return new TaskResult($event->name(), TaskStatus::NotDue);
        }

        $token = $this->lock->acquire($event->name(), $key);

        if ($token === null) {
            $status = $this->lock->isCompleted($event->name(), $key)
                ? TaskStatus::AlreadyCompleted
                : TaskStatus::Locked;

            return new TaskResult($event->name(), $status);
        }

        $started = hrtime(true);

        try {
            ($event->callback())();
        } catch (Throwable $exception) {
            $this->lock->release($event->name(), $key, $token);

            return new TaskResult($event->name(), TaskStatus::Failed, $this->duration($started), $exception->getMessage());
        }

        $this->lock->complete($event->name(), $key, $token);

        return new TaskResult($event->name(), TaskStatus::Completed, $this->duration($started));
    }

    private function duration(int $started): float
    {
        return (hrtime(true) - $started) / 1_000_000_000;
    }
}
