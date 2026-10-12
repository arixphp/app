<?php

declare(strict_types=1);

namespace Arix\Http;

use Arix\Constants\HttpStatusCode;
use Arix\HttpFoundation\Request;
use InvalidArgumentException;

use function strlen;

class QueryPathValidator
{
    public function __construct(public readonly string $basePath = '')
    {
        if ($basePath !== '') {
            try {
                if ($basePath === '/' || str_ends_with($basePath, '/') || str_contains($basePath, '?') || str_contains($basePath, '#') || $this->decode($basePath) !== $basePath) {
                    throw new InvalidArgumentException('base_path must be an unencoded absolute path without a trailing slash.');
                }
            } catch (HttpException $exception) {
                throw new InvalidArgumentException('Invalid base_path.', previous: $exception);
            }
        }
    }

    public function path(Request $request): string
    {
        $path = $this->decode(explode('?', $request->getRequestUri(), 2)[0]);

        if ($this->basePath !== '') {
            if ($path !== $this->basePath && ! str_starts_with($path, "{$this->basePath}/")) {
                throw new HttpException(HttpStatusCode::NOT_FOUND, 'Page not found.');
            }

            $path = substr($path, strlen($this->basePath));
        }

        return $path === '' ? '/' : $path;
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
