<?php

declare(strict_types=1);

namespace Arix\Config;

use Arix\Support\Arr;
use InvalidArgumentException;

use function is_array;
use function is_string;

class Repository
{
    public function __construct(private readonly array $values)
    {
        self::validate($values, 'Configuration');
    }

    public static function validate(array $values, string $source): void
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && str_contains($key, '.')) {
                throw new InvalidArgumentException("{$source} contains the literal dotted key '{$key}'; use nested arrays.");
            }

            if (is_array($value)) {
                self::validate($value, $source);
            }
        }
    }

    public function get(string|null $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->values;
        }

        return Arr::get($this->values, $key, $default);
    }
}
