<?php

declare(strict_types=1);

namespace Arix\Support;

use InvalidArgumentException;

use function array_slice;
use function chr;
use function count;
use function ord;
use function strlen;

class Str extends ForbidInstance
{
    public static function length(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    public static function upper(string $value): string
    {
        return mb_strtoupper($value, 'UTF-8');
    }

    public static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    public static function contains(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle === '') {
                continue;
            }

            if (mb_strpos($haystack, $needle, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    public static function startsWith(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle === '') {
                continue;
            }

            if (mb_substr($haystack, 0, mb_strlen($needle, 'UTF-8'), 'UTF-8') === $needle) {
                return true;
            }
        }

        return false;
    }

    public static function endsWith(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle === '') {
                continue;
            }

            $needleLength = mb_strlen($needle, 'UTF-8');

            if ($needleLength > mb_strlen($haystack, 'UTF-8')) {
                continue;
            }

            if (mb_substr($haystack, -$needleLength, null, 'UTF-8') === $needle) {
                return true;
            }
        }

        return false;
    }

    public static function before(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $position = mb_strpos($subject, $search, 0, 'UTF-8');

        return $position === false ? $subject : mb_substr($subject, 0, $position, 'UTF-8');
    }

    public static function after(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $position = mb_strpos($subject, $search, 0, 'UTF-8');

        return $position === false ? $subject : mb_substr($subject, $position + mb_strlen($search, 'UTF-8'), null, 'UTF-8');
    }

    public static function beforeLast(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $position = mb_strrpos($subject, $search, 0, 'UTF-8');

        return $position === false ? $subject : mb_substr($subject, 0, $position, 'UTF-8');
    }

    public static function afterLast(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }
        $position = mb_strrpos($subject, $search, 0, 'UTF-8');

        return $position === false ? $subject : mb_substr($subject, $position + mb_strlen($search, 'UTF-8'), null, 'UTF-8');
    }

    public static function between(string $subject, string $from, string $to): string
    {
        if ($from === '' || $to === '') {
            return '';
        }

        $fromPosition = mb_strpos($subject, $from, 0, 'UTF-8');

        if ($fromPosition === false) {
            return '';
        }

        $start = $fromPosition + mb_strlen($from, 'UTF-8');
        $toPosition = mb_strpos($subject, $to, $start, 'UTF-8');

        if ($toPosition === false) {
            return '';
        }

        return mb_substr($subject, $start, $toPosition - $start, 'UTF-8');
    }

    public static function limit(string $value, int $limit = 100, string $end = '...'): string
    {
        if ($limit < 0) {
            throw new InvalidArgumentException('Limit must not be negative.');
        }

        if (mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit, 'UTF-8') . $end;
    }

    public static function words(string $value, int $words = 100, string $end = '...'): string
    {
        if ($words < 1) {
            throw new InvalidArgumentException('Word count must be at least 1.');
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return '';
        }

        $parts = (array) preg_split('/\s+/u', $trimmed);

        if (count($parts) <= $words) {
            return $value;
        }

        return implode(' ', array_slice($parts, 0, $words)) . $end;
    }

    public static function slug(string $title, string $separator = '-'): string
    {
        if ($separator === '') {
            throw new InvalidArgumentException('Separator must not be empty.');
        }

        $value = mb_strtolower(static::ascii($title), 'UTF-8');
        $escaped = preg_quote($separator, '/');
        $value = (string) preg_replace('/[^a-z0-9' . $escaped . ']+/i', $separator, $value);
        $value = (string) preg_replace('/' . $escaped . '{2,}/', $separator, $value);

        return trim($value, $separator);
    }

    /** StudlyCase to camelCase entry point shared by camel(). */
    public static function studly(string $value): string
    {
        $words = preg_split('/[_\-\s]+/u', $value);

        $studly = '';
        foreach ($words === false ? [] : $words as $word) {
            if ($word === '') {
                continue;
            }
            $studly .= mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($word, 1, null, 'UTF-8');
        }

        return $studly;
    }

    public static function camel(string $value): string
    {
        return lcfirst(static::studly($value));
    }

    public static function snake(string $value, string $delimiter = '_'): string
    {
        if ($delimiter === '') {
            throw new InvalidArgumentException('Delimiter must not be empty.');
        }

        $value = (string) preg_replace('/[\s-]+/u', $delimiter, $value);
        $value = (string) preg_replace('/([a-z0-9])([A-Z])/u', '$1' . $delimiter . '$2', $value);
        $value = mb_strtolower($value, 'UTF-8');
        $escaped = preg_quote($delimiter, '/');
        $value = (string) preg_replace('/' . $escaped . '{2,}/', $delimiter, $value);

        return trim($value, $delimiter);
    }

    public static function kebab(string $value): string
    {
        return static::snake($value, '-');
    }

    public static function headline(string $value): string
    {
        $value = (string) preg_replace('/([a-z0-9])([A-Z])/u', '$1 $2', $value);
        $value = (string) preg_replace('/[_\\-]+/u', ' ', $value);
        $value = (string) preg_replace('/\s+/u', ' ', trim($value));

        if ($value === '') {
            return '';
        }

        $words = explode(' ', mb_strtolower($value, 'UTF-8'));

        foreach ($words as $index => $word) {
            $words[$index] = mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($word, 1, null, 'UTF-8');
        }

        return implode(' ', $words);
    }

    public static function replace(string|array $search, string|array $replace, string|array $subject): string|array
    {
        return str_replace($search, $replace, $subject);
    }

    public static function repeat(string $string, int $times): string
    {
        if ($times < 0) {
            throw new InvalidArgumentException('Repeat count must not be negative.');
        }

        return str_repeat($string, $times);
    }

    public static function is(string|array $pattern, string $value): bool
    {
        foreach ((array) $pattern as $item) {
            $quoted = preg_quote($item, '/');
            $regex = '/^' . str_replace('\\*', '.*', $quoted) . '$/s';

            if (preg_match($regex, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function random(int $length = 16): string
    {
        if ($length < 1) {
            throw new InvalidArgumentException('Random length must be at least 1.');
        }

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $max = strlen($alphabet) - 1;
        $token = '';

        for ($i = 0; $i < $length; $i++) {
            $token .= $alphabet[random_int(0, $max)];
        }

        return $token;
    }

    /** Random version 4 UUID. */
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    private static function ascii(string $value): string
    {
        $map = [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
            'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A', 'Ā' => 'A', 'Ă' => 'A', 'Ą' => 'A',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e',
            'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E', 'Ē' => 'E', 'Ĕ' => 'E', 'Ė' => 'E', 'Ę' => 'E',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i',
            'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I', 'Ī' => 'I', 'Ĭ' => 'I', 'Į' => 'I',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o', 'ø' => 'o',
            'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ō' => 'O', 'Ŏ' => 'O', 'Ő' => 'O', 'Ø' => 'O',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
            'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U', 'Ū' => 'U', 'Ŭ' => 'U', 'Ů' => 'U', 'Ű' => 'U', 'Ų' => 'U',
            'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n',
            'Ñ' => 'N', 'Ń' => 'N', 'Ņ' => 'N', 'Ň' => 'N',
            'ç' => 'c', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c',
            'Ç' => 'C', 'Ć' => 'C', 'Ĉ' => 'C', 'Ċ' => 'C', 'Č' => 'C',
            'š' => 's', 'ś' => 's', 'ş' => 's', 'ș' => 's',
            'Š' => 'S', 'Ś' => 'S', 'Ş' => 'S', 'Ș' => 'S',
            'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
            'Ž' => 'Z', 'Ź' => 'Z', 'Ż' => 'Z',
            'ý' => 'y', 'ÿ' => 'y', 'Ý' => 'Y',
            'đ' => 'd', 'ð' => 'd', 'Ð' => 'D', 'Đ' => 'D',
            'ł' => 'l', 'Ł' => 'L',
            'æ' => 'ae', 'Æ' => 'AE', 'œ' => 'oe', 'Œ' => 'OE', 'ß' => 'ss',
        ];

        $value = strtr($value, $map);
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $transliterated === false ? $value : $transliterated;
    }
}
