<?php

declare(strict_types=1);

namespace Arix\Testing;

use Arix\Application;
use Arix\Container\ContainerContext;
use Arix\Http\Request;
use Countable;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

use function in_array;

abstract class TestCase
{
    protected string $temporary = '';
    private int $redirectsToFollow = 0;

    protected function setUp(): void
    {
        $this->temporary = rtrim(sys_get_temp_dir(), '/') . '/arix-test-' . bin2hex(random_bytes(8));

        mkdir($this->temporary, 0700, true);

        $this->redirectsToFollow = 0;
    }

    protected function tearDown(): void
    {
        $this->removeTemporary();

        try {
            ContainerContext::current();
        } catch (LogicException) {
            return;
        }

        Assert::fail('Container scope leaked outside the request.');
    }

    protected function projectRoot(): string
    {
        $root = realpath(__DIR__ . '/../..');

        return $root !== false ? $root : dirname(__DIR__, 2);
    }

    protected function application(string $site = '', array $overrides = [], array $cacheOverrides = []): Application
    {
        $root = $site === '' ? $this->projectRoot() : $this->temporary . '/' . $site;

        return new Application($root, $overrides, ['path' => $this->temporary] + $cacheOverrides);
    }

    protected function fixture(string $name, string $content): string
    {
        $path = $this->temporary . DIRECTORY_SEPARATOR . $name;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        file_put_contents($path, $content);

        return $path;
    }

    /** Register a fixture controller without introducing discovery or autowiring. */
    protected function controllerRoutes(string $site, string $class, string $path = '/', int $ttl = 0, array $query = [], ?string $factory = null): void
    {
        $id = "App\\Controllers\\{$class}";
        $factory ??= "new \\{$id}()";

        $this->fixture("$site/app/services.php", '<?php return static function (\\Arix\\Container\\Container $container): void { $container->bind(' . var_export($id, true) . ', static fn (\\Arix\\Container\\Container $c) => ' . $factory . '); };');
        $this->fixture("$site/app/routes.php", '<?php \\Arix\\Facades\\Route::get(' . var_export($path, true) . ', [' . var_export($id, true) . ', "index"])' . ($ttl > 0 ? '->cache(' . $ttl . ', ' . var_export($query, true) . ')' : '') . ';');
    }

    protected function followingRedirects(int $max = 5): static
    {
        $this->redirectsToFollow = $max;

        return $this;
    }

    protected function handle(Application $application, Request $request): TestResponse
    {
        return new TestResponse($application->handle($request));
    }

    /** @param array<string, string> $query */
    protected function call(Application $application, string $method, string $path, array $query = [], string $body = '', array $headers = []): TestResponse
    {
        $request = new Request($method, $this->uri($path, $query), $headers, $query, $body);
        $remaining = $this->redirectsToFollow;
        $response = $application->handle($request);

        while ($remaining > 0 && in_array($response->status, [301, 302, 303, 307, 308], true) && isset($response->headers['Location'])) {
            $remaining--;
            $location = $response->headers['Location'];
            $followQuery = [];
            if (str_contains($location, '?')) {
                parse_str((string) parse_url($location, PHP_URL_QUERY), $followQuery);
            }
            $followMethod = $response->status === 303 ? 'GET' : $request->method;
            $request = new Request($followMethod, $location, $request->headers, $followQuery, $followMethod === 'GET' ? '' : $request->body());
            $response = $application->handle($request);
        }

        return new TestResponse($response);
    }

    /** @param array<string, string> $query */
    protected function get(Application $application, string $path, array $query = [], array $headers = []): TestResponse
    {
        return $this->call($application, 'GET', $path, $query, '', $headers);
    }

    protected function post(Application $application, string $path, string $body = '', array $headers = [], array $query = []): TestResponse
    {
        return $this->call($application, 'POST', $path, $query, $body, $headers);
    }

    protected function put(Application $application, string $path, string $body = '', array $headers = [], array $query = []): TestResponse
    {
        return $this->call($application, 'PUT', $path, $query, $body, $headers);
    }

    protected function patch(Application $application, string $path, string $body = '', array $headers = [], array $query = []): TestResponse
    {
        return $this->call($application, 'PATCH', $path, $query, $body, $headers);
    }

    protected function delete(Application $application, string $path, array $query = [], array $headers = []): TestResponse
    {
        return $this->call($application, 'DELETE', $path, $query, '', $headers);
    }

    protected function head(Application $application, string $path, array $query = [], array $headers = []): TestResponse
    {
        return $this->call($application, 'HEAD', $path, $query, '', $headers);
    }

    protected function options(Application $application, string $path, array $query = [], array $headers = []): TestResponse
    {
        return $this->call($application, 'OPTIONS', $path, $query, '', $headers);
    }

    /** @param array<string, string> $query */
    protected function query(Application $application, string $path, array $query = [], string $body = '', array $headers = []): TestResponse
    {
        return $this->call($application, 'QUERY', $path, $query, $body, $headers);
    }

    /** @param class-string $expected */
    protected function raises(callable $callback, string $expected = RuntimeException::class): void
    {
        Assert::assertThrows($callback, $expected);
    }

    protected function assertTrue(bool $condition, string $message = 'Failed asserting that the condition is true.'): void
    {
        Assert::assertTrue($condition, $message);
    }

    protected function assertFalse(bool $condition, string $message = 'Failed asserting that the condition is false.'): void
    {
        Assert::assertFalse($condition, $message);
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        Assert::assertSame($expected, $actual, $message);
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        Assert::assertEquals($expected, $actual, $message);
    }

    protected function assertNull(mixed $actual, string $message = 'Failed asserting that the value is null.'): void
    {
        Assert::assertNull($actual, $message);
    }

    protected function assertNotNull(mixed $actual, string $message = 'Failed asserting that the value is not null.'): void
    {
        Assert::assertNotNull($actual, $message);
    }

    /** @param array<mixed>|Countable $actual */
    protected function assertCount(int $expected, array|Countable $actual, string $message = ''): void
    {
        Assert::assertCount($expected, $actual, $message);
    }

    protected function assertArrayHasKey(string|int $key, array $actual, string $message = ''): void
    {
        Assert::assertArrayHasKey($key, $actual, $message);
    }

    protected function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        Assert::assertStringContainsString($needle, $haystack, $message);
    }

    protected function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        Assert::assertStringNotContainsString($needle, $haystack, $message);
    }

    protected function assertInstanceOf(string $expected, object $actual, string $message = ''): void
    {
        Assert::assertInstanceOf($expected, $actual, $message);
    }

    protected function assertMatchesRegularExpression(string $pattern, string $subject, string $message = ''): void
    {
        Assert::assertMatchesRegularExpression($pattern, $subject, $message);
    }

    protected function fail(string $message = 'Test failed.'): never
    {
        Assert::fail($message);
    }

    /** @param array<string, string> $query */
    private function uri(string $path, array $query): string
    {
        return $query === []
            ? $path
            : "{$path}?" . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function removeTemporary(): void
    {
        if ($this->temporary === '') {
            return;
        }

        $base = realpath(sys_get_temp_dir()) ?: rtrim(sys_get_temp_dir(), '/');
        $base = rtrim($base, '/');
        $real = realpath($this->temporary);
        $temporary = $this->temporary;
        $this->temporary = '';

        if ($real === false || !str_starts_with($real, "{$base}/arix-test-")) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($iterator as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($real);
        unset($temporary);
    }
}
