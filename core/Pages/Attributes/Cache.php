<?php

declare(strict_types=1);

namespace Arix\Pages\Attributes;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_CLASS)]
final class Cache
{
    public function __construct(public readonly int $ttl = 0, public readonly array $query = [])
    {
        if ($ttl < 0) {
            throw new InvalidArgumentException('Cache TTL cannot be negative.');
        }
        foreach ($query as $key) {
            if (!is_string($key) || !preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $key)) {
                throw new InvalidArgumentException('Invalid cache query key.');
            }
        }
    }
}
