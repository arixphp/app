<?php

declare(strict_types=1);

namespace Arix\Scheduling\Contracts;

interface ScheduleLock
{
    /**
     * Reserve an occurrence for execution.
     *
     * Returns an opaque token when the event may run, or null when it must
     * not: the lock is held by another process, or the occurrence is already
     * recorded as completed.
     */
    public function acquire(string $event, string $occurrence): string|null;

    /** Record the occurrence as completed and release the lock. */
    public function complete(string $event, string $occurrence, string $token): void;

    /** Release the lock without recording the occurrence, so it can be retried. */
    public function release(string $event, string $occurrence, string $token): void;

    /** Report whether the occurrence, or a later one, is already completed. */
    public function isCompleted(string $event, string $occurrence): bool;
}
