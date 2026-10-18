<?php

declare(strict_types=1);

namespace Arix\Assets;

use InvalidArgumentException;
use JsonException;

use function count;
use function in_array;
use function is_array;
use function is_string;
use function strlen;

class AssetPolicy
{
    private readonly array $metadata;

    public function __construct(string|null $file = null)
    {
        $file ??= __DIR__ . DIRECTORY_SEPARATOR . 'public-policy.json';

        if (is_link($file) || ! is_file($file) || ! is_readable($file)) {
            throw new InvalidArgumentException('Public asset policy is unavailable.');
        }

        $size = @filesize($file);
        $contents = $size !== false && $size <= 8192 ? @file_get_contents($file) : false;

        if ($contents === false || strlen($contents) > 8192) {
            throw new InvalidArgumentException('Public asset policy is unavailable.');
        }

        try {
            $metadata = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid public asset policy.', previous: $exception);
        }

        if (! is_array($metadata) || array_keys($metadata) !== ['format', 'directories', 'root_files'] || $metadata['format'] !== 2
            || ! is_array($metadata['directories']) || array_keys($metadata['directories']) !== ['styles', 'scripts', 'fonts', 'media']
            || $metadata['directories']['styles'] !== ['css'] || $metadata['directories']['scripts'] !== ['js']
            || $metadata['root_files'] !== ['favicon.ico']) {
            throw new InvalidArgumentException('Unsupported public asset policy.');
        }

        $safeMedia = ['avif', 'gif', 'ico', 'jpg', 'jpeg', 'png', 'webp', 'mp4', 'webm', 'ogv'];

        foreach ($metadata['directories'] as $kind => $extensions) {

            if (! is_array($extensions) || ! array_is_list($extensions) || $extensions === [] || count(array_unique($extensions, SORT_REGULAR)) !== count($extensions)) {
                throw new InvalidArgumentException('Invalid public asset extensions.');
            }

            foreach ($extensions as $extension) {

                $allowed = match ($kind) {
                    'styles' => ['css'], 'scripts' => ['js'], 'fonts' => ['woff', 'woff2', 'ttf', 'otf'], default => $safeMedia,
                };

                if (! is_string($extension) || ! in_array($extension, $allowed, true)) {
                    throw new InvalidArgumentException('Unsafe public asset extension.');
                }
            }
        }

        $this->metadata = $metadata;
    }

    public function normalize(string $path): string
    {

        if ($path === '' || strlen($path) > 4096 || str_starts_with($path, '//') || preg_match('/%(?![a-f0-9]{2})|%(?:2f|5c)/i', $path)) {
            throw new InvalidArgumentException('Invalid public asset path.');
        }

        if (str_starts_with($path, '/')) {
            throw new InvalidArgumentException('Invalid public asset path.');
        }

        $relative = $path;

        if (str_contains($relative, '%') || preg_match('~\A' . $this->relativePattern() . '\z~D', $relative) !== 1) {
            throw new InvalidArgumentException('Asset is not an allowed public file.');
        }

        return $relative;
    }

    public function resolve(AssetType $type, string $path): string
    {
        if (preg_match('/%(?![a-f0-9]{2})|%(?:2f|5c)/i', $path)) {
            throw new InvalidArgumentException('Invalid public asset encoding.');
        }

        $path = rawurldecode($path);

        if (preg_match('~\A(?:styles|scripts|fonts|media|assets)(?:/|\z)~i', $path) === 1) {
            throw new InvalidArgumentException('Asset helper paths must be relative.');
        }

        return $this->normalize("{$type->value}/{$path}");
    }

    /** @return array<string, array<int, string>> */
    public function directories(): array
    {
        return $this->metadata['directories'];
    }

    /** A generated relative-path regex; callers supply anchors appropriate to their engine. */
    public function relativePattern(): string
    {
        $patterns = $this->patterns();
        return '(?:' . implode('|', $patterns) . ')';
    }

    /** @return array<string, string> Safe fragments without anchors or lookarounds (Caddy uses RE2). */
    public function patterns(): array
    {
        $nested = '(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\\.';
        $parts = [];

        foreach ($this->metadata['directories'] as $directory => $extensions) {
            $parts[$directory] = "{$directory}/{$nested}(?i:" . implode('|', $extensions) . ')';
        }

        $parts['favicon'] = 'favicon\\.ico';

        return $parts;
    }
}
