<?php

declare(strict_types=1);

namespace Arix\Scheduling;

class TaskResult
{
    public function __construct(
        public readonly string $name,
        public readonly TaskStatus $status,
        public readonly float $duration = 0.0,
        public readonly string|null $message = null,
    ) {}
}
