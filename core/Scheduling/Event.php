<?php

declare(strict_types=1);

namespace Arix\Scheduling;

use Arix\Cron\CronExpression;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

use function preg_match;

class Event
{
    private CronExpression $cron;

    private string $timezone;

    public function __construct(
        private readonly string $name,
        private readonly Closure $callback,
        string $expression = '* * * * *',
        string $timezone = 'UTC',
    ) {
        $this->cron = new CronExpression($expression);
        $this->timezone = $this->validTimezone($timezone);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function expression(): string
    {
        return (string) $this->cron->getExpression();
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function callback(): Closure
    {
        return $this->callback;
    }

    public function cron(string $expression): static
    {
        $this->cron = new CronExpression($expression);

        return $this;
    }

    public function hourly(): static
    {
        return $this->cron('0 * * * *');
    }

    public function daily(): static
    {
        return $this->cron('0 0 * * *');
    }

    public function weekly(): static
    {
        return $this->cron('0 0 * * 0');
    }

    public function monthly(): static
    {
        return $this->cron('0 0 1 * *');
    }

    public function everyMinute(): static
    {
        return $this->cron('* * * * *');
    }

    public function everyFiveMinutes(): static
    {
        return $this->cron('*/5 * * * *');
    }

    public function everyTenMinutes(): static
    {
        return $this->cron('*/10 * * * *');
    }

    public function everyFifteenMinutes(): static
    {
        return $this->cron('*/15 * * * *');
    }

    public function everyThirtyMinutes(): static
    {
        return $this->cron('*/30 * * * *');
    }

    public function everyTwoHours(): static
    {
        return $this->cron('0 */2 * * *');
    }

    public function everyTwoDays(): static
    {
        return $this->cron('0 0 */2 * *');
    }

    public function everyWeekday(): static
    {
        return $this->cron('0 0 * * 1-5');
    }

    public function everyWeekend(): static
    {
        return $this->cron('0 0 * * 6,0');
    }

    public function mondays(): static
    {
        return $this->cron('0 0 * * 1');
    }

    public function fridays(): static
    {
        return $this->cron('0 0 * * 5');
    }

    public function at(string $time): static
    {
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $matches)) {
            throw new InvalidArgumentException("Invalid schedule time: {$time}");
        }

        $this->cron->setPart(0, (string) (int) $matches[2]);
        $this->cron->setPart(1, (string) (int) $matches[1]);

        return $this;
    }

    public function dailyAt(string $time): static
    {
        return $this->daily()->at($time);
    }

    public function weeklyAt(string $time): static
    {
        return $this->weekly()->at($time);
    }

    public function timezone(string $timezone): static
    {
        $this->timezone = $this->validTimezone($timezone);

        return $this;
    }

    public function occurrence(DateTimeImmutable $now): DateTimeImmutable
    {
        $previous = $this->cron->getPreviousRunDate($now, 0, true, $this->timezone);

        return DateTimeImmutable::createFromMutable($previous)->setTimezone(new DateTimeZone('UTC'));
    }

    private function validTimezone(string $timezone): string
    {
        try {
            new DateTimeZone($timezone);
        } catch (Exception $exception) {
            throw new InvalidArgumentException("Invalid schedule timezone: {$timezone}", previous: $exception);
        }

        return $timezone;
    }
}
