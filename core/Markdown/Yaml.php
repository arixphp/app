<?php

declare(strict_types=1);

namespace Arix\Markdown;

use function array_key_exists;
use function count;
use function is_string;

/**
 * YAML frontmatter parser inspired by https://github.com/eriknyk/Yaml.
 * Originally based on Spyc by Chris Wanstrath and Vladimir Andersen;
 * further work by Erik Amaru Ortiz. Maintained independently by Arix.
 *
 * Copyright 2005-2006 Chris Wanstrath, 2006-2011 Vladimir Andersen,
 * and 2012 Erik Amaru Ortiz. Distributed under the MIT license; see
 * LICENSE in this directory.
 *
 * Supports a conservative subset of YAML: mappings, sequences, quoted and
 * plain scalars, and flow sequences. Unsupported syntax returns null.
 */
class Yaml
{
    public function parse(string $source): array|null
    {
        $lines = preg_split('/\r\n|\n|\r/', $source);
        $index = 0;
        $valid = true;
        $result = $this->mapping($lines, $index, 0, $valid);

        if (! $valid || $index !== count($lines)) {
            return null;
        }

        return $result;
    }

    private function mapping(array $lines, int &$index, int $indent, bool &$valid): array
    {
        $result = [];

        while ($index < count($lines)) {
            $line = $lines[$index];

            if (trim($line) === '' || preg_match('/^\s*#/', $line)) {
                $index++;

                continue;
            }

            $depth = strspn($line, ' ');

            if ($depth < $indent) {
                break;
            }

            if ($depth !== $indent || ! preg_match('/^([A-Za-z_][A-Za-z0-9_-]*):(?:\s+(.*)|\s*)$/u', substr($line, $depth), $match) || array_key_exists($match[1], $result)) {
                $valid = false;

                break;
            }

            $index++;
            $value = $match[2] ?? '';

            if ($value !== '') {
                $result[$match[1]] = $this->scalar($value, $valid);
            } else {
                $next = $index;

                while ($next < count($lines) && (trim($lines[$next]) === '' || preg_match('/^\s*#/', $lines[$next]))) {
                    $next++;
                }

                $childIndent = $next < count($lines) ? strspn($lines[$next], ' ') : 0;

                if ($childIndent > $indent) {
                    $index = $next;
                    $result[$match[1]] = str_starts_with(substr($lines[$next], $childIndent), '- ')
                        ? $this->sequence($lines, $index, $childIndent, $valid)
                        : $this->mapping($lines, $index, $childIndent, $valid);
                } else {
                    $result[$match[1]] = null;
                }
            }

            if (! $valid) {
                break;
            }
        }

        return $result;
    }

    private function sequence(array $lines, int &$index, int $indent, bool &$valid): array
    {
        $result = [];

        while ($index < count($lines)) {
            $line = $lines[$index];

            if (trim($line) === '' || preg_match('/^\s*#/', $line)) {
                $index++;

                continue;
            }

            $depth = strspn($line, ' ');

            if ($depth < $indent) {
                break;
            }

            if ($depth !== $indent || ! preg_match('/^-\s+(.+)$/u', substr($line, $depth), $match)) {
                $valid = false;

                break;
            }

            $result[] = $this->scalar($match[1], $valid);
            $index++;

            if (! $valid) {
                break;
            }
        }

        return $result;
    }

    private function scalar(string $value, bool &$valid): mixed
    {
        $value = trim($value);

        if (str_starts_with($value, '[')) {
            if (! str_ends_with($value, ']')) {
                $valid = false;

                return null;
            }

            $inner = trim(substr($value, 1, -1));

            if ($inner === '') {
                return [];
            }

            preg_match_all('/(?:"(?:\\\\.|[^"\\\\])*"|\'(?:\'\'|[^\'])*\'|[^,])+/u', $inner, $matches);
            $parts = $matches[0];
            $values = [];

            if (implode(',', $parts) !== $inner) {
                $valid = false;

                return null;
            }

            foreach ($parts as $part) {
                $values[] = $this->scalar(trim($part), $valid);
            }

            return $values;
        }

        if (str_starts_with($value, '"')) {
            $decoded = json_decode($value, true);

            if (json_last_error() !== JSON_ERROR_NONE || ! is_string($decoded)) {
                $valid = false;
            }

            return $decoded;
        }

        if (str_starts_with($value, "'")) {
            if (! preg_match("/^'((?:[^']|'')*)'$/s", $value, $match)) {
                $valid = false;

                return null;
            }

            return str_replace("''", "'", $match[1]);
        }

        if ($value === '' || preg_match('/^[\[{&*!|>@`%}]|[\[\]{}]|:\s/u', $value)) {
            $valid = false;

            return null;
        }

        $value = preg_replace('/\s+#.*$/u', '', $value);

        return match (strtolower($value)) {
            'null', '~' => null,
            'true' => true,
            'false' => false,
            default => preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) && filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : $value,
        };
    }
}
