<?php

declare(strict_types=1);

namespace Arix\Views;

use Arix\BladeOne\BladeOne;
use Closure;
use RuntimeException;

use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_string;

class BladeAdapter extends BladeOne
{
    private array $active = [];

    private null|Closure $nativeRunner = null;

    public function __construct(
        private readonly TemplateResolver $resolver,
        private readonly CompiledTemplateCache $cache,
    ) {
        // Template paths are resolved through TemplateResolver; the parent
        // lookup is never used. Compiled files live in the private cache.
        parent::__construct([], $this->cache->directory(), self::MODE_AUTO, 0);
        $this->directive('json', static fn (string $expression): string => '<?php echo json_encode(' . $expression . ', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR); ?>');
    }

    public function setNativeRunner(Closure $runner): void
    {
        $this->nativeRunner = $runner;
    }

    public static function get($array, $key, $default = null): mixed
    {
        if (is_int($key)) {
            $key = (string) $key;
        }

        return parent::get($array, $key, $default);
    }

    public function run(null|string $view = null, array $variables = [], array $seed = []): string
    {
        if ($view === null) {
            return parent::run(null, $variables);
        }

        return $this->guarded($view, function () use ($view, $variables, $seed): string {
            // run() clears sections first, so incoming sections are seeded
            // after the reset instead of before the call.
            $this->sections = [];

            foreach ($seed as $name => $content) {
                if (! is_string($name) || ! is_string($content)) {
                    throw new RuntimeException('Invalid template section.');
                }

                $this->sections[$name] = $content;
            }

            return $this->runInternal($view, $variables, false, false);
        });
    }

    public function runChild($view, $variables = []): string
    {
        if (! is_string($view) || $view === '') {
            throw new RuntimeException('Invalid nested template name.');
        }

        if (! is_array($variables)) {
            throw new RuntimeException('Nested template variables must be an array.');
        }

        $source = $this->resolver->resolve($view);

        if (! str_ends_with($source, '.arix.php')) {
            if ($this->nativeRunner === null) {
                throw new RuntimeException('Native templates require a renderer.');
            }

            return ($this->nativeRunner)($view, array_merge($this->variables, $variables), $this->sections);
        }

        return $this->guarded($view, fn (): string => parent::runChild($view, $variables));
    }

    public function runString(string $string, array $data = []): string
    {
        throw new RuntimeException('Template strings cannot be evaluated.');
    }

    public function startSection($section, $content = ''): void
    {
        if ($content === '') {
            parent::startSection($section);

            return;
        }

        // Inline sections carry data, not markup: escape like {{ }} so
        // request values cannot break out of the layout.
        parent::startSection($section, self::e($content));
    }

    public function yieldContent($section, $default = ''): string
    {
        if (isset($this->sections[$section])) {
            return parent::yieldContent($section, $default);
        }

        return self::e($default);
    }

    public function setIsCompiled(bool $bool = false): BladeOne
    {
        if (! $bool) {
            throw new RuntimeException('Templates must be compiled to files.');
        }

        return parent::setIsCompiled($bool);
    }

    public function getTemplateFile(null|string $templateName = ''): string
    {
        $templateName = empty($templateName) ? $this->fileName : $templateName;

        if (isset($this->templateFileCache[$templateName])) {
            return $this->templateFileCache[$templateName];
        }

        return $this->templateFileCache[$templateName] = $this->resolver->resolve($templateName);
    }

    public function getCompiledFile(null|string $templateName = ''): string
    {
        $templateName = empty($templateName) ? $this->fileName : $templateName;

        if (isset($this->compiledFileCache[$templateName])) {
            return $this->compiledFileCache[$templateName];
        }

        $source = $this->getTemplateFile($templateName);
        $code = file_get_contents($source);

        if ($code === false) {
            throw new RuntimeException("Cannot read template: {$templateName}");
        }

        return $this->compiledFileCache[$templateName] = $this->cache->path($source, $code);
    }

    public function compile(null|string $templateName = null, bool $forced = false)
    {
        $name = $templateName ?? '';

        if ($name === '') {
            $name = $this->fileName;
        }

        $source = $this->getTemplateFile($name);
        $code = file_get_contents($source);

        if ($code === false) {
            throw new RuntimeException("Cannot read template: {$name}");
        }

        $compiled = $this->cache->path($source, $code);

        if (! is_file($compiled)) {
            $php = $this->compileString($code);
            $this->compileCallBacks($php, $name);
            $this->cache->write($compiled, $php);
        }

        return $this->compiledFileCache[$name] = $compiled;
    }

    public function isExpired(null|string $fileName): bool
    {
        // Compiled paths embed a content hash, so freshness never depends
        // on modification times.
        return ! is_file($this->getCompiledFile($fileName ?? ''));
    }

    public function showError(string $id, string $text, bool $critic = false, bool $alwaysThrow = false): string
    {
        throw new RuntimeException("Template error [{$id}]: {$text}");
    }

    private function guarded(string $view, Closure $render): string
    {
        $source = $this->resolver->resolve($view);

        if (in_array($source, $this->active, true) || count($this->active) >= 64) {
            throw new RuntimeException("Circular or excessively nested template: {$view}");
        }

        $this->active[] = $source;

        try {
            return $render();
        } finally {
            array_pop($this->active);
        }
    }
}
