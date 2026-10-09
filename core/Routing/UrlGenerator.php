<?php

declare(strict_types=1);

namespace Arix\Routing;

use Arix\Http\HttpException;
use Arix\Http\PublicAssets;
use Arix\Http\Request;
use InvalidArgumentException;

final class UrlGenerator
{
    public function __construct(
        private readonly string $basePath,
        private readonly string $locale,
        private readonly array $locales,
        private readonly string $currentPath = '/',
    ) {
    }

    public function to(string $path = '/', ?string $locale = null): string
    {
        $locale ??= $this->locale;
        if (!in_array($locale, $this->locales, true)) {
            throw new InvalidArgumentException('Unsupported locale.');
        }
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

        return rtrim($this->basePath, '/') . '/' . $locale . ($suffix === '' ? '' : '/' . $suffix);
    }

    public function current_path(): string
    {
        // PageContext paths are decoded; return a reusable URL path.
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
