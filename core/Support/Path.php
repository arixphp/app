<?php

declare(strict_types=1);

namespace Arix\Support;

class Path extends ForbidInstance
{
    public static function join(string ...$segments): string
    {
        return implode(DIRECTORY_SEPARATOR, $segments);
    }
}
