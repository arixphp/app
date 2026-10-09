<?php

declare(strict_types=1);

namespace Arix\Routing;

use Arix\Http\HttpException;
use Arix\Http\PublicAssets;
use Arix\Http\Request;
use InvalidArgumentException;

class UrlGenerator
{
    public function __construct(
        private readonly string $basePath,
        private readonly string $currentPath = '/',
    ) {
    }

    public function to(string $path = '/'): string
    {
        if (str_contains($path, '?') || str_contains($path, '#') || str_contains($path, ':')) {
            throw new InvalidArgumentException('Expected an internal path without query or fragment.');
        }
        $path = str_starts_with($path, '/') ? $path : '/' . $path;
        try {
            // Share request path validation; encode segments after decoding once.
            $path = (new Request('GET', $path))->path();
        } catch (HttpException $exception) {
            throw new InvalidArgumentException('Invalid internal path.', previous: $exception);
        }
        if (str_contains($path, ':') || str_contains($path, '?') || str_contains($path, '#')) {
            throw new InvalidArgumentException('Invalid internal path.');
        }
        $segments = array_map('rawurlencode', explode('/', trim($path, '/')));
        $suffix = implode('/', $segments);

        return rtrim($this->basePath, '/') . '/' . $suffix;
    }

    public function current_path(): string
    {
        // RequestContext paths are decoded; return a reusable URL path.
        return implode('/', array_map('rawurlencode', explode('/', $this->currentPath)));
    }

    public function current(): string
    {
        return $this->to($this->current_path());
    }

    public function asset(string $path): string
    {
        $path = ltrim($path, '/');
        if (!PublicAssets::allows($path)) {
            throw new InvalidArgumentException('Invalid public asset path.');
        }

        return rtrim($this->basePath, '/') . '/' . $path;
    }
}
