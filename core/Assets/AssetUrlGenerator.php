<?php

declare(strict_types=1);

namespace Arix\Assets;

use Arix\Routing\UrlGenerator;
use Arix\Support\AssetVersioner;

class AssetUrlGenerator
{
    public function __construct(
        private readonly AssetPolicy $policy,
        private readonly UrlGenerator $urls,
        private readonly AssetVersioner $versioner
    ) {}

    public function to(AssetType $type, string $path, bool $versioned): string
    {
        $relative = $this->policy->resolve($type, $path);
        $url = $this->urls->to("/{$relative}");

        return $versioned ? "{$url}?v=" . $this->versioner->hash($relative) : $url;
    }
}
