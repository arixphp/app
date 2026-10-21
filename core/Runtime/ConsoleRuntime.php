<?php

declare(strict_types=1);

namespace Arix\Runtime;

use Arix\Application;
use Arix\Config\Repository;
use Arix\Container\Container;
use Arix\Container\ContainerContext;
use Arix\Scheduling\FileScheduleLock;
use Arix\Scheduling\Schedule;
use Arix\Scheduling\ScheduleLogger;
use Arix\Scheduling\ScheduleReport;
use Arix\Scheduling\ScheduleRunner;
use Arix\Storage\Storage;
use Arix\Support\ApplicationAutoloader;
use Arix\Support\RuntimePaths;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

use function fwrite;
use function implode;
use function is_string;
use function sprintf;

class ConsoleRuntime
{
    public function run(string $applicationRoot): int
    {
        $container = new Container();

        return ContainerContext::run($container, function () use ($applicationRoot, $container): int {
            try {
                $applicationRoot = RuntimePaths::fromApplicationRoot($applicationRoot)->root;

                return ApplicationAutoloader::run($applicationRoot, fn (): int => $this->execute($applicationRoot, $container));
            } catch (Throwable $exception) {
                fwrite(STDERR, 'Arix scheduler failed: ' . $exception->getMessage() . PHP_EOL);

                return 1;
            }
        });
    }

    private function execute(string $applicationRoot, Container $container): int
    {
        $application = Application::create($applicationRoot);
        $application->registerConsole($container);

        $config = $container->get(Repository::class);
        $timezone = $config->get('schedule.timezone', 'UTC');
        $grace = (int) $config->get('schedule.grace', 3600);

        $schedule = new Schedule(is_string($timezone) ? $timezone : 'UTC');
        $application->schedules($schedule);

        $lock = new FileScheduleLock($container->get(Storage::class));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $report = (new ScheduleRunner($schedule, $lock, $grace))->run($now);

        $this->write($report, $now);
        $this->reportFailures($report);
        $this->log($report, $now, $applicationRoot);

        return $report->failed() ? 1 : 0;
    }

    private function write(ScheduleReport $report, DateTimeImmutable $now): void
    {
        $lines = [
            sprintf('Arix scheduler  %s', $now->format('Y-m-d H:i:s T')),
            ...$report->lines(),
            $report->summary(),
        ];

        fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    private function reportFailures(ScheduleReport $report): void
    {
        foreach ($report->failureLines() as $line) {
            fwrite(STDERR, $line . PHP_EOL);
        }
    }

    private function log(ScheduleReport $report, DateTimeImmutable $now, string $applicationRoot): void
    {
        // The scheduler log is framework-owned: it must never be pointed at a
        // published directory, where appending to it would corrupt content.
        $paths = RuntimePaths::fromStrictRoot($applicationRoot);
        $logger = new ScheduleLogger($paths->scheduleLogPath(), $paths->scheduleLogDirectory());
        $logger->write($report, $now);
    }
}
