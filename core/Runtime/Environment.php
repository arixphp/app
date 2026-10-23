<?php

declare(strict_types=1);

namespace Arix\Runtime;

use Arix\Exceptions\CoreRuntimeException;
use Arix\Support\Path;
use Arix\Support\RuntimePaths;
use Closure;

use function array_key_exists;
use function in_array;

class Environment
{
    public const string ENV_FILE = '.env';

    private static string|null $activeRoot = null;

    protected readonly string $envFilePath;

    public function __construct(protected readonly string $path)
    {
        $this->envFilePath = Path::join($this->path, self::ENV_FILE);
    }

    /** Run synchronously with an application-local overlay; never modify getenv(). */
    public static function run(string $root, Closure $callback): mixed
    {
        $root = RuntimePaths::fromApplicationRoot($root)->root;

        if (self::$activeRoot !== null) {

            if (self::$activeRoot !== $root) {
                throw new CoreRuntimeException('Different application environments cannot overlap.');
            }

            return $callback();
        }

        $file = new self($root)->getFilePath();
        $values = $file === null ? [] : new EnvFileParser($file)->parse();
        $previous = [];
        self::$activeRoot = $root;

        try {

            foreach ($values as $key => $value) {

                if (str_starts_with($key, 'APP_')) {
                    $previous[$key] = [array_key_exists($key, $_ENV), $_ENV[$key] ?? null];
                    $_ENV[$key] = $value;
                }

            }

            return $callback();
        } finally {

            foreach ($previous as $key => [$existed, $value]) {

                if ($existed) {
                    $_ENV[$key] = $value;
                } else {
                    unset($_ENV[$key]);
                }

            }

            self::$activeRoot = null;
        }
    }

    public static function debug(): bool
    {

        return in_array(env('APP_DEBUG', false), [true, 1, '1'], true);
    }

    protected function getFilePath(): string|null
    {
        clearstatcache(true, $this->envFilePath);

        if (! file_exists($this->envFilePath) && ! Path::isLink($this->envFilePath)) {

            return null;
        }

        return $this->envFilePath;
    }
}
