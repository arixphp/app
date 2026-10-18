<?php

declare(strict_types=1);

namespace Arix\Assets;

use InvalidArgumentException;

use function in_array;

class AssetLocator
{
    private readonly string $root;

    public function __construct(string $root, private readonly AssetPolicy $policy)
    {
        $ancestor = $root;

        while (true) {
            if (is_link($ancestor)) {
                throw new InvalidArgumentException('Linked asset roots are not allowed.');
            }

            $parent = dirname($ancestor);

            if ($parent === $ancestor) {
                break;
            }

            $ancestor = $parent;
        }

        $real = realpath($root);

        if ($real === false || ! is_dir($real)) {
            throw new InvalidArgumentException('Asset root is missing.');
        }

        $this->root = $real;
    }

    public function locate(string $path): string
    {
        $relative = $this->policy->normalize($path);
        $file = $this->root;

        foreach (explode('/', $relative) as $segment) {

            if (! is_dir($file) || ! in_array($segment, scandir($file) ?: [], true)) {
                throw new InvalidArgumentException('Public asset is missing.');
            }

            $file .= DIRECTORY_SEPARATOR . $segment;

            clearstatcache(true, $file);

            if (is_link($file) || realpath($file) !== $file) {
                throw new InvalidArgumentException('Linked public assets are not allowed.');
            }
        }

        if (! is_file($file) || ! is_readable($file)) {
            throw new InvalidArgumentException('Public asset is not readable.');
        }

        return $file;
    }
}
