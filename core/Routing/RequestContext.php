<?php

declare(strict_types=1);

namespace Arix\Routing;

use Arix\Http\Request;
use Arix\Localization\Translator;

class RequestContext
{
    private readonly UrlGenerator $urls;

    public function __construct(
        public readonly Request $request,
        public readonly Translator $translator,
        public readonly string $path,
        private readonly string $basePath,
    ) {
        $this->urls = new UrlGenerator($basePath, $path);
    }

    public function url(string $path = '/'): string
    {
        return $this->urls->to($path);
    }

    public function asset(string $path): string
    {
        return $this->urls->asset($path);
    }

    public function urls(): UrlGenerator
    {
        return $this->urls;
    }
}
