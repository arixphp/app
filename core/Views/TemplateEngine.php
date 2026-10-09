<?php

declare(strict_types=1);

namespace Arix\Views;

use Arix\Cache\FileCache;
use Arix\Routing\RequestContext;
use InvalidArgumentException;
use RuntimeException;

final class TemplateEngine
{
    private array $active = [];

    public function __construct(
        private readonly string $directory,
        private readonly FileCache $cache,
        private readonly TemplateCompiler $compiler = new TemplateCompiler(),
        private readonly bool $allowEval = false,
    ) {
    }

    public function render(string $template, array $data = [], ?RequestContext $context = null, array $sections = []): string
    {
        $this->validateData($data);
        $source = $this->resolve($template);
        if (in_array($source, $this->active, true) || count($this->active) >= 64) {
            throw new RuntimeException('Circular or excessively nested template: ' . $template);
        }
        $this->active[] = $source;
        $level = ob_get_level();
        try {
            $view = new RenderContext($this, $data, $context, $sections);
            $code = file_get_contents($source);
            if ($code === false) {
                throw new RuntimeException('Cannot read template: ' . $template);
            }
            $key = 'compiled:' . TemplateCompiler::VERSION . ':' . $source . ':' . hash('sha256', $code);
            $native = !str_ends_with($source, '.arix.php');
            // Native templates retain their original __DIR__/__FILE__ semantics.
            $path = $native ? $source : $this->cache->existingPath($key);
            $compiled = null;
            if (!$native && $path === null) {
                $compiled = $this->compiler->compile($code);
                $path = $this->cache->write($key, $compiled);
            }
            $compiler = $this->compiler;
            $allowEval = $this->allowEval;
            // Lazy fallback also handles a concurrent clear, without compiling cache hits.
            $fallback = static fn (): string => $compiled ?? $compiler->compile($code);
            ob_start();
            // Bind native composition to $this; extracted data never owns the runtime.
            $execute = (function (RenderContext $__arixContext, array $__arixData, ?string $__arixPath, \Closure $__arixFallback, bool $__arixAllowEval): void {
                extract($__arixData, EXTR_SKIP);
                if ($__arixPath !== null && is_file($__arixPath)) {
                    require $__arixPath;
                } else {
                    if (!$__arixAllowEval) {
                        throw new RuntimeException('Compiled templates require writable private cache. Use native PHP templates or explicitly enable allow_eval.');
                    }
                    // Only trusted application templates are evaluated, never request data.
                    // Keeps rendering available when shared hosting forbids persistent cache.
                    eval('?>' . $__arixFallback());
                }
            })->bindTo($view, RenderContext::class);
            $execute($view, $data, $path, $fallback, $allowEval);
            if (ob_get_level() !== $level + 1) {
                throw new RuntimeException('Unclosed template buffer.');
            }

            return $view->finish((string) ob_get_clean());
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            array_pop($this->active);
        }
    }

    private function validateData(array $data): void
    {
        foreach ($data as $name => $_) {
            if (!is_string($name) || !preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/D', $name)) {
                throw new InvalidArgumentException('Template data keys must be valid PHP variable names.');
            }
            if (str_starts_with($name, '__arix') || in_array($name, ['this', 'GLOBALS'], true)) {
                throw new InvalidArgumentException('Reserved template variable: ' . $name);
            }
        }
    }

    private function resolve(string $template): string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$/', $template)) {
            throw new RuntimeException('Invalid template name.');
        }
        $base = realpath($this->directory);
        $name = str_replace('.', '/', $template);
        $matches = [];
        foreach (['.arix.php', '.php'] as $extension) {
            $path = realpath($this->directory . '/' . $name . $extension);
            if ($base !== false && $path !== false && is_file($path) && str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
                $matches[] = $path;
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('Missing or conflicting template: ' . $template);
        }

        return $matches[0];
    }
}
