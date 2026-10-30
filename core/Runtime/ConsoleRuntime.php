<?php

declare(strict_types=1);

namespace Arix\Runtime;

use Arix\Application;
use Arix\Container\Container;
use Arix\Container\ContainerContext;
use Arix\Scheduling\ConsoleReporter;
use Arix\Scheduling\ScheduleReport;
use Arix\Support\RuntimePaths;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

use function fwrite;

class ConsoleRuntime
{
    public function __construct(private readonly ConsoleReporter $reporter = new ConsoleReporter()) {}

    public function run(string $applicationRoot): int
    {
        $container = new Container();

        return ContainerContext::run($container, function () use ($applicationRoot, $container): int {
            try {
                $applicationRoot = RuntimePaths::fromApplicationRoot($applicationRoot)->root;

                return Environment::run($applicationRoot, fn (): int => $this->execute($applicationRoot, $container));
            } catch (Throwable $exception) {
                fwrite(STDERR, 'Arix scheduler failed: ' . $exception->getMessage() . PHP_EOL);

                return 1;
            }
        });
    }

    private function execute(string $applicationRoot, Container $container): int
    {
        $kernel = Application::createForScheduling($applicationRoot)->scheduleKernel($container);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $report = $kernel->handle($now);

        $this->write($report, $now);

        return $report->failed() ? 1 : 0;
    }

    private function write(ScheduleReport $report, DateTimeImmutable $now): void
    {
        fwrite(STDOUT, $this->reporter->render($report, $now));

        foreach ($this->reporter->failures($report) as $line) {
            fwrite(STDERR, $line . PHP_EOL);
        }
    }
}
