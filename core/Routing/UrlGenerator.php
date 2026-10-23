<?php

declare(strict_types=1);

namespace Arix\Routing;

use Arix\Http\RequestUrlContext;
use Arix\HttpFoundation\Request;
use SensitiveParameter;

class UrlGenerator
{
    public function __construct(
        private readonly ApplicationUrl $root,
        private readonly string $currentPath = '/',
    ) {
        UrlPath::validateCurrent($currentPath);
    }

    public function to(string $path = '/'): string
    {
        UrlPath::validateInternal($path);

        return $this->root->url . UrlPath::encode($path);
    }

    public function currentPath(): string
    {

        return UrlPath::encode($this->currentPath);
    }

    public function current(): string
    {

        return $this->root->url . $this->currentPath();
    }

    public static function fromRequest(Request $request, RequestUrlContext $context, bool $assets = false): self
    {
        $root = ApplicationUrl::fromParts($request->getSchemeAndHttpHost(), $assets ? $context->basePath : $context->baseUrl);

        return new self($root, $context->path);
    }

    public static function fromApplicationUrl(#[SensitiveParameter] string $url): self
    {

        return new self(ApplicationUrl::fromString($url));
    }
}
