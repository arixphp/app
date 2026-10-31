<?php

declare(strict_types=1);

namespace Arix\Markdown;

use Arix\Parsedown\Parsedown;
use InvalidArgumentException;

use function is_array;
use function is_string;

class HeadingParser extends Parsedown
{
    public function renderDocument(string $markdown): RenderedMarkdown
    {
        $elements = $this->handleElementsRecursive($this->textElements($markdown));
        $reserved = [];

        $this->walk($elements, function (array &$element) use (&$reserved): void {
            if (! isset($element['arixHeadingId'])) {
                return;
            }

            $id = $element['arixHeadingId'];

            if (isset($reserved[$id])) {
                throw new InvalidArgumentException("Duplicate heading ID: {$id}");
            }

            $reserved[$id] = true;
        });

        $headings = [];

        $this->walk($elements, function (array &$element) use (&$reserved, &$headings): void {
            if (! isset($element['name']) || ! preg_match('/^h[1-6]$/', $element['name'])) {
                return;
            }

            $title = html_entity_decode($this->plainText($element), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $id = $element['arixHeadingId'] ?? null;

            if ($id === null) {
                $base = mb_strtolower($title, 'UTF-8');
                $base = preg_replace('/\s+/u', '-', $base);
                $base = trim(preg_replace('/[^\p{L}\p{N}_-]/u', '', $base), '-');
                $base = preg_replace('/-+/u', '-', $base);
                $base = $base === '' ? 'section' : $base;
                $id = $base;
                $suffix = 2;

                while (isset($reserved[$id])) {
                    $id = "{$base}-{$suffix}";
                    $suffix++;
                }

                $reserved[$id] = true;
            }

            $element['attributes']['id'] = $id;
            $headings[] = new Heading((int) substr($element['name'], 1), $id, $title);
        });

        return new RenderedMarkdown(trim($this->elements($elements), "\n"), $headings);
    }

    protected function handle(array $element)
    {
        if (isset($element['name'], $element['handler']['argument'])
            && preg_match('/^h[1-6]$/', $element['name'])
            && is_string($element['handler']['argument'])
            && preg_match('/\s+\{#([^}]*)\}\s*$/u', $element['handler']['argument'], $matches)) {
            $id = $matches[1];
            if (! preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $id)) {
                throw new InvalidArgumentException('Invalid heading ID.');
            }

            $element['arixHeadingId'] = $id;
            $element['handler']['argument'] = substr($element['handler']['argument'], 0, -strlen($matches[0]));
        }

        return parent::handle($element);
    }

    private function walk(array &$elements, callable $callback): void
    {
        foreach ($elements as &$element) {
            $callback($element);

            if (isset($element['elements']) && is_array($element['elements'])) {
                $this->walk($element['elements'], $callback);
            } elseif (isset($element['element']) && is_array($element['element'])) {
                $children = [&$element['element']];
                $this->walk($children, $callback);
            }
        }
    }

    private function plainText(array $element): string
    {
        if (($element['name'] ?? null) === 'img') {
            return $element['attributes']['alt'] ?? '';
        }

        $text = $element['text'] ?? $element['rawHtml'] ?? '';

        foreach ($element['elements'] ?? [] as $child) {
            $text .= $this->plainText($child);
        }

        if (isset($element['element'])) {
            $text .= $this->plainText($element['element']);
        }

        return $text;
    }
}
