<?php

declare(strict_types=1);

namespace Arix\Testing;

use Countable;
use Throwable;
use RuntimeException;

use function array_key_exists;
use function count;

final class Assert
{
    public static function assertTrue(bool $condition, string $message = 'Failed asserting that the condition is true.'): void
    {
        if ($condition !== true) {
            throw new AssertionFailed($message);
        }
    }

    public static function assertFalse(bool $condition, string $message = 'Failed asserting that the condition is false.'): void
    {
        if ($condition !== false) {
            throw new AssertionFailed($message);
        }
    }

    public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailed($message !== '' ? $message : 'Failed asserting that two values are identical: ' . self::describe($expected) . ' !== ' . self::describe($actual));
        }
    }

    public static function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected != $actual) {
            throw new AssertionFailed($message !== '' ? $message : 'Failed asserting that two values are equal: ' . self::describe($expected) . ' != ' . self::describe($actual));
        }
    }

    public static function assertNull(mixed $actual, string $message = 'Failed asserting that the value is null.'): void
    {
        if ($actual !== null) {
            throw new AssertionFailed($message);
        }
    }

    public static function assertNotNull(mixed $actual, string $message = 'Failed asserting that the value is not null.'): void
    {
        if ($actual === null) {
            throw new AssertionFailed($message);
        }
    }

    /** @param array<mixed>|Countable $actual */
    public static function assertCount(int $expected, array|Countable $actual, string $message = ''): void
    {
        if (count($actual) !== $expected) {
            throw new AssertionFailed($message !== '' ? $message : "Failed asserting that the count is {$expected}, got " . count($actual) . '.');
        }
    }

    public static function assertArrayHasKey(string|int $key, array $actual, string $message = ''): void
    {
        if (!array_key_exists($key, $actual)) {
            throw new AssertionFailed($message !== '' ? $message : "Failed asserting that the array has the key '{$key}'.");
        }
    }

    public static function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailed($message !== '' ? $message : "Failed asserting that the string contains '{$needle}'.");
        }
    }

    public static function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            throw new AssertionFailed($message !== '' ? $message : "Failed asserting that the string does not contain '{$needle}'.");
        }
    }

    public static function assertGreaterThan(int|float $expected, int|float $actual, string $message = ''): void
    {
        if ($actual <= $expected) {
            throw new AssertionFailed($message !== '' ? $message : "Failed asserting that {$actual} is greater than {$expected}.");
        }
    }

    public static function assertInstanceOf(string $expected, object $actual, string $message = ''): void
    {
        if (!($actual instanceof $expected)) {
            throw new AssertionFailed($message !== '' ? $message : "Failed asserting that the object is an instance of {$expected}.");
        }
    }

    public static function assertMatchesRegularExpression(string $pattern, string $subject, string $message = ''): void
    {
        if (preg_match($pattern, $subject) !== 1) {
            throw new AssertionFailed($message !== '' ? $message : "Failed asserting that the string matches '{$pattern}'.");
        }
    }

    /** @param class-string<Throwable> $expected */
    public static function assertThrows(callable $callback, string $expected = RuntimeException::class, string $message = ''): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            if ($exception instanceof $expected) {
                return;
            }

            throw new AssertionFailed($message !== '' ? $message : "Failed asserting that the exception is {$expected}, got ". $exception::class . '.');
        }

        throw new AssertionFailed($message !== '' ? $message : 'Failed asserting that an exception was thrown.');
    }

    public static function fail(string $message = 'Test failed.'): never
    {
        throw new AssertionFailed($message);
    }

    private static function describe(mixed $value): string
    {
        return var_export($value, true);
    }
}
