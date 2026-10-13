<?php

declare(strict_types=1);

namespace Arix\Views;

use Arix\Container\Container;
use Arix\HttpFoundation\Request;
use Arix\Localization\Translator;
use Arix\Routing\UrlGenerator;
use Arix\Support\Path;
use InvalidArgumentException;

use function is_array;
use function is_string;

class ViewServices
{
    public static function register(Container $container, string $root, Request $request, array $configuration): void
    {
        $app = $configuration['app'] ?? null;
        $cache = $configuration['cache'] ?? null;
        $views = $configuration['views'] ?? null;
        $lang = $configuration['lang'] ?? null;

        if (! is_array($app) || ! is_array($cache) || ! is_string($views) || $views === '' || ! is_string($lang) || $lang === '') {
            throw new InvalidArgumentException('View services require app, cache, views and lang configuration.');
        }

        $locales = $app['locales'] ?? null;
        $defaultLocale = $app['default_locale'] ?? null;
        $basePath = $app['base_path'] ?? '';

        if (! is_array($locales) || ! is_string($defaultLocale) || ! is_string($basePath)) {
            throw new InvalidArgumentException('View services require app.locales, app.default_locale and a string app.base_path.');
        }

        $base = realpath($root);

        if ($base === false || ! is_dir($base)) {
            throw new InvalidArgumentException('Application root is missing.');
        }

        foreach (['views' => $views, 'lang' => $lang] as $label => $directory) {
            $real = realpath($directory);

            if ($real === false || ! is_dir($real)) {
                throw new InvalidArgumentException("Application {$label} directory is missing.");
            }

            if ($label === 'views') {
                $views = $real;
            } else {
                $lang = $real;
            }
        }

        $cachePath = $cache['path'] ?? Path::join($base, 'storage', 'cache');
        $cacheVersion = $cache['version'] ?? '1';

        if (! is_string($cachePath) || $cachePath === '' || ! is_string($cacheVersion) || $cacheVersion === '') {
            throw new InvalidArgumentException('View services require cache.path and cache.version.');
        }

        $path = $request->attributes->get('_arix_path');

        if (! is_string($path) || ! str_starts_with($path, '/')) {
            $path = '/';
        }

        $container->instance(Translator::class, new Translator($lang, $locales, $defaultLocale, $defaultLocale));
        $container->instance(UrlGenerator::class, new UrlGenerator($basePath, $path));
        $container->instance(ViewFactory::class, new ViewFactory());
        $engine = new TemplateEngine($views, $cachePath, TemplateEngine::VERSION . "\0" . $cacheVersion);
        $container->instance(TemplateEngine::class, $engine);
        $container->instance(ErrorViewRenderer::class, new ErrorViewRenderer($engine, is_string($app['name'] ?? null) ? $app['name'] : 'ArixPHP'));
    }
}
