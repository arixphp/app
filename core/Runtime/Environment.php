<?php

declare(strict_types=1);

namespace Arix\Runtime;

use Arix\Support\Path;
use Arix\Support\RuntimePaths;

use function array_key_exists;
use function in_array;

class Environment
{
    public const string ENV_FILE = '.env';

    protected readonly string $envFilePath;

    public function __construct(protected readonly string $path)
    {
        $this->envFilePath = Path::join($this->path, self::ENV_FILE);
    }

    public static function load(string $root): void
    {
        $root = RuntimePaths::fromApplicationRoot($root)->root;
        $file = new self($root)->getFilePath();
        $values = $file === null ? [] : new EnvFileParser($file)->parse();

        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $_ENV) && getenv($key) === false) {
                $_ENV[$key] = $value;
            }
        }
    }

    public static function debug(): bool
    {
        return in_array(env('APP_DEBUG', false), [true, 1, '1'], true);
    }

    protected function getFilePath(): string|null
    {
        clearstatcache(true, $this->envFilePath);

        if (! file_exists($this->envFilePath)) {

            return null;
        }

        return $this->envFilePath;
    }
}
