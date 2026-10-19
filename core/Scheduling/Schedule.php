<?php

declare(strict_types=1);

namespace Arix\Scheduling;

use Closure;
use InvalidArgumentException;

use function array_key_exists;

class Schedule
{
    /** @var array<string, Event> */
    private array $events = [];

    public function __construct(private readonly string $timezone = 'UTC') {}

    public function call(string $name, Closure $callback): Event
    {
        if (array_key_exists($name, $this->events)) {
            throw new InvalidArgumentException("Schedule [{$name}] is already registered.");
        }

        $event = new Event($name, $callback, '* * * * *', $this->timezone);
        $this->events[$name] = $event;

        return $event;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->events);
    }

    /** @return array<string, Event> */
    public function events(): array
    {
        return $this->events;
    }
}
