<?php

declare(strict_types=1);

namespace Arix\Routing;

use InvalidArgumentException;

use function is_string;

readonly class CachePolicy
{
    /** @param list<string> $query */
    public function __construct(public int $ttl = 0, public array $query = [])
    {
        if ($ttl < 0 || !array_is_list($query)) {
            throw new InvalidArgumentException('Invalid cache policy.');
        }

        foreach ($query as $key) {
            if (!is_string($key) || !preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $key)) {
                throw new InvalidArgumentException('Invalid cache query key.');
            }
        }
    }
}
