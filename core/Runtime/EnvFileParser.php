<?php

declare(strict_types=1);

namespace Arix\Runtime;

use Arix\Exceptions\CoreRuntimeException;
use Arix\Support\Path;
use SensitiveParameter;

use function strlen;

class EnvFileParser
{
    public function __construct(
        protected readonly string $filePath
    ) {}

    /**
     * Return validated literals; env() handles quotes and scalar conversion.
     * Variable interpolation and multiline values are deliberately unsupported.
     *
     * @return array<string, string>
     */
    public function parse(): array
    {
        if (Path::isLink($this->filePath) || ! is_file($this->filePath) || ! is_readable($this->filePath)) {
            throw new CoreRuntimeException('The environment file must be a readable regular file, not a symbolic link.');
        }

        set_error_handler(static function (): never {
            throw new CoreRuntimeException('Cannot read the environment file.');
        });

        try {
            $lines = file($this->filePath, FILE_IGNORE_NEW_LINES);
        } finally {
            restore_error_handler();
        }

        if ($lines === false) {
            throw new CoreRuntimeException('Cannot read the environment file.');
        }

        $env = [];

        foreach ($lines as $index => $line) {
            $number = $index + 1;

            if (str_contains($line, "\0")) {
                throw new CoreRuntimeException("Invalid environment assignment on line {$number}.");
            }

            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! preg_match('/\A([A-Za-z_][A-Za-z0-9_]*)[ \t]*=(.*)\z/', $line, $matches)) {
                throw new CoreRuntimeException("Invalid environment assignment on line {$number}.");
            }

            $env[$matches[1]] = $this->value(trim($matches[2]), $number);
        }

        return $env;
    }

    private function value(#[SensitiveParameter] string $value, int $line): string
    {
        if ($value === '') {
            return '';
        }

        $quote = $value[0];

        if ($quote !== '"' && $quote !== "'") {
            return rtrim(preg_replace('/(?:\A|[ \t]+)#.*\z/', '', $value) ?? $value);
        }

        $length = strlen($value);
        $escaped = false;

        for ($index = 1; $index < $length; $index++) {
            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($quote === '"' && $value[$index] === '\\') {
                $escaped = true;

                continue;
            }

            if ($value[$index] !== $quote) {
                continue;
            }

            $remainder = substr($value, $index + 1);

            if (! preg_match('/\A(?:[ \t]*|[ \t]+#.*)\z/', $remainder)) {
                throw new CoreRuntimeException("Invalid quoted environment value on line {$line}.");
            }

            return substr($value, 0, $index + 1);
        }

        throw new CoreRuntimeException("Unterminated environment value on line {$line}.");
    }
}
