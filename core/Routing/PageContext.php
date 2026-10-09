<?php

declare(strict_types=1);

namespace Arix\Routing;

use Arix\Http\Request;
use Arix\Http\PublicAssets;
use Arix\Localization\Translator;

final class PageContext
{
    public function __construct(
        public readonly Request $request,
        public readonly Translator $translator,
        public readonly string $path,
        private readonly string $basePath,
    ) {
    }

    public function url(string $path = '/', ?string $locale = null): string
    {
        $locale ??= $this->translator->locale;
        if (!in_array($locale, $this->translator->locales, true)) {
            throw new \InvalidArgumentException('Unsupported locale.');
        }

        return $this->basePath . '/' . $locale . (trim($path, '/') === '' ? '' : '/' . trim($path, '/'));
    }

    public function asset(string $path): string
    {
        $path = ltrim($path, '/');
        if (!PublicAssets::allows($path)) {
            throw new \InvalidArgumentException('Invalid public asset path.');
        }

        return $this->basePath . '/' . $path;
    }
}
