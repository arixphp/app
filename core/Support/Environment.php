<?php

declare(strict_types=1);

namespace Arix\Support;

use function in_array;

final class Environment extends ForbidInstance
{
    public static function boolean(string $name): bool
    {
        // Only an explicit true/1 enables a flag. Missing or invalid values fail closed.
        return in_array(strtolower(trim((string) getenv($name))), ['true', '1'], true);
    }
}
