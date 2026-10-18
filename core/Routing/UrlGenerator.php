<?php

declare(strict_types=1);

namespace Arix\Routing;

use Arix\Constants\HttpStatusCode;
use Arix\Http\HttpException;
use InvalidArgumentException;

class UrlGenerator
{
    public function __construct(
        private readonly string $basePath = '',
        private readonly string $currentPath = '/',
    ) {
        if ($basePath !== '') {
            if ($basePath === '/' || str_ends_with($basePath, '/') || str_contains($basePath, '?') || str_contains($basePath, '#')) {
                throw new InvalidArgumentException('base_path must be an unencoded absolute path without a trailing slash.');
            }

            $this->validate($basePath);
        }

        $this->validate($currentPath);

        if (! str_starts_with($currentPath, '/')) {
            throw new InvalidArgumentException('Invalid current path.');
        }
    }

    public function to(string $path = '/'): string
    {
        return rtrim($this->basePath, '/') . '/' . $this->suffix($path);
    }

    public function currentPath(): string
    {
        return '/' . $this->suffix($this->currentPath);
    }

    public function current(): string
    {
        return $this->to($this->currentPath());
    }

    private function suffix(string $path): string
    {
        $this->validate($path);

        if (! str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
    }

    private function validate(string $path): void
    {
        if (str_contains($path, '?') || str_contains($path, '#') || str_contains($path, ':')) {
            throw new InvalidArgumentException('Expected an internal path without query or fragment.');
        }

        try {
            $decoded = $this->decode($path);
        } catch (HttpException $exception) {
            throw new InvalidArgumentException('Invalid internal path.', previous: $exception);
        }

        if (str_contains($decoded, ':') || str_contains($decoded, '?') || str_contains($decoded, '#')) {
            throw new InvalidArgumentException('Invalid internal path.');
        }
    }

    private function decode(string $raw): string
    {
        if (! str_starts_with($raw, '/') || preg_match('/%(?![a-f0-9]{2})|%(?:2f|5c)/i', $raw)) {
            throw new HttpException(HttpStatusCode::BAD_REQUEST, 'Invalid path.');
        }

        $path = rawurldecode($raw);

        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $path) || ! preg_match('//u', $path)) {
            throw new HttpException(HttpStatusCode::BAD_REQUEST, 'Invalid path.');
        }

        foreach (explode('/', $path) as $segment) {
            if (str_starts_with($segment, '.')) {
                throw new HttpException(HttpStatusCode::BAD_REQUEST, 'Invalid path.');
            }
        }

        if (str_contains($path, '//')) {
            throw new HttpException(HttpStatusCode::BAD_REQUEST, 'Ambiguous path.');
        }

        return $path;
    }
}
