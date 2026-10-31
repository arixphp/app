<?php

declare(strict_types=1);

namespace Arix\Facades;

use Arix\Markdown\Markdown as MarkdownService;
use Arix\Runtime\Facade;

class Markdown extends Facade
{
    protected static function getKeyName(): string
    {
        return MarkdownService::class;
    }
}
