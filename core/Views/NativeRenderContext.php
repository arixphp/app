<?php

declare(strict_types=1);

namespace Arix\Views;

use RuntimeException;

class NativeRenderContext
{
    private null|string $layout = null;

    private array $layoutData = [];

    private array $sections;

    private array $captures = [];

    private array $components = [];

    public function __construct(
        private readonly TemplateEngine $engine,
        private readonly array $data,
        array $sections = [],
    ) {
        $this->sections = $sections;
    }

    public function escape(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    public function extend(string $layout, array $data = []): void
    {
        if ($this->layout !== null) {
            throw new RuntimeException('A template can declare only one layout.');
        }

        $this->layout = $layout;
        $this->layoutData = $data;
    }

    public function startSection(string $name, mixed $value = null): void
    {
        if ($value !== null) {
            $this->sections[$name] = $this->escape($value);

            return;
        }

        $this->capture('section', $name);
    }

    public function endSection(): void
    {
        [$name, $content] = $this->endCapture('section');
        $this->sections[$name] = $content;
    }

    public function yieldSection(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $this->escape($default);
    }

    public function partial(string $template, array $data = []): string
    {
        return $this->engine->render($template, array_replace($this->data, $data));
    }

    public function startComponent(string $template, array $props = []): void
    {
        $this->components[] = ['template' => $template, 'props' => $props, 'slots' => []];
        $this->capture('component', 'slot');
    }

    public function startSlot(string $name): void
    {
        if ($this->components === []) {
            throw new RuntimeException('Slots require a component.');
        }

        $this->capture('slot', $name);
    }

    public function endSlot(): void
    {
        [$name, $content] = $this->endCapture('slot');
        $this->components[array_key_last($this->components)]['slots'][$name] = $content;
    }

    public function endComponent(): string
    {
        [, $slot] = $this->endCapture('component');
        $component = array_pop($this->components);

        return $this->engine->render($component['template'], array_replace($component['props'], $component['slots'], ['slot' => $slot]));
    }

    public function finish(string $content): string
    {
        if ($this->captures !== [] || $this->components !== []) {
            throw new RuntimeException('Unclosed section, component or slot.');
        }

        if ($this->layout !== null) {
            return $this->engine->render($this->layout, array_replace($this->data, $this->layoutData), $this->sections);
        }

        return $content;
    }

    private function capture(string $type, string $name): void
    {
        $this->captures[] = [$type, $name, ob_get_level()];
        ob_start();
    }

    private function endCapture(string $type): array
    {
        $capture = array_pop($this->captures);

        if ($capture === null || $capture[0] !== $type || ob_get_level() !== $capture[2] + 1) {
            throw new RuntimeException("Mismatched template capture: {$type}");
        }

        return [$capture[1], (string) ob_get_clean()];
    }
}
