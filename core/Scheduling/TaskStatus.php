<?php

declare(strict_types=1);

namespace Arix\Scheduling;

enum TaskStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';
    case NotDue = 'not_due';
    case AlreadyCompleted = 'already_completed';
    case Locked = 'locked';
}
