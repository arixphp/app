<?php

declare(strict_types=1);

namespace Arix\Markdown;

class Heading
{
    public function __construct(public readonly int $level, public readonly string $id, public readonly string $title) {}
}
