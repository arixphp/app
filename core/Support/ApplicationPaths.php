<?php

declare(strict_types=1);

namespace Arix\Support;

class ApplicationPaths
{
    public function __construct(private readonly string $root)
    {
    }

    public function base(string $path = ''): string
    {
        if ($path === '') {
            return $this->root;
        }

        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        return Path::join(rtrim($this->root, '/\\'), ltrim($path, '/\\'));
    }
}
