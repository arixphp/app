<?php

declare(strict_types=1);

namespace Arix\Testing;

use Arix\Application;
use Arix\Constants\HttpMethod;
use Arix\HttpFoundation\Request;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase as BaseTestCase;

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

        $response = Application::create($this->applicationRoot())->kernel()->handle(Request::create($path, $method->value));

        return new TestResponse($response);
    }

    protected function applicationRoot(): string
    {
        return (string) getcwd();
    }
}
