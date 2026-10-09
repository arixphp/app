<?php

declare(strict_types=1);

namespace Arix\Http;

final class PublicAssets
{
    public static function allows(string $path): bool
    {
        if (!preg_match('~^(styles|scripts|assets)/([A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.([A-Za-z0-9]+)$~D', $path, $match)) {
            return false;
        }
        $extension = strtolower($match[3]);
        $fonts = ['woff', 'woff2', 'ttf', 'otf'];
        $allowed = match ($match[1]) {
            'styles' => ['css'],
            'scripts' => ['js'],
            'assets' => str_starts_with(strtolower($path), 'assets/fonts/')
                ? $fonts
                : [...$fonts, 'avif', 'gif', 'ico', 'jpg', 'jpeg', 'png', 'webp', 'mp4', 'webm', 'ogv'],
        };

        return in_array($extension, $allowed, true);
    }
}
