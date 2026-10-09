<?php

declare(strict_types=1);

namespace Arix\Localization;

use InvalidArgumentException;

final class Translator
{
    private array $catalogues = [];

    public function __construct(
        private readonly string $directory,
        public readonly array $locales,
        public readonly string $defaultLocale,
        public readonly string $locale,
    ) {
        if ($locales === [] || !in_array($defaultLocale, $locales, true) || !in_array($locale, $locales, true)) {
            throw new InvalidArgumentException('Invalid locale configuration.');
        }
        foreach ($locales as $supported) {
            if (!preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $supported)) {
                throw new InvalidArgumentException('Invalid locale name.');
            }
        }
    }

    public function negotiate(string $header): string
    {
        $preferences = [];
        foreach (explode(',', $header) as $position => $part) {
            if (!preg_match('/^\s*([a-zA-Z-]+|\*)(?:\s*;\s*q=(0(?:\.\d{1,3})?|1(?:\.0{1,3})?))?\s*$/', $part, $match)) {
                continue;
            }
            $preferences[] = ['tag' => strtolower($match[1]), 'q' => isset($match[2]) ? (float) $match[2] : 1.0, 'position' => $position];
        }
        usort($preferences, static fn (array $a, array $b): int => ($b['q'] <=> $a['q']) ?: ($a['position'] <=> $b['position']));

        foreach ($preferences as $preference) {
            if ($preference['q'] === 0.0) {
                continue;
            }
            foreach ($this->locales as $locale) {
                $tag = strtolower($locale);
                if ($preference['tag'] === $tag || str_starts_with($preference['tag'], $tag . '-')) {
                    return $locale;
                }
            }
        }

        return $this->defaultLocale;
    }

    public function get(string $key, array $replace = []): string
    {
        $value = $this->catalogue($this->locale)[$key] ?? $this->catalogue($this->defaultLocale)[$key] ?? $key;
        foreach ($replace as $name => $replacement) {
            $value = str_replace(':' . $name, (string) $replacement, $value);
        }

        return (string) $value;
    }

    private function catalogue(string $locale): array
    {
        if (!isset($this->catalogues[$locale])) {
            $file = $this->directory . '/' . $locale . '.php';
            $this->catalogues[$locale] = is_file($file) ? require $file : [];
        }

        return $this->catalogues[$locale];
    }
}
