<?php

declare(strict_types=1);

namespace Arix\Views;

use Arix\HttpFoundation\Request;
use Arix\Support\RuntimePaths;
use InvalidArgumentException;

use function is_array;
use function is_string;

class ViewServicesConfig
{
    private function __construct(
        public readonly string $base,
        public readonly string $views,
        public readonly string $lang,
        public readonly string $cachePath,
        public readonly string $cacheVersion,
        public readonly array $locales,
        public readonly string $defaultLocale,
        public readonly string $locale,
        public readonly string $basePath,
        public readonly string $appName,
        public readonly string $requestPath,
        public readonly TemplateCachePolicy $templateCachePolicy,
    ) {}

    public static function resolve(string $root, Request $request, array $configuration): self
    {
        [$app, $cache, $views, $lang] = self::sections($configuration);

        [$locales, $defaultLocale, $locale, $basePath, $appName] = self::application($app);

        $base = self::directory($root, 'Application root is missing.');

        $views = self::directory($views, 'Application views directory is missing.');

        $lang = self::directory($lang, 'Application lang directory is missing.');

        [$cachePath, $cacheVersion] = self::cache($cache, $base);

        return new self($base, $views, $lang, $cachePath, $cacheVersion, $locales, $defaultLocale, $locale, $basePath, $appName, self::requestPath($request), TemplateCachePolicy::fromConfiguration($cache));
    }

    private static function sections(array $configuration): array
    {
        $app = $configuration['app'] ?? null;

        $cache = $configuration['cache'] ?? null;

        $views = $configuration['views'] ?? null;

        $lang = $configuration['lang'] ?? null;

        if (! is_array($app) || ! is_array($cache) || ! is_string($views) || $views === '' || ! is_string($lang) || $lang === '') {
            throw new InvalidArgumentException('View services require app, cache, views and lang configuration.');
        }

        return [$app, $cache, $views, $lang];
    }

    private static function application(array $app): array
    {
        $locales = $app['locales'] ?? null;

        $defaultLocale = $app['default_locale'] ?? null;
        $locale = $app['locale'] ?? $defaultLocale;

        $basePath = $app['base_path'] ?? '';

        if (! is_array($locales) || ! is_string($defaultLocale) || ! is_string($locale) || ! is_string($basePath)) {
            throw new InvalidArgumentException('View services require app.locales, app.default_locale and a string app.base_path.');
        }

        $name = $app['name'] ?? null;

        return [$locales, $defaultLocale, $locale, $basePath, is_string($name) ? $name : 'ArixPHP'];
    }

    private static function directory(string $path, string $missing): string
    {
        $real = realpath($path);

        if ($real === false || ! is_dir($real)) {
            throw new InvalidArgumentException($missing);
        }

        return RuntimePaths::fromStrictRoot($path)->root;
    }

    private static function cache(array $cache, string $base): array
    {
        $version = $cache['version'] ?? '1';

        if (! is_string($version) || $version === '') {
            throw new InvalidArgumentException('View services require cache.version.');
        }

        // The compiled template cache is framework-owned: compiled templates
        // are PHP files and must never land in a published directory.
        return [RuntimePaths::fromStrictRoot($base)->cacheDirectory(), $version];
    }

    private static function requestPath(Request $request): string
    {
        $path = $request->attributes->get('_arix_path');

        if (! is_string($path) || ! str_starts_with($path, '/')) {
            return '/';
        }

        return $path;
    }
}
