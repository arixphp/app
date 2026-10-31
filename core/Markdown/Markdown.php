<?php

declare(strict_types=1);

namespace Arix\Markdown;

use Arix\Parsedown\Parsedown;

class Markdown
{
    public function __construct(private readonly DocumentReader $reader) {}

    public function read(string $path): string
    {
        return $this->reader->read($path);
    }

    public function html(string $path): string
    {
        return $this->parse($this->read($path));
    }

    public function render(string $path): RenderedMarkdown
    {
        $parser = new HeadingParser();
        $parser->setSafeMode(true);

        return $parser->renderDocument($this->read($path));
    }

    public function parse(string $markdown): string
    {
        $parser = new Parsedown();
        $parser->setSafeMode(true);

        return $parser->text($markdown);
    }
}
