<?php

declare(strict_types=1);

namespace Arix\Markdown;

class RenderedMarkdown
{
    /** @param list<Heading> $headings */
    public function __construct(public readonly string $html, public readonly array $headings) {}
}
