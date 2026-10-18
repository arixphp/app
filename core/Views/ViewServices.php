<?php

declare(strict_types=1);

namespace Arix\Views;

use Arix\Assets\AssetServices;
use Arix\Assets\AssetUrlGenerator;
use Arix\Container\Container;
use Arix\HttpFoundation\Request;
use Arix\Localization\Translator;
use Arix\Routing\UrlGenerator;

class ViewServices
{
    public static function register(Container $container, string $root, Request $request, array $configuration): void
    {
        $config = ViewServicesConfig::resolve($root, $request, $configuration);

        $container->instance(Translator::class, new Translator($config->lang, $config->locales, $config->defaultLocale, $config->locale));

        // Keep direct ViewServices callers compatible without owning application assets.
        if (! $container->has(AssetUrlGenerator::class)) {
            AssetServices::register($container, $config->base, new UrlGenerator($config->basePath, $config->requestPath));
        }

        $container->instance(ViewFactory::class, new ViewFactory());

        $engine = new TemplateEngine($config->views, $config->cachePath, TemplateEngine::VERSION . "\0" . $config->cacheVersion);

        $container->instance(TemplateEngine::class, $engine);
        $container->instance(ErrorViewRenderer::class, new ErrorViewRenderer($engine, $config->appName));
    }
}
