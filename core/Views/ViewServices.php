<?php

declare(strict_types=1);

namespace Arix\Views;

use Arix\Container\Container;
use Arix\Localization\Translator;

class ViewServices
{
    public static function register(Container $container, string $root, array $configuration): void
    {
        $config = ViewServicesConfig::resolve($root, $configuration);

        $container->instance(Translator::class, new Translator($config->lang, $config->locales, $config->defaultLocale, $config->locale));

        $container->instance(ViewFactory::class, new ViewFactory());

        $engine = new TemplateEngine($config->views, $config->cachePath, TemplateEngine::VERSION . "\0" . $config->cacheVersion, $config->templateCachePolicy);

        $container->instance(TemplateEngine::class, $engine);
        $container->instance(ErrorViewRenderer::class, new ErrorViewRenderer($engine, $config->appName));
    }
}
