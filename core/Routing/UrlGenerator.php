<?php

declare(strict_types=1);

namespace Arix\Routing;

use InvalidArgumentException;

use function array_map;
use function explode;
use function implode;
use function preg_match;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function trim;

class UrlGenerator
{
    public function __construct(
        private readonly string $basePath = '',
        private readonly string $currentPath = '/',
    ) {
        if ($basePath !== '') {
            if ($basePath === '/' || str_ends_with($basePath, '/') || str_contains($basePath, '?') || str_contains($basePath, '#') || str_contains($basePath, ':') || str_contains($basePath, '%')) {
                throw new InvalidArgumentException('base_path must be an unencoded absolute path without a trailing slash.');
            }

            $this->validate($basePath);
        }

        $this->validateCurrent($currentPath);
    }

    public function to(string $path = '/'): string
    {
        return rtrim($this->basePath, '/') . '/' . $this->suffix($path);
    }

    public function currentPath(): string
    {
        return '/' . $this->encode($this->currentPath);
    }

    public function current(): string
    {
        return rtrim($this->basePath, '/') . $this->currentPath();
    }

    private function suffix(string $path): string
    {
        $this->validate($path);

        return $this->encode($path);
    }

    /** Encode an already decoded path for output. */
    private function encode(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
    }

    /**
     * The policy of an application supplied path.
     *
     * Internal paths are literal: the request path is decoded exactly once
     * at the edge, so a path is never decoded again here.
     */
    private function validate(string $path): void
    {
        if (! str_starts_with($path, '/')) {
            throw new InvalidArgumentException('Invalid internal path.');
        }

        if (str_contains($path, '?') || str_contains($path, '#')) {
            throw new InvalidArgumentException('Expected an internal path without query or fragment.');
        }

        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $path) || ! preg_match('//u', $path)) {
            throw new InvalidArgumentException('Invalid internal path.');
        }

        foreach (explode('/', $path) as $segment) {
            if (str_starts_with($segment, '.')) {
                throw new InvalidArgumentException('Invalid internal path.');
            }
        }

        if (str_contains($path, '//')) {
            throw new InvalidArgumentException('Ambiguous internal path.');
        }
    }

    /**
     * The policy of the current request path.
     *
     * The path was decoded and validated at the edge. It only has to remain a
     * usable absolute path: it is encoded again for output.
     */
    private function validateCurrent(string $path): void
    {
        if (! str_starts_with($path, '/')) {
            throw new InvalidArgumentException('Invalid current path.');
        }

        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $path) || ! preg_match('//u', $path)) {
            throw new InvalidArgumentException('Invalid current path.');
        }
    }
}
