<?php

declare(strict_types=1);

namespace Arix\Assets;

use Arix\Container\Container;
use Arix\Routing\UrlGenerator;
use Arix\Support\AssetVersioner;

class AssetServices
{
    public static function register(Container $container, string $root, UrlGenerator $urls): void
    {
        $policy = new AssetPolicy();
        $container->instance(UrlGenerator::class, $urls);
        $container->instance(AssetPolicy::class, $policy);

        $locator = new AssetLocator($root, $policy);
        $versioner = new AssetVersioner($locator);

        $container->instance(AssetLocator::class, $locator);
        $container->instance(AssetVersioner::class, $versioner);
        $container->instance(AssetUrlGenerator::class, new AssetUrlGenerator($policy, $urls, $versioner));
    }
}
