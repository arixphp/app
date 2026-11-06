<?php

declare(strict_types=1);

namespace Arix\Markdown;

use Arix\Parsedown\Parsedown;

use function strlen;

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
        $source = $this->read($path);
        $frontmatter = null;

        if (preg_match('/\A---\r?\n(.*?)^---(?:\r?\n|\z)/ms', $source, $matches)) {
            $frontmatter = new Yaml()->parse($matches[1]);

            if ($frontmatter !== null) {
                $source = substr($source, strlen($matches[0]));
            }
        }

        $parser = new HeadingParser();
        $parser->setSafeMode(true);
        $document = $parser->renderDocument($source);

        return new RenderedMarkdown($document->html, $document->headings, $frontmatter);
    }

    public function parse(string $markdown): string
    {
        $parser = new Parsedown();
        $parser->setSafeMode(true);

        return $parser->text($markdown);
    }
}
