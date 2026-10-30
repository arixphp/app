<?php

declare(strict_types=1);

namespace Arix\Assets;

use InvalidArgumentException;

class AssetVersioner
{
    /** @var array<string, string> */
    private array $hashes = [];

    public function __construct(private readonly AssetLocator $locator) {}

    public function hash(string $path): string
    {
        $file = $this->locator->locate($path);

        if (! isset($this->hashes[$path])) {
            $hash = hash_file('sha256', $file);

            if ($hash === false) {
                throw new InvalidArgumentException('Cannot hash public asset.');
            }

            $this->hashes[$path] = $hash;
        }

        return $this->hashes[$path];
    }
}
