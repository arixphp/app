<?php

declare(strict_types=1);

namespace Arix\Autoload;

use JsonException;
use RuntimeException;
use stdClass;

use function in_array;
use function is_array;
use function is_string;
use function strlen;

class AutoloadConfiguration
{
    protected readonly string $filePath;

    protected readonly string $root;

    /** @var array<string, string> */
    protected array $prefixes = [];

    /** @var list<string> */
    protected array $files = [];

    /** @var array<string, string> */
    protected array $devPrefixes = [];

    protected function __construct(string $root)
    {
        if ($root === '' || str_contains($root, "\0") || str_contains($root, '://')) {
            throw new RuntimeException('Invalid core directory.');
        }

        $directory = realpath($root);

        if ($directory === false || ! is_dir($directory)) {
            throw new RuntimeException('Core directory is missing or unreadable.');
        }

        $this->root = $directory;
        $this->filePath = $directory . DIRECTORY_SEPARATOR . '.arix.json';
    }

    public static function read(string $root): self
    {
        return new self($root)->parse();
    }

    public function root(): string
    {

        return $this->root;
    }

    /** @return array<string, string> */
    public function prefixes(): array
    {
        return $this->prefixes;
    }

    /** @return array<string, string> */
    public function devPrefixes(): array
    {
        return $this->devPrefixes;
    }

    /** @return list<string> */
    public function files(): array
    {
        return $this->files;
    }

    protected function parse(): self
    {
        clearstatcache(true, $this->filePath);

        if (! $this->configFileIsValid()) {
            throw new RuntimeException('Application .arix.json is missing, linked or unreadable.');
        }

        $contents = @file_get_contents($this->filePath, length: 65537);

        if ($contents === false || strlen($contents) > 65536) {
            throw new RuntimeException('Invalid application manifest size.');
        }

        try {
            $manifest = json_decode($contents, depth: 32, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid application manifest JSON.', previous: $exception);
        }

        if (! $manifest instanceof stdClass || ($manifest->version ?? null) !== '1.0.0') {
            throw new RuntimeException('Unsupported application compatibility contract version.');
        }

        $this->validateFields($manifest, ['version', 'autoload', 'autoload-dev']);

        $autoload = $manifest->autoload ?? null;
        $development = property_exists($manifest, 'autoload-dev') ? $manifest->{'autoload-dev'} : new stdClass();

        if (! $autoload instanceof stdClass || ! $development instanceof stdClass) {
            throw new RuntimeException('Autoload configuration must be an object.');
        }

        $this->validateFields($autoload, ['psr-4', 'files']);
        $this->validateFields($development, ['psr-4']);

        $prefixes = $this->parsePrefixes(
            property_exists($autoload, 'psr-4') ? $autoload->{'psr-4'} : new stdClass()
        );

        $devPrefixes = $this->parsePrefixes(
            property_exists($development, 'psr-4') ? $development->{'psr-4'} : new stdClass()
        );

        $files = property_exists($autoload, 'files') ? $autoload->files : [];

        if (! is_array($files) || ! array_is_list($files)) {
            throw new RuntimeException('Autoload files must be a list.');
        }

        foreach ($files as $file) {
            $this->validatePath($file, directory: false);
        }

        $this->prefixes = $prefixes;
        $this->devPrefixes = $devPrefixes;
        $this->files = $files;

        return $this;
    }

    protected function configFileIsValid(): bool
    {
        return ! is_link($this->filePath)
            && in_array('.arix.json', @scandir(dirname($this->filePath)) ?: [], true)
            && is_file($this->filePath)
            && is_readable($this->filePath);
    }

    /** @param list<string> $allowed */
    protected function validateFields(stdClass $section, array $allowed): void
    {
        foreach ($section as $field => $_value) {
            if (! in_array($field, $allowed, true)) {
                throw new RuntimeException("Unsupported manifest field: {$field}");
            }
        }
    }

    /** @return array<string, string> */
    protected function parsePrefixes(mixed $mappings): array
    {
        if (! $mappings instanceof stdClass) {
            throw new RuntimeException('PSR-4 mappings must be an object.');
        }

        $prefixes = [];

        foreach ($mappings as $prefix => $directory) {
            if (! preg_match('/^(?:[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\\\\)+$/D', $prefix)) {
                throw new RuntimeException('Invalid PSR-4 namespace prefix.');
            }

            $this->validatePath($directory, directory: true);

            $prefixes[$prefix] = $directory;
        }

        return $prefixes;
    }

    protected function validatePath(mixed $path, bool $directory): void
    {
        if (! is_string($path) || $path === '' || preg_match('~[\x00-\x1f\x7f\\\\:]~', $path)) {
            throw new RuntimeException('Autoload paths must be local relative paths.');
        }

        if ($directory && str_ends_with($path, '/')) {
            $path = substr($path, 0, -1);
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || rtrim($segment, '. ') !== $segment) {
                throw new RuntimeException('Invalid relative autoload path.');
            }
        }
    }
}
