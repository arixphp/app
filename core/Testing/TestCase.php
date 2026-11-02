<?php

declare(strict_types=1);

namespace Arix\Testing;

use Arix\Constants\HttpMethod;
use Arix\Http\Kernel;
use Arix\HttpFoundation\Request;
use Arix\Runtime\Environment;
use Arix\Support\RuntimePaths;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase as BaseTestCase;
use RuntimeException;

class TestCase extends BaseTestCase
{
    public function get(string $path): TestResponse
    {
        return $this->call(HttpMethod::GET, $path);
    }

    public function post(string $path): TestResponse
    {
        return $this->call(HttpMethod::POST, $path);
    }

    public function put(string $path): TestResponse
    {
        return $this->call(HttpMethod::PUT, $path);
    }

    public function patch(string $path): TestResponse
    {
        return $this->call(HttpMethod::PATCH, $path);
    }

    public function delete(string $path): TestResponse
    {
        return $this->call(HttpMethod::DELETE, $path);
    }

    public function call(HttpMethod $method, string $path): TestResponse
    {
        if (! str_starts_with($path, '/')) {
            throw new InvalidArgumentException('Test request path must start with "/".');
        }

        $root = $this->applicationRoot();

        Environment::load($root);
        $bootstrap = RuntimePaths::fromApplicationRoot($root)->resolve(['app', 'bootstrap.php']);

        if ($bootstrap === null || ! is_file($bootstrap) || ! is_readable($bootstrap)) {
            throw new RuntimeException('Application bootstrap is missing or unreadable.');
        }

        $kernel = require $bootstrap;

        if (! $kernel instanceof Kernel) {
            throw new RuntimeException('Application bootstrap must return an Arix\\Http\\Kernel.');
        }

        return new TestResponse($kernel->handle(Request::create($path, $method->value)));
    }

    protected function applicationRoot(): string
    {
        return (string) getcwd();
    }
}
