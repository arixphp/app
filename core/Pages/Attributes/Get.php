<?php

declare(strict_types=1);

namespace Arix\Pages\Attributes;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_CLASS)]
final class Get
{
    public function __construct(public readonly string $path)
    {
        if (!preg_match('~^/(?:[\pL\pN_-]+(?:/[\pL\pN_-]+)*)?$~uD', $path)) {
            throw new InvalidArgumentException('GET routes must be absolute canonical paths without query strings or fragments.');
        }
    }
}
