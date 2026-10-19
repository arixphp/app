<?php

declare(strict_types=1);

namespace Arix\Views;

use InvalidArgumentException;

use function array_key_exists;
use function is_array;
use function is_bool;
use function is_int;

class TemplateCachePolicy
{
    public function __construct(
        public readonly bool $cleanup = true,
        public readonly int $interval = 3600,
        public readonly int $maxAge = 86400,
        public readonly int $maxDeletions = 100,
    ) {

        if ($interval < 1 || $maxAge < 1 || $maxDeletions < 1) {
            throw new InvalidArgumentException('Template cache limits must be positive integers.');
        }

    }

    public static function fromConfiguration(array $cache): self
    {
        $options = array_key_exists('templates', $cache) ? $cache['templates'] : [];

        if (! is_array($options)) {
            throw new InvalidArgumentException('cache.templates must be an array.');
        }

        $options = array_replace(['cleanup' => true, 'interval' => 3600, 'max_age' => 86400, 'max_deletions' => 100], $options);

        if (! is_bool($options['cleanup'])) {
            throw new InvalidArgumentException('cache.templates.cleanup must be a boolean.');
        }

        foreach (['interval', 'max_age', 'max_deletions'] as $key) {

            if (! is_int($options[$key]) || $options[$key] < 1) {
                throw new InvalidArgumentException("cache.templates.{$key} must be a positive integer.");
            }

        }

        return new self($options['cleanup'], $options['interval'], $options['max_age'], $options['max_deletions']);
    }
}
