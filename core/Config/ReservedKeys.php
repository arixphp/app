<?php

declare(strict_types=1);

namespace Arix\Config;

use InvalidArgumentException;

use function array_key_exists;

/**
 * Configuration keys owned by the framework.
 *
 * The framework keeps these locations out of the application configuration
 * on purpose: compiled templates are PHP files and the scheduler appends to
 * its log, so neither may be pointed at a directory the web server
 * publishes.
 */
class ReservedKeys
{
    /** @var array<string, array<string, string>> */
    private const array RESERVED = [
        'cache' => [
            'path' => 'The compiled template cache is fixed at storage/cache inside the application root.',
        ],
        'schedule' => [
            'log' => 'The scheduler log is fixed at storage/logs/cron.log inside the application root.',
        ],
    ];

    /**
     * @param  array<string, mixed>  $values
     */
    public static function assert(string $section, array $values, string $file): void
    {
        foreach (self::RESERVED[$section] ?? [] as $key => $message) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            throw new InvalidArgumentException("{$file} declares the reserved configuration key '{$section}.{$key}'. {$message} Remove it from the file.");
        }
    }
}
