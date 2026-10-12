<?php

declare(strict_types=1);

namespace Arix\Localization;

use Arix\Support\Arr;
use InvalidArgumentException;

use function in_array;
use function is_array;
use function is_string;

class Translator
{
    private array $catalogues = [];

    public function __construct(
        private readonly string $directory,
        public readonly array $locales,
        public readonly string $defaultLocale,
        public readonly string $locale,
    ) {
        if ($locales === [] || ! in_array($defaultLocale, $locales, true) || ! in_array($locale, $locales, true)) {
            throw new InvalidArgumentException('Invalid locale configuration.');
        }

        foreach ($locales as $supported) {
            if (! is_string($supported) || ! preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $supported)) {
                throw new InvalidArgumentException('Invalid locale name.');
            }
        }
    }

    public function get(string $key, array $replace = []): string
    {
        $value = $this->find($this->locale, $key)
            ?? ($this->locale === $this->defaultLocale ? null : $this->find($this->defaultLocale, $key))
            ?? $key;

        foreach ($replace as $name => $replacement) {
            $value = str_replace(':'.$name, (string) $replacement, $value);
        }

        return $value;
    }

    private function find(string $locale, string $key): null|string
    {
        $value = Arr::get($this->catalogue($locale), $key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function catalogue(string $locale): array
    {
        if (! isset($this->catalogues[$locale])) {
            $file = $this->directory.'/'.$locale.'.php';
            $catalogue = is_file($file) ? require $file : [];

            if (! is_array($catalogue)) {
                throw new InvalidArgumentException("Translation catalogue for locale '{$locale}' must return an array.");
            }

            self::validateKeys($catalogue, $locale);
            $this->catalogues[$locale] = $catalogue;
        }

        return $this->catalogues[$locale];
    }

    private static function validateKeys(array $values, string $locale): void
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && str_contains($key, '.')) {
                throw new InvalidArgumentException("Translation catalogue for locale '{$locale}' contains the literal dotted key '{$key}'; use nested arrays.");
            }

            if (is_array($value)) {
                self::validateKeys($value, $locale);
            }
        }
    }
}
