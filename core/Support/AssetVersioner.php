<?php

declare(strict_types=1);

namespace Arix\Support;

use Arix\Routing\UrlGenerator;
use InvalidArgumentException;

use function in_array;

class AssetVersioner
{
    private readonly string $root;

    /** @var array<string, string> */
    private array $hashes = [];

    public function __construct(string $root, private readonly UrlGenerator $urls)
    {
        $real = realpath($root);
        if ($real === false || ! is_dir($real)) {
            throw new InvalidArgumentException('Asset root is missing.');
        }
        $this->root = $real;
    }

    public function asset(string $path): string
    {
        if (str_starts_with($path, '//')) {
            throw new InvalidArgumentException('Invalid public asset path.');
        }
        $this->urls->asset($path);
        $relative = ltrim(rawurldecode($path), '/');
        $allowed = preg_match('~\A(?:styles/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.(?i:css)|scripts/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.(?i:js)|assets/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.(?i:avif|gif|ico|jpe?g|png|webp|mp4|webm|ogv|woff2?|ttf|otf))\z~', $relative) === 1;
        if (! $allowed || (str_starts_with($relative, 'assets/fonts/') && preg_match('/\.(?:woff2?|ttf|otf)\z/i', $relative) !== 1)) {
            throw new InvalidArgumentException('Asset is not an allowed public file.');
        }
        $file = $this->root;
        foreach (explode('/', $relative) as $segment) {
            if (! is_dir($file) || ! in_array($segment, scandir($file) ?: [], true)) {
                throw new InvalidArgumentException("Public asset is missing: {$relative}.");
            }
            $file .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($file)) {
                throw new InvalidArgumentException('Linked public assets are not allowed.');
            }
        }
        if (! is_file($file) || ! is_readable($file)) {
            throw new InvalidArgumentException("Public asset is not readable: {$relative}.");
        }
        if (! isset($this->hashes[$relative])) {
            $hash = hash_file('sha256', $file);
            if ($hash === false) {
                throw new InvalidArgumentException('Cannot hash public asset.');
            }
            $this->hashes[$relative] = $hash;
        }
        $url = $this->urls->to('/' . $relative);

        return "{$url}?v={$this->hashes[$relative]}";
    }
}
