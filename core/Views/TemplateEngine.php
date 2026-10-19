<?php

declare(strict_types=1);

namespace Arix\Views;

use RuntimeException;

use function count;
use function in_array;

class TemplateEngine
{
    public const string VERSION = '2';

    private array $active = [];

    private bool $renderingBlade = false;

    public function __construct(
        private readonly string $directory,
        private readonly string $cacheDirectory,
        private readonly string $version = self::VERSION,
        private readonly TemplateCachePolicy|null $cachePolicy = null,
    ) {}

    public function render(string $name, array $data = [], array $sections = []): string
    {
        $view = new View($name, $data);
        $resolver = new TemplateResolver($this->directory);
        $source = $resolver->resolve($view->name());
        $this->enter($source, $view->name());

        try {
            if (str_ends_with($source, '.arix.php')) {
                return $this->renderBlade($resolver, $view, $sections);
            }

            return $this->executeNative($resolver, $view, $sections);
        } finally {
            array_pop($this->active);
        }
    }

    public function renderNative(string $name, array $data, array $sections = []): string
    {
        $view = new View($name, $data);
        $resolver = new TemplateResolver($this->directory);
        $source = $resolver->resolve($view->name());

        if (str_ends_with($source, '.arix.php')) {
            throw new RuntimeException("Native rendering requires a PHP template: {$view->name()}");
        }

        $this->enter($source, $view->name());

        try {
            return $this->executeNative($resolver, $view, $sections);
        } finally {
            array_pop($this->active);
        }
    }

    private function renderBlade(TemplateResolver $resolver, View $view, array $sections): string
    {
        if ($this->renderingBlade) {

            return $this->executeBlade($resolver, $view, $sections);
        }

        $maintenance = new TemplateCacheMaintenance($this->cacheDirectory, $this->cachePolicy ?? new TemplateCachePolicy());

        return $maintenance->run(function () use ($resolver, $view, $sections): string {
            $this->renderingBlade = true;

            try {

                return $this->executeBlade($resolver, $view, $sections);
            } finally {
                $this->renderingBlade = false;
            }
        });
    }

    private function executeBlade(TemplateResolver $resolver, View $view, array $sections): string
    {
        $adapter = new BladeAdapter($resolver, new CompiledTemplateCache($this->cacheDirectory, $this->version));
        $adapter->setNativeRunner(fn (string $template, array $data, array $incoming): string => $this->renderNative($template, $data, $incoming));
        $level = ob_get_level();

        try {
            $html = $adapter->run($view->name(), $view->data(), $sections);

            if (ob_get_level() !== $level) {
                throw new RuntimeException('Unclosed template buffer.');
            }

            return $html;
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    private function executeNative(TemplateResolver $resolver, View $view, array $sections): string
    {
        $source = $resolver->resolve($view->name());
        $context = new NativeRenderContext($this, $view->data(), $sections);
        $level = ob_get_level();
        $execute = (function (NativeRenderContext $__arixContext, array $__arixData, string $__arixPath): void {
            extract($__arixData, EXTR_SKIP);
            require $__arixPath;
        })->bindTo($context, NativeRenderContext::class);

        try {
            ob_start();
            $execute($context, $view->data(), $source);

            if (ob_get_level() !== $level + 1) {
                throw new RuntimeException('Unclosed template buffer.');
            }

            return $context->finish((string) ob_get_clean());
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    private function enter(string $source, string $name): void
    {
        if (in_array($source, $this->active, true) || count($this->active) >= 64) {
            throw new RuntimeException("Circular or excessively nested template: {$name}");
        }

        $this->active[] = $source;
    }
}
