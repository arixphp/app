<?php

declare(strict_types=1);

use Arix\Application;
use Arix\Cache\FileCache;
use Arix\Config\Repository;
use Arix\Constants\HttpMethod;
use Arix\Container\Container;
use Arix\Container\ContainerContext;
use Arix\Http\HttpException;
use Arix\Http\Request;
use Arix\Localization\Translator;
use Arix\Routing\RequestContext;
use Arix\Routing\UrlGenerator;
use Arix\Support\Environment;
use Arix\Views\TemplateCompiler;
use Arix\Views\TemplateEngine;
use Arix\Views\ViewFactory;

require dirname(__DIR__) . '/app/bootstrap.php';
ob_start();

$passed = 0;
$failed = 0;
$temporary = rtrim(sys_get_temp_dir(), '/') . '/arix-tests-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700, true);

function check(bool $condition, string $message = 'Assertion failed'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function raises(callable $callback, string $class = RuntimeException::class): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        check($exception instanceof $class, 'Unexpected exception: ' . $exception::class);
        return;
    }
    throw new RuntimeException('Expected exception was not thrown.');
}

function test(string $name, callable $callback): void
{
    global $passed, $failed;
    try {
        $callback();
        $passed++;
        echo "PASS $name\n";
    } catch (Throwable $exception) {
        $failed++;
        echo "FAIL $name: {$exception->getMessage()}\n";
    }
}

function fixture(string $name, string $content): string
{
    global $temporary;
    $path = $temporary . '/' . $name;
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }
    file_put_contents($path, $content);
    return $path;
}

/** Register a fixture controller without introducing discovery or autowiring. */
function controllerRoutes(string $site, string $class, string $path = '/', int $ttl = 0, array $query = [], ?string $factory = null): void
{
    $id = 'App\\Controllers\\' . $class;
    $factory ??= 'new \\' . $id . '()';
    fixture($site . '/app/services.php', '<?php return static function (\\Arix\\Container\\Container $container): void { $container->bind(' . var_export($id, true) . ', static fn (\\Arix\\Container\\Container $c) => ' . $factory . '); };');
    fixture($site . '/app/routes.php', '<?php \\Arix\\Facades\\Route::get(' . var_export($path, true) . ', [' . var_export($id, true) . ', "index"])' . ($ttl > 0 ? '->cache(' . $ttl . ', ' . var_export($query, true) . ')' : '') . ';');
}

$root = dirname(__DIR__);
$app = new Application($root, [], ['path' => $temporary]);

/** Run the real development router against an isolated document root. */
function serveFixture(string $directory, callable $callback): void
{
    global $root, $temporary;
    $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
    check(is_resource($socket), $message);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $process = proc_open([PHP_BINARY, '-S', $address, '-t', $directory, $root . '/core/Development/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/server.log', 'a'], 2 => ['file', $temporary . '/server.log', 'a']], $pipes, $root);
    check(is_resource($process));
    fclose($pipes[0]);
    try {
        $ready = false;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @stream_socket_client('tcp://' . $address, $code, $message, 0.05);
            if (is_resource($connection)) {
                fclose($connection);
                $ready = true;
                break;
            }
            usleep(20000);
        }
        check($ready, 'Development server did not start.');
        $callback('http://' . $address);
    } finally {
        proc_terminate($process);
        proc_close($process);
    }
}

try {
    test('Core PSR-4 autoload resolves nested identifiers and delegates misses safely', function () use ($root, $temporary): void {
        fixture('autoload-core/autoload.php', (string) file_get_contents($root . '/core/autoload.php'));
        fixture('autoload-core/functions.php', '<?php $GLOBALS["helpers_loaded"] = true;');
        fixture('autoload-core/AutoloadFixture/Nested/Example.php', '<?php namespace Arix\\AutoloadFixture\\Nested; class Example {} $GLOBALS["loaded"] = ($GLOBALS["loaded"] ?? 0) + 1;');
        fixture('autoload-core/AutoloadFixture/Éxample.php', '<?php namespace Arix\\AutoloadFixture; class Éxample {}');
        $outside = fixture('autoload-outside/Escape.php', '<?php $GLOBALS["escaped"] = true;');
        symlink($outside, $temporary . '/autoload-core/AutoloadFixture/Escape.php');
        fixture('autoload-core/fallback.php', '<?php namespace Arix\\AutoloadFixture; class Fallback {}');
        $script = fixture('autoload-core/check.php', <<<'PHP'
<?php
set_error_handler(static function (int $level, string $message): bool {
    if (error_reporting() & $level) {
        throw new RuntimeException($message);
    }
    return false;
});
$prefix = 'caller-prefix';
$baseDirectory = 'caller-directory';
require __DIR__ . '/autoload.php';
if ($prefix !== 'caller-prefix' || $baseDirectory !== 'caller-directory') {
    throw new RuntimeException('Autoload modified the caller scope.');
}
$loader = spl_autoload_functions()[0];
if (!($GLOBALS['helpers_loaded'] ?? false)) {
    throw new RuntimeException('Helpers were not loaded.');
}
if (!class_exists('Arix\AutoloadFixture\Nested\Example') || !class_exists('Arix\AutoloadFixture\Éxample')) {
    throw new RuntimeException('Valid PSR-4 class was not loaded.');
}
class_exists('Arix\AutoloadFixture\Nested\Example');
if (($GLOBALS['loaded'] ?? 0) !== 1) {
    throw new RuntimeException('Class file was loaded twice.');
}
$delegated = [];
spl_autoload_register(static function (string $class) use (&$delegated): void {
    $delegated[] = $class;
    if ($class === 'Arix\AutoloadFixture\Fallback') {
        require __DIR__ . '/fallback.php';
    }
});
foreach (['Other\Example', 'ArixOther\Example', 'Arix\Missing', 'Arix\AutoloadFixture\Escape'] as $class) {
    if (class_exists($class) || !in_array($class, $delegated, true)) {
        throw new RuntimeException('Invalid class was loaded or not delegated: ' . $class);
    }
}
// PHP can reject invalid names before invoking SPL, so test the callback directly.
foreach (['Arix\..\Escape', 'Arix\php://filter', 'Arix\Bad/Name', "Arix\0Bad"] as $class) {
    $loader($class);
}
if (isset($GLOBALS['escaped']) || !class_exists('Arix\AutoloadFixture\Fallback')) {
    throw new RuntimeException('Symlink containment or SPL fallback failed.');
}
echo 'autoload-ok';
PHP);
        $process = proc_open([PHP_BINARY, $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        check(is_resource($process));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        check(proc_close($process) === 0 && $output === 'autoload-ok', $output . $errors);
    });

    test('APP_DEBUG enables only explicit true/1 and defaults to false', function () use ($root): void {
        $previous = getenv('APP_DEBUG');
        try {
            foreach ([null, '', 'false', 'FALSE', '0', 'invalid', 'yes', 'on', 'true', 'TRUE', ' true ', '1'] as $value) {
                $value === null ? putenv('APP_DEBUG') : putenv('APP_DEBUG=' . $value);
                $expected = in_array($value, ['true', 'TRUE', ' true ', '1'], true);
                check(Environment::boolean('APP_DEBUG') === $expected, 'Value: ' . var_export($value, true));
                check((require $root . '/app/config/app.php')['debug'] === $expected);
            }
        } finally {
            $previous === false ? putenv('APP_DEBUG') : putenv('APP_DEBUG=' . $previous);
        }
    });

    test('APP_DEBUG controls escaped exception detail and explicit overrides win', function () use ($root, $temporary): void {
        fixture('debug-site/app/config/app.php', (string) file_get_contents($root . '/app/config/app.php'));
        fixture('debug-site/app/config/cache.php', '<?php return ["version" => "debug-test"];');
        fixture('debug-site/app/controllers/DebugTestController.php', '<?php namespace App\\Controllers; final class DebugTestController { public function index(): \\Arix\\Views\\Contracts\\View { throw new \\RuntimeException("debug-marker <script>alert(1)</script>"); } }');
        controllerRoutes('debug-site', 'DebugTestController');
        $previous = getenv('APP_DEBUG');
        $previousLog = ini_get('error_log');
        ini_set('error_log', $temporary . '/debug-errors.log');
        try {
            foreach ([null, 'false', '0', 'invalid', 'true', '1'] as $value) {
                $value === null ? putenv('APP_DEBUG') : putenv('APP_DEBUG=' . $value);
                $site = new Application($temporary . '/debug-site', [], ['enabled' => false]);
                $response = $site->handle(new Request('GET', '/', ['APP_DEBUG' => 'true'], ['APP_DEBUG' => 'true']));
                $expected = in_array($value, ['true', '1'], true);
                check($response->status === 500 && $response->headers['Cache-Control'] === 'no-store');
                check(str_contains($response->body, '<meta name="viewport" content="width=device-width, initial-scale=1">'), 'Error pages adapt to mobile screens.');
                check(str_contains($response->body, '<main>') && str_contains($response->body, '<h1>500</h1>'), 'Error pages expose a main landmark and status heading.');
                check(str_contains($response->body, '<title>500 — ArixPHP</title>'), 'Error pages have an identifiable document title.');
                check(str_contains($response->body, 'debug-marker') === $expected);
                check(!str_contains($response->body, '<script>'));
                if ($expected) {
                    check(str_contains($response->body, '&lt;script&gt;') && str_contains($response->body, 'Stack trace:'));
                } else {
                    check(str_contains($response->body, 'Internal server error.'));
                    check(!str_contains($response->body, 'DebugTestController.php'));
                }
            }
            putenv('APP_DEBUG=true');
            $site = new Application($temporary . '/debug-site', ['debug' => false], ['enabled' => false]);
            check(!str_contains($site->handle(new Request('GET', '/'))->body, 'debug-marker'));
            putenv('APP_DEBUG=false');
            $site = new Application($temporary . '/debug-site', ['debug' => true], ['enabled' => false]);
            check(str_contains($site->handle(new Request('GET', '/'))->body, 'debug-marker'));
            check(str_contains((string) file_get_contents($temporary . '/debug-errors.log'), 'debug-marker'));
        } finally {
            $previous === false ? putenv('APP_DEBUG') : putenv('APP_DEBUG=' . $previous);
            ini_set('error_log', (string) $previousLog);
        }
    });

    test('Container resolves factories, shared services, instances and explicit replacements', function (): void {
        $container = new Container();
        $calls = 0;
        $container->bind('transient', static function () use (&$calls): object {
            $calls++;
            return new stdClass();
        });
        check($container->get('transient') !== $container->get('transient'));
        check($calls === 2);
        $container->singleton('shared', static fn (Container $container): object => (object) ['dependency' => $container->get('transient')]);
        check($container->get('shared') === $container->get('shared'));
        check($calls === 3);
        $instance = new stdClass();
        $container->instance('shared', $instance);
        check($container->get('shared') === $instance);
        $container->singleton('shared', static fn (): object => new stdClass());
        check($container->get('shared') !== $instance);
        check($container->get('shared') === $container->get('shared'));
        raises(fn () => $container->get('unregistered'));
        raises(fn () => $container->instance('', $instance), InvalidArgumentException::class);
        $container->bind('invalid', static fn (): string => 'not an object');
        raises(fn () => $container->get('invalid'));
    });

    test('Container detects cycles and recovers after failed resolution', function (): void {
        $container = new Container();
        $container->singleton('a', static fn (Container $container): object => $container->get('b'));
        $container->bind('b', static fn (Container $container): object => $container->get('a'));
        raises(fn () => $container->get('a'));
        $container->instance('b', new stdClass());
        check($container->get('a') === $container->get('b'));
        $attempts = 0;
        $container->singleton('retry', static function () use (&$attempts): object {
            if (++$attempts === 1) {
                throw new RuntimeException('First attempt fails');
            }
            return new stdClass();
        });
        raises(fn () => $container->get('retry'));
        check($container->get('retry') === $container->get('retry'));
        check($attempts === 2);
        $container->bind('replace-self', static function (Container $container): object {
            $container->instance('replace-self', new stdClass());
            return new stdClass();
        });
        raises(fn () => $container->get('replace-self'));
    });

    test('Container scopes restore nested contexts and reject helpers outside a scope', function (): void {
        raises(fn () => ContainerContext::current(), LogicException::class);
        raises(fn () => view('index'), LogicException::class);
        raises(fn () => trans('home.title'), LogicException::class);
        $outer = new Container();
        $inner = new Container();
        check(ContainerContext::run($outer, function () use ($outer, $inner): string {
            check(ContainerContext::current() === $outer);
            raises(fn () => ContainerContext::run($inner, function () use ($inner): void {
                check(ContainerContext::current() === $inner);
                throw new RuntimeException('Scope failure');
            }));
            check(ContainerContext::current() === $outer);
            return 'restored';
        }) === 'restored');
        raises(fn () => ContainerContext::current(), LogicException::class);
    });

    test('Request exposes repeatable raw bytes and configurable body limits', function () use ($root): void {
        check(method_exists(Request::class, 'body'), 'Request::body is missing');
        check((new Request('GET', '/'))->body() === '');
        foreach (['{"hello":"world"}', "raw\0bytes", ''] as $body) {
            $request = new Request('QUERY', '/', [], ['body' => 'query'], $body);
            check($request->body() === $body && $request->body() === $body);
            check($request->query === ['body' => 'query']);
        }
        $app = new Application($root, ['max_body_bytes' => 4], ['enabled' => false]);
        check($app->maxBodyBytes() === 4);
        foreach ([0, -1, '4', PHP_INT_MAX] as $limit) {
            raises(fn () => new Application($root, ['max_body_bytes' => $limit], ['enabled' => false]), InvalidArgumentException::class);
        }
    });

    test('Global requests preserve bodies and enforce byte limits through the development router', function () use ($root, $temporary): void {
        fixture('body-server/index.php', '<?php require_once ' . var_export($root . '/core/autoload.php', true) . '; try { $r = \\Arix\\Http\\Request::fromGlobals(4); echo json_encode([$r->method, base64_encode($r->body()), $r->header("Content-Type"), $r->header("Content-Length")]); } catch (\\Arix\\Http\\HttpException $e) { http_response_code($e->status); }');
        serveFixture($temporary . '/body-server', function (string $url): void {
            foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $context = stream_context_create(['http' => ['method' => $method, 'header' => 'Content-Type: application/octet-stream', 'content' => "a\0bc", 'ignore_errors' => true, 'timeout' => 5]]);
                $result = file_get_contents($url . '/echo', false, $context);
                check(json_decode($result, true) === [$method, 'YQBiYw==', 'application/octet-stream', '4'], (string) $result);
            }
            $context = stream_context_create(['http' => ['method' => 'POST', 'content' => '12345', 'header' => 'Content-Type: application/octet-stream', 'ignore_errors' => true, 'timeout' => 5]]);
            file_get_contents($url . '/echo', false, $context);
            check(str_contains($http_response_header[0], '413'));
        });
    });

    test('Request and Response use HttpMethod without rejecting unknown method names', function (): void {
        foreach (HttpMethod::cases() as $method) {
            $request = new Request($method->value, '/');
            check($request->method === $method->value && $request->httpMethod() === $method);
        }
        check((new Request('QUERY', '/'))->httpMethod() === HttpMethod::QUERY);
        check((new Request('TRACE', '/'))->httpMethod() === null);
        $response = new Arix\Http\Response('body');
        ob_start();
        $response->send(HttpMethod::HEAD);
        check(ob_get_clean() === '');
        ob_start();
        $response->send(HttpMethod::GET);
        check(ob_get_clean() === 'body');
    });

    test('Front controller enforces configured body limits without trusting Content-Length', function () use ($root, $temporary): void {
        fixture('front-body/index.php', (string) file_get_contents($root . '/index.php'));
        fixture('front-body/app/bootstrap.php', '<?php require_once ' . var_export($root . '/core/autoload.php', true) . '; return new \\Arix\\Application(' . var_export($root, true) . ', ["max_body_bytes" => 4], ["enabled" => false]);');
        serveFixture($temporary . '/front-body', function (string $url): void {
            foreach (['1234' => 405, '12345' => 413] as $body => $status) {
                $body = (string) $body;
                $context = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/octet-stream', 'content' => $body, 'ignore_errors' => true, 'timeout' => 5]]);
                $result = file_get_contents($url . '/', false, $context);
                check(str_contains($http_response_header[0], (string) $status), (string) $result);
                check(in_array('Cache-Control: no-store', $http_response_header, true));
            }
            $address = parse_url($url, PHP_URL_HOST) . ':' . parse_url($url, PHP_URL_PORT);
            $socket = stream_socket_client('tcp://' . $address, $code, $message, 5);
            check(is_resource($socket), $message);
            stream_set_timeout($socket, 5);
            fwrite($socket, "POST / HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/octet-stream\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n5\r\n12345\r\n0\r\n\r\n");
            $response = stream_get_contents($socket);
            fclose($socket);
            check(str_contains($response, '413'), $response);
        });
    });

    test('Route facade uses scoped collectors without loading controllers', function (): void {
        check(class_exists(Arix\Routing\RouteCollector::class), 'RouteCollector is missing');
        raises(fn () => Arix\Facades\Route::get('/', ['App\\Controllers\\NotLoaded', 'index']), LogicException::class);
        $outer = new Container();
        $collector = new Arix\Routing\RouteCollector();
        $outer->instance(Arix\Routing\RouteCollector::class, $collector);
        ContainerContext::run($outer, function () use ($collector): void {
            $route = Arix\Facades\Route::get('/', ['App\\Controllers\\NotLoaded', 'index'])->cache(30, ['q']);
            check($route->path === '/' && $route->controller === 'App\\Controllers\\NotLoaded');
            check($route->cachePolicy()->ttl === 30);
            check(!class_exists('App\\Controllers\\NotLoaded', false));
            $inner = new Container();
            $innerCollector = new Arix\Routing\RouteCollector();
            $inner->instance(Arix\Routing\RouteCollector::class, $innerCollector);
            raises(fn () => ContainerContext::run($inner, function (): void {
                Arix\Facades\Route::post('/inner', ['App\\Controllers\\NotLoaded', 'store']);
                throw new RuntimeException('Expected');
            }));
            check(count($collector->routes()) === 1 && count($innerCollector->routes()) === 1);
            raises(fn () => Arix\Facades\Route::missing(), BadMethodCallException::class);
        });
    });

    test('Collector validates static routes methods handlers and cache policies', function (): void {
        check(class_exists(Arix\Routing\RouteCollector::class), 'RouteCollector is missing');
        $collector = new Arix\Routing\RouteCollector();
        $handler = ['App\\Controllers\\Example', 'index'];
        foreach (['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'query'] as $method) {
            $route = $collector->$method('/same', $handler);
            check($route->methods === [strtoupper($method)]);
        }
        check($collector->match(['CUSTOM', HttpMethod::GET], '/extension', $handler)->methods === ['CUSTOM', 'GET']);
        raises(fn () => $collector->get('/same', $handler), InvalidArgumentException::class);
        foreach (['index', '/../secret', '/hello?x=1', '/hello#fragment', '//host', '/hello/', '/hello.php', '/{id}'] as $path) {
            raises(fn () => $collector->get($path, $handler), InvalidArgumentException::class);
        }
        foreach ([[], ['bad method'], [''], [123], ['GET', 'GET']] as $methods) {
            raises(fn () => $collector->match($methods, '/bad', $handler), InvalidArgumentException::class);
        }
        foreach ([[], ['Bad'], ['App\\Controllers\\Example', 'index', 'extra'], ['../bad', 'index'], ['App\\Controllers\\Example', '__call']] as $invalid) {
            raises(fn () => $collector->get('/bad', $invalid), InvalidArgumentException::class);
        }
        raises(fn () => $collector->post('/write', $handler)->cache(30), InvalidArgumentException::class);
        raises(fn () => $collector->get('/negative', $handler)->cache(-1), InvalidArgumentException::class);
        raises(fn () => $collector->get('/key', $handler)->cache(30, ['bad key']), InvalidArgumentException::class);
        check(HttpMethod::tryFrom('QUERY') === HttpMethod::QUERY);
    });

    test('HTTP router selects method destinations and supplies per-path Allow', function (): void {
        check(class_exists(Arix\Routing\Router::class), 'HTTP Router is missing');
        $collector = new Arix\Routing\RouteCollector();
        foreach (['get', 'post', 'put', 'patch', 'delete', 'query'] as $method) {
            $collector->$method('/resource', ['App\\Controllers\\Example', $method]);
        }
        $collector->get('/read', ['App\\Controllers\\Example', 'index']);
        $collector->head('/resource', ['App\\Controllers\\Example', 'head']);
        $collector->options('/resource', ['App\\Controllers\\Example', 'options']);
        $collector->match(['Custom'], '/custom', ['App\\Controllers\\Example', 'custom']);
        $router = new Arix\Routing\Router($collector);
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'QUERY', 'HEAD', 'OPTIONS'] as $method) {
            check($router->resolve($method, '/resource')->action === strtolower($method));
        }
        check($router->resolve('HEAD', '/read')->action === 'index');
        check($router->resolve('Custom', '/custom')->action === 'custom');
        check($router->allowedMethods('/read') === ['GET', 'HEAD', 'OPTIONS']);
        check($router->allowedMethods('/missing') === []);
        foreach ([['POST', '/read', 405], ['GET', '/missing', 404], ['custom', '/custom', 405]] as [$method, $path, $status]) {
            try {
                $router->resolve($method, $path);
                check(false, 'Route should not resolve.');
            } catch (HttpException $exception) {
                check($exception->status === $status);
                if ($path === '/read') {
                    check($exception->headers['Allow'] === 'GET, HEAD, OPTIONS');
                }
            }
        }
    });

    test('Dispatcher uses explicit bindings and validates action contracts before construction', function () use ($temporary): void {
        check(class_exists(Arix\Routing\ControllerDispatcher::class), 'ControllerDispatcher is missing');
        $file = fixture('dispatch/DispatchFixture.php', <<<'PHP'
<?php
namespace App\Controllers;
final class DispatchFixture
{
    public function index(): \Arix\Views\Contracts\View { return view('index'); }
    public function echoBody(\Arix\Http\Request $request): \Arix\Http\Response { return new \Arix\Http\Response($request->body(), 201); }
    private function hidden(): \Arix\Http\Response { return new \Arix\Http\Response(); }
    public static function staticAction(): \Arix\Http\Response { return new \Arix\Http\Response(); }
    public function badArgument(string $input): \Arix\Http\Response { return new \Arix\Http\Response(); }
    public function optional(\Arix\Http\Request $request = new \Arix\Http\Request('GET', '/')): \Arix\Http\Response { return new \Arix\Http\Response(); }
    public function badReturn(): string { return 'bad'; }
    public function __call(string $name, array $args): \Arix\Http\Response { throw new \RuntimeException('Magic must not run'); }
}
PHP);
        require $file;
        $container = new Container();
        $container->instance(ViewFactory::class, new ViewFactory());
        $calls = 0;
        $container->bind(App\Controllers\DispatchFixture::class, static function () use (&$calls): object {
            $calls++;
            return new App\Controllers\DispatchFixture();
        });
        $collector = new Arix\Routing\RouteCollector();
        $dispatcher = new Arix\Routing\ControllerDispatcher($container, $temporary . '/dispatch');
        ContainerContext::run($container, function () use ($collector, $dispatcher, &$calls): void {
            $route = $collector->post('/echo', [App\Controllers\DispatchFixture::class, 'echoBody']);
            check($calls === 0);
            $response = $dispatcher->dispatch($route, new Request('POST', '/', [], [], "raw\0data"));
            check($response->status === 201 && $response->body === "raw\0data" && $calls === 1);
            $view = $dispatcher->dispatch($collector->get('/', [App\Controllers\DispatchFixture::class, 'index']), new Request('GET', '/'));
            check($view->name() === 'index' && $calls === 2);
            foreach (['hidden', 'staticAction', 'badArgument', 'optional', 'badReturn', 'missing'] as $action) {
                raises(fn () => $dispatcher->dispatch($collector->get('/' . $action, [App\Controllers\DispatchFixture::class, $action]), new Request('GET', '/')));
            }
            check($calls === 2, 'Invalid contracts must not construct controllers.');
        });
        $container->instance(App\Controllers\DispatchFixture::class, new stdClass());
        raises(fn () => $dispatcher->dispatch($collector->get('/wrong', [App\Controllers\DispatchFixture::class, 'index']), new Request('GET', '/')));
        $unbound = new Arix\Routing\ControllerDispatcher(new Container(), $temporary . '/dispatch');
        raises(fn () => $unbound->dispatch($collector->get('/unbound', [App\Controllers\DispatchFixture::class, 'index']), new Request('GET', '/')));
    });

    test('Controller autoload rejects symlinks and matches class filenames', function () use ($temporary): void {
        check(method_exists(Arix\Autoloader::class, 'registerControllers'), 'Controller autoload is missing');
        fixture('controller-load/Nested/Loaded.php', '<?php namespace App\\Controllers\\Nested; final class Loaded {}');
        fixture('outside-controller.php', '<?php $GLOBALS["escaped_controller"] = true;');
        symlink($temporary . '/outside-controller.php', $temporary . '/controller-load/Escape.php');
        Arix\Autoloader::registerControllers($temporary . '/controller-load');
        check(class_exists('App\\Controllers\\Nested\\Loaded'));
        check(!class_exists('App\\Controllers\\Escape') && !isset($GLOBALS['escaped_controller']));
        fixture('controller-load/wrongcasecontroller.php', '<?php namespace App\\Controllers; final class WrongCaseController {}');
        check(!class_exists('App\\Controllers\\WrongCaseController'), 'Filename case must match the class even on macOS.');
        fixture('linked-root/LinkedRootController.php', '<?php namespace App\\Controllers; final class LinkedRootController {}');
        symlink($temporary . '/linked-root', $temporary . '/linked-controller-directory');
        Arix\Autoloader::registerControllers($temporary . '/linked-controller-directory');
        check(!class_exists('App\\Controllers\\LinkedRootController'), 'Linked controller roots must not be loaded.');
    });

    test('Application dispatches controller verbs preserves Responses and isolates repeated registration', function () use ($root, $temporary): void {
        fixture('verbs/app/config/app.php', (string) file_get_contents($root . '/app/config/app.php'));
        fixture('verbs/app/config/cache.php', '<?php return ["version" => "verbs"];');
        fixture('verbs/app/controllers/VerbsController.php', <<<'PHP'
<?php
namespace App\Controllers;
final class VerbsController
{
    public function index(): \Arix\Views\Contracts\View { $GLOBALS['verb_reads']++; return view('home'); }
    public function echoBody(\Arix\Http\Request $request): \Arix\Http\Response { return new \Arix\Http\Response($request->method . ':' . $request->body(), 201, ['Content-Type' => 'application/octet-stream', 'X-Action' => 'echo']); }
    public function head(): \Arix\Http\Response { return new \Arix\Http\Response('explicit-head', 202, ['X-Action' => 'head']); }
    public function options(): \Arix\Http\Response { return new \Arix\Http\Response('explicit-options', 200, ['X-Action' => 'options']); }
    public function response(): \Arix\Http\Response { $GLOBALS['verb_responses']++; return new \Arix\Http\Response((string) $GLOBALS['verb_responses'], 202); }
}
PHP);
        controllerRoutes('verbs', 'VerbsController');
        fixture('verbs/app/routes.php', <<<'PHP'
<?php
use Arix\Facades\Route;
use App\Controllers\VerbsController;
Route::get('/', [VerbsController::class, 'index'])->cache(300);
Route::head('/', [VerbsController::class, 'head']);
Route::match(['POST', 'PUT', 'PATCH', 'DELETE', 'QUERY', 'Custom'], '/', [VerbsController::class, 'echoBody']);
Route::get('/response', [VerbsController::class, 'response'])->cache(300);
Route::options('/explicit', [VerbsController::class, 'options']);
PHP);
        fixture('verbs/app/views/home.php', 'read');
        $GLOBALS['verb_reads'] = $GLOBALS['verb_responses'] = 0;
        $site = new Application($temporary . '/verbs', [], ['path' => $temporary]);
        $second = new Application($temporary . '/verbs', ['base_path' => '/second'], ['path' => $temporary]);
        check($site->handle(new Request('GET', '/'))->body === 'read');
        check($site->handle(new Request('GET', '/'))->body === 'read' && $GLOBALS['verb_reads'] === 1);
        check($site->handle(new Request('HEAD', '/'))->headers['X-Action'] === 'head');
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'QUERY', 'Custom'] as $method) {
            $response = $site->handle(new Request($method, '/', [], [], "a\0b"));
            check($response->status === 201 && $response->body === $method . ":a\0b", $response->body);
            check($response->headers === ['Content-Type' => 'application/octet-stream', 'X-Action' => 'echo']);
        }
        check($second->handle(new Request('POST', '/second/', [], [], 'second'))->body === 'POST:second');
        $options = $site->handle(new Request('OPTIONS', '/'));
        check($options->status === 204 && $options->body === '' && $options->headers['Allow'] === 'GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS, QUERY, Custom');
        check($site->handle(new Request('OPTIONS', '/explicit'))->body === 'explicit-options');
        check($site->handle(new Request('OPTIONS', '/missing'))->status === 404);
        check($site->handle(new Request('POST', '/', [], [], 'root'))->body === 'POST:root');
        check($site->handle(new Request('QUERY', '/', [], [], 'root'))->body === 'QUERY:root');
        check($site->handle(new Request('POST', '/response/?x=1', [], ['x' => '1']))->headers['Location'] === '/response?x=1');
        check($site->handle(new Request('GET', '/', [], [], 'body'))->body === 'read' && $GLOBALS['verb_reads'] === 2);
        check($site->handle(new Request('GET', '/response'))->body === '1');
        check($site->handle(new Request('GET', '/response'))->body === '2');
        foreach ([204, 304] as $status) {
            ob_start();
            (new Arix\Http\Response('must-not-send', $status))->send();
            check(ob_get_clean() === '');
        }
        fixture('verbs/app/routes.php', '<?php throw new \\RuntimeException("Registration failure");');
        check($site->handle(new Request('GET', '/'))->status === 500);
        raises(fn () => ContainerContext::current(), LogicException::class);
    });

    test('Routes use exact declared paths without language negotiation or prefixes', function () use ($app): void {
        $response = $app->handle(new Request('GET', '/', ['Accept-Language' => 'en']));
        check($response->status === 200, 'Root must render directly, not redirect.');
        check(!isset($response->headers['Location']) && !isset($response->headers['Vary']));
        check($response->headers['Content-Language'] === 'es');
        check($app->handle(new Request('GET', '/about'))->status === 200);
        check($app->handle(new Request('GET', '/es'))->status === 404);
        check($app->handle(new Request('GET', '/en/about'))->status === 404);
    });

    test('Configured language renders translated layouts partials and components', function () use ($app, $root, $temporary): void {
        $spanish = $app->handle(new Request('GET', '/', ['Accept-Language' => 'en']));
        $englishApp = new Application($root, ['default_locale' => 'en'], ['path' => $temporary]);
        $english = $englishApp->handle(new Request('GET', '/'));
        check($spanish->status === 200 && str_contains($spanish->body, 'Sitios simples'));
        check($english->status === 200 && str_contains($english->body, 'Simple sites'));
        check(str_contains($spanish->body, 'Rutas explícitas y controladores'));
        check(str_contains($english->body, 'Explicit routes and controllers'));
        check(!str_contains($spanish->body, 'Enrutamiento por archivos'));
        check(!str_contains($english->body, 'File-based routing'));
        check(str_contains($spanish->body, '<article class="card">'));
        check(!str_contains($spanish->body, '@extends'));
        check($spanish->headers['Content-Language'] === 'es');
        check($app->handle(new Request('GET', '/about'))->status === 200);
    });

    test('Demo navigation identifies the current page without implicit language URLs', function () use ($app): void {
        foreach (['/' => '/', '/about' => '/about'] as $path => $current) {
            $response = $app->handle(new Request('GET', $path));
            check($response->status === 200);
            check(preg_match('~<a\b[^>]*href="' . preg_quote($current, '~') . '"[^>]*aria-current="page"~', $response->body) === 1);
            check(substr_count($response->body, 'aria-current="page"') === 1);
            check(!str_contains($response->body, 'hreflang='));
        }
    });

    test('Demo layouts provide a translated keyboard shortcut to main content', function () use ($app, $root, $temporary): void {
        $english = new Application($root, ['default_locale' => 'en'], ['path' => $temporary]);
        foreach ([[$app, '/', 'Saltar al contenido'], [$english, '/about', 'Skip to content']] as [$site, $path, $label]) {
            $body = $site->handle(new Request('GET', $path))->body;
            check(str_contains($body, 'href="#main-content">' . $label . '</a>'));
            check(str_contains($body, '<main id="main-content" tabindex="-1">'));
            check(strpos($body, 'href="#main-content"') < strpos($body, '<header>'));
        }
    });

    test('Unsupported methods return 405 with Allow', function () use ($app): void {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'QUERY', 'TRACE'] as $method) {
            $response = $app->handle(new Request($method, '/'));
            check($response->status === 405 && $response->headers['Allow'] === 'GET, HEAD, OPTIONS');
        }
    });

    test('HEAD has GET headers and sends no body', function () use ($app): void {
        $get = $app->handle(new Request('GET', '/'));
        $head = $app->handle(new Request('HEAD', '/'));
        check($head->headers === $get->headers && $head->body === $get->body);
        ob_start();
        $head->send('HEAD');
        check(ob_get_clean() === '');
    });

    test('Canonical redirects preserve queries', function () use ($app): void {
        $response = $app->handle(new Request('GET', '/about/?q=hello', [], ['q' => 'hello']));
        check($response->status === 308 && $response->headers['Location'] === '/about?q=hello');
    });

    test('Subdirectory installation generates correct page and asset URLs', function () use ($root, $temporary): void {
        $app = new Application($root, ['base_path' => '/site'], ['path' => $temporary]);
        check($app->handle(new Request('GET', '/site/'))->status === 200);
        $response = $app->handle(new Request('GET', '/site/about'));
        check($response->status === 200 && str_contains($response->body, '/site/styles/app.css'));
        check($app->handle(new Request('GET', '/en'))->status === 404);
    });

    test('Unknown routes including former locale prefixes return 404', function () use ($app): void {
        foreach (['/missing', '/fr', '/es', '/en/about', '/about.php', '/app/config/app.php'] as $uri) {
            check($app->handle(new Request('GET', $uri))->status === 404, $uri);
        }
    });

    test('Unsafe and ambiguous paths are rejected', function () use ($app): void {
        foreach (['/es/../about', '/es/%2e%2e/about', '/es/%00', '/es/%2Fabout', '/es/%5cabout', '/es//about', '//evil.test', '/es/%zz', '/es/\\about', '/es/.hidden'] as $uri) {
            check($app->handle(new Request('GET', $uri))->status === 400, $uri);
        }
    });

    test('Explicit routes map URLs independently of controller filenames', function (): void {
        $collector = new Arix\Routing\RouteCollector();
        $collector->get('/journal', ['App\\Controllers\\RouteBlog', 'index']);
        $router = new Arix\Routing\Router($collector);
        check($router->resolve('GET', '/journal')->controller === 'App\\Controllers\\RouteBlog');
        raises(fn () => $router->resolve('GET', '/RouteBlog'), HttpException::class);
        $collector->get('/added', ['App\\Controllers\\RouteAdded', 'index']);
        check($router->resolve('GET', '/added')->controller === 'App\\Controllers\\RouteAdded');
        raises(fn () => $collector->get('/journal', ['App\\Controllers\\RouteDuplicate', 'index']), InvalidArgumentException::class);
    });

    test('Route declarations reject traversal fragments relative paths and queries', function (): void {
        $collector = new Arix\Routing\RouteCollector();
        foreach (['index', '/../secret', '/hello?x=1', '/hello#fragment', '//host', '/hello/', '/hello.php'] as $path) {
            raises(fn () => $collector->get($path, ['App\\Controllers\\Example', 'index']), InvalidArgumentException::class);
        }
        check($collector->get('/', ['App\\Controllers\\Example', 'index'])->path === '/');
    });

    test('Controller symlinks cannot escape the application', function () use ($temporary): void {
        fixture('outside.php', '<?php throw new RuntimeException("Must not execute");');
        mkdir($temporary . '/symlink-controllers');
        symlink($temporary . '/outside.php', $temporary . '/symlink-controllers/Outside.php');
        $dispatcher = new Arix\Routing\ControllerDispatcher(new Container(), $temporary . '/symlink-controllers');
        $collector = new Arix\Routing\RouteCollector();
        raises(fn () => $dispatcher->dispatch($collector->get('/outside', ['App\\Controllers\\Outside', 'index']), new Request('GET', '/')));
    });

    test('Global view helper returns a deferred immutable definition', function (): void {
        $container = new Container();
        $factoryCalls = 0;
        $container->singleton(ViewFactory::class, static function () use (&$factoryCalls): ViewFactory {
            $factoryCalls++;
            return new ViewFactory();
        });
        ContainerContext::run($container, function () use (&$factoryCalls): void {
            $view = view('index', ['title' => 'Example']);
            $other = view('about', ['title' => 'Other']);
            check($view instanceof Arix\Views\Contracts\View);
            check($view->name() === 'index' && $view->data() === ['title' => 'Example']);
            check($view !== $other && $other->data() === ['title' => 'Other']);
            check($factoryCalls === 1);
            raises(fn () => view('../secrets'), InvalidArgumentException::class);
        });
        check(class_exists(App\Controllers\WelcomeController::class));
    });

    test('Public assets allow media and fonts but reject executable and ambiguous paths', function () use ($root): void {
        foreach (['styles/app.css', 'scripts/app.js', 'assets/images/logo.png', 'assets/video/demo.mp4', 'assets/fonts/site.woff2', 'assets/fonts/site.ttf'] as $path) {
            check(Arix\Http\PublicAssets::allows($path), $path);
        }
        foreach (['assets/evil.php', 'assets/evil.php.png', 'assets/.hidden.png', 'assets/../app/config/app.php', 'assets//logo.png', 'assets/logo.svg', 'assets/fonts/logo.png', 'assets/Fonts/logo.png', 'scripts/style.css', 'styles/script.js'] as $path) {
            check(!Arix\Http\PublicAssets::allows($path), $path);
        }
        $translator = new Translator($root . '/app/lang', ['es', 'en'], 'es', 'es');
        $context = new RequestContext(new Request('GET', '/site/es'), $translator, '/', '/site');
        check($context->asset('assets/fonts/site.woff2') === '/site/assets/fonts/site.woff2');
        raises(fn () => $context->asset('assets/evil.php'), InvalidArgumentException::class);
    });

    test('Translation replacement and fallback', function () use ($temporary): void {
        fixture('lang/es.php', '<?php return ["hello" => "Hola :name", "fallback" => "Base"];');
        fixture('lang/en.php', '<?php return [];');
        $translator = new Translator($temporary . '/lang', ['es', 'en'], 'es', 'en');
        check($translator->get('hello', ['name' => 'Arix']) === 'Hola Arix');
        check($translator->get('fallback') === 'Base');
        check($translator->get('missing') === 'missing');
        check($translator->negotiate('en;q=0, es;q=1') === 'es');
        raises(fn () => new Translator($temporary, ['../secret'], '../secret', '../secret'), InvalidArgumentException::class);
        $container = new Container();
        $container->instance(Translator::class, $translator);
        ContainerContext::run($container, function (): void {
            check(trans('hello', ['name' => 'Arix']) === 'Hola Arix');
            check(trans('fallback') === 'Base');
            check(trans('missing') === 'missing');
        });
    });

    test('Requests isolate translation and restore outer scope after hits, redirects and errors', function () use ($temporary): void {
        fixture('scoped-site/app/config/app.php', '<?php return ["debug" => false, "base_path" => "", "locales" => ["es", "en"], "default_locale" => "es"];');
        fixture('scoped-site/app/config/cache.php', '<?php return ["version" => "scope-test"];');
        fixture('scoped-site/app/lang/es.php', '<?php return ["hello" => "Hola :name", "markup" => "<b>Español</b>"];');
        fixture('scoped-site/app/lang/en.php', '<?php return ["hello" => "Hello :name", "markup" => "<b>English</b>"];');
        fixture('scoped-site/app/controllers/ScopedTestController.php', '<?php namespace App\\Controllers; final class ScopedTestController { public function index(): \\Arix\\Views\\Contracts\\View { $container = \\Arix\\Container\\ContainerContext::current(); $context = $container->get(\\Arix\\Routing\\RequestContext::class); $request = $container->get(\\Arix\\Http\\Request::class); if ($request !== $context->request) { throw new \\RuntimeException("Wrong request"); } if (isset($request->query["error"])) { throw new \\RuntimeException("Expected scope test failure"); } return view("index", ["title" => trans("hello", ["name" => "Arix"])]); } }');
        controllerRoutes('scoped-site', 'ScopedTestController', '/', 300);
        fixture('scoped-site/app/views/index.arix.php', '{{ $title }}|{{ trans("markup") }}|{{ config("app.locale") }}:{{ url()->current() }}');
        $site = new Application($temporary . '/scoped-site', [], ['path' => $temporary]);
        $outer = new Container();
        $outer->instance(Translator::class, new Translator($temporary . '/scoped-site/app/lang', ['es', 'en'], 'es', 'es'));
        $outer->instance(Repository::class, new Repository(['app' => ['locale' => 'outer']]));
        $outer->instance(UrlGenerator::class, new UrlGenerator('/outer'));
        $english = new Application($temporary . '/scoped-site', ['default_locale' => 'en'], ['path' => $temporary]);
        ContainerContext::run($outer, function () use ($site, $english, $outer): void {
            foreach ([[$english, 'en'], [$site, 'es'], [$english, 'en']] as [$application, $locale]) {
                $response = $application->handle(new Request('GET', '/'));
                check($response->status === 200);
                check(str_contains($response->body, $locale === 'en' ? 'Hello Arix' : 'Hola Arix'));
                check(str_contains($response->body, '&lt;b&gt;'));
                check(str_ends_with($response->body, "$locale:/"));
                check(ContainerContext::current() === $outer && trans('hello', ['name' => 'outer']) === 'Hola outer');
                check(config('app.locale') === 'outer' && url()->current() === '/outer/');
            }
            foreach ([new Request('GET', '/about/'), new Request('POST', '/'), new Request('GET', '/missing'), new Request('GET', '/', [], ['error' => '1'])] as $request) {
                $response = $site->handle($request);
                check(in_array($response->status, [308, 405, 404, 500], true));
                check(ContainerContext::current() === $outer);
                check(config('app.locale') === 'outer' && url()->current() === '/outer/');
            }
        });
        raises(fn () => ContainerContext::current(), LogicException::class);
    });

    $cache = new FileCache($temporary, ['path' => $temporary]);
    $engine = new TemplateEngine($temporary . '/views', $cache);

    test('Request services remain coherent across languages applications and HTML cache hits', function () use ($temporary): void {
        fixture('helper-site/app/config/app.php', '<?php return ["debug" => false, "base_path" => "", "locales" => ["es", "en"], "default_locale" => "es", "name" => "Original"];');
        fixture('helper-site/app/config/cache.php', '<?php return ["version" => "helper-test"];');
        fixture('helper-site/app/lang/es.php', '<?php return ["hello" => "Hola"];');
        fixture('helper-site/app/lang/en.php', '<?php return ["hello" => "Hello"];');
        fixture('helper-site/app/controllers/ScopedHelpersController.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Controllers;
final class ScopedHelpersController
{
    public function __construct(\Arix\Routing\RequestContext $context)
    {
        if (config('app.locale') !== $context->translator->locale || url() !== $context->urls()) {
            throw new \RuntimeException('Incoherent request services.');
        }
    }

    public function index(): \Arix\Views\Contracts\View
    {
        return view('index', ['view' => 'grid']);
    }
}
PHP);
        controllerRoutes('helper-site', 'ScopedHelpersController', '/about', 300, [], 'new \\App\\Controllers\\ScopedHelpersController($c->get(\\Arix\\Routing\\RequestContext::class))');
        fixture('helper-site/app/views/index.arix.php', '@extends("layout")@section("body")@include("partial")|@component("component"){{ $view }}@endcomponent@endsection');
        fixture('helper-site/app/views/layout.arix.php', '{{ config("app.name") }}|{{ config("app.locale") }}|{{ config("app.default_locale") }}|{{ url()->current_path() }}|{{ url()->current() }}|{{ asset("styles/app.css") }}|@yield("body")');
        fixture('helper-site/app/views/partial.arix.php', '{{ trans("hello") }}:{{ implode(",", config("app.locales")) }}');
        fixture('helper-site/app/views/component.arix.php', '{{ url(url()->current_path()) }}:{!! $slot !!}');
        $first = new Application($temporary . '/helper-site', ['name' => 'First', 'base_path' => '/first'], ['path' => $temporary, 'version' => 'first']);
        $second = new Application($temporary . '/helper-site', ['name' => 'Second', 'base_path' => '/second', 'default_locale' => 'en'], ['path' => $temporary, 'version' => 'second']);
        $outer = new Container();
        $outer->instance(Repository::class, new Repository(['app' => ['locale' => 'outer']]));
        $outer->instance(UrlGenerator::class, new UrlGenerator('/outer'));
        ContainerContext::run($outer, function () use ($first, $second): void {
            for ($iteration = 0; $iteration < 3; $iteration++) {
                foreach ([[$first, 'First', '/first', 'es'], [$second, 'Second', '/second', 'en']] as [$site, $name, $base, $default]) {
                    $expected = "$name|$default|$default|/about|$base/about|$base/styles/app.css|" . ($default === 'es' ? 'Hola' : 'Hello') . ":es,en|$base/about:grid";
                    for ($hit = 0; $hit < 2; $hit++) {
                        $response = $site->handle(new Request('GET', "$base/about"));
                        check($response->status === 200 && $response->body === $expected, $response->body);
                        check(config('app.locale') === 'outer' && url()->current() === '/outer/');
                    }
                }
            }
        });
        raises(fn () => url(), LogicException::class);
    });

    test('Configuration repository preserves null and supports dotted keys and defaults', function (): void {
        $values = ['app' => ['locale' => 'es', 'locales' => ['es', 'en'], 'nullable' => null, 'debug' => false]];
        $repository = new Repository($values);
        check($repository->get() === $values);
        check($repository->get('app.locale') === 'es');
        check($repository->get('app.locales') === ['es', 'en']);
        check($repository->get('app.nullable', 'fallback') === null);
        check($repository->get('app.debug', true) === false);
        check($repository->get('app.missing', 'fallback') === 'fallback');
        check($repository->get('app.locale.nested', 'fallback') === 'fallback');
    });

    test('URL generator preserves declared internal paths and delegates RequestContext consistently', function () use ($root): void {
        $translator = new Translator($root . '/app/lang', ['es', 'en'], 'es', 'en');
        $context = new RequestContext(new Request('GET', '/site/about?x=1'), $translator, '/about', '/site');
        $urls = $context->urls();
        check($urls->to('/') === '/site/');
        check($urls->to('about') === '/site/about');
        check($urls->to('/about/') === '/site/about');
        check($urls->current_path() === '/about');
        check($urls->current() === '/site/about');
        check($urls->to($urls->current_path()) === '/site/about');
        check($context->url('/about') === $urls->to('/about'));
        check($context->asset('styles/app.css') === $urls->asset('styles/app.css'));
        check((new UrlGenerator(''))->current() === '/');
        $encoded = new UrlGenerator('', '/café/100%');
        check($encoded->current_path() === '/caf%C3%A9/100%25');
        check($encoded->current() === '/caf%C3%A9/100%25');
        check($encoded->to($encoded->current_path()) === $encoded->current());
        foreach (['https://example.com', '//example.com', '../secret', '/a/../b', '/%2e%2e/b', '/a//b', '/a\\b', '/%2fb', '/%5cb', '/bad%', '/x?y=1', '/x#y', '/%3f', '/%00', '/.hidden'] as $path) {
            raises(fn () => $urls->to($path), InvalidArgumentException::class);
        }
        foreach (['app/config/app.php', 'styles/file.php', 'assets/logo.svg', '../styles/app.css'] as $path) {
            raises(fn () => $urls->asset($path), InvalidArgumentException::class);
        }
    });

    test('URL config and asset helpers use explicit nested container scopes', function () use ($engine, $temporary): void {
        foreach ([fn () => url(), fn () => config(), fn () => asset('styles/app.css')] as $helper) {
            raises($helper, LogicException::class);
        }
        $outer = new Container();
        $outerUrls = new UrlGenerator('/site', '/about');
        $outer->instance(UrlGenerator::class, $outerUrls);
        $outer->instance(Repository::class, new Repository(['app' => ['locale' => 'es', 'locales' => ['es', 'en']]]));
        $inner = new Container();
        $inner->instance(UrlGenerator::class, new UrlGenerator(''));
        $inner->instance(Repository::class, new Repository(['app' => ['locale' => 'en']]));
        fixture('views/helpers.arix.php', '{{ config("app.locale") }}|{{ url()->current_path() }}|{{ url()->current() }}|{{ url("/") }}|{{ asset("styles/app.css") }}');
        fixture('views/helpers-native.php', '<?php declare(strict_types=1); echo $this->escape(config("app.locale")) . "|" . url()->current();');
        ContainerContext::run($outer, function () use ($outerUrls, $outer, $inner, $engine, $temporary): void {
            check(url() === $outerUrls);
            check(config('app.locales') === ['es', 'en']);
            check($engine->render('helpers') === 'es|/about|/site/about|/site/|/site/styles/app.css');
            check($engine->render('helpers-native') === 'es|/site/about');
            $eval = new TemplateEngine($temporary . '/views', new FileCache($temporary, ['enabled' => false]), allowEval: true);
            check($eval->render('helpers') === 'es|/about|/site/about|/site/|/site/styles/app.css');
            raises(fn () => ContainerContext::run($inner, function (): void {
                check(config('app.locale') === 'en' && url()->current() === '/');
                throw new RuntimeException('Expected nested scope failure.');
            }));
            check(ContainerContext::current() === $outer);
            check(config('app.locale') === 'es' && url() === $outerUrls);
        });
        $level = ob_get_level();
        raises(fn () => $engine->render('helpers'), LogicException::class);
        check(ob_get_level() === $level);
        raises(fn () => config(), LogicException::class);
    });

    test('Template data no longer collides with execution variables in compiled native and eval views', function () use ($engine, $temporary): void {
        $data = ['view' => '<grid>', 'data' => 'data', 'path' => 'path', 'fallback' => 'fallback', 'allowEval' => 'allowEval'];
        fixture('views/free-variables.arix.php', '{{ $view }}|{{ $data }}|{{ $path }}|{{ $fallback }}|{{ $allowEval }}|@json($view)');
        $expected = '&lt;grid&gt;|data|path|fallback|allowEval|"\\u003Cgrid\\u003E"';
        check($engine->render('free-variables', $data) === $expected);
        check($engine->render('free-variables', $data) === $expected);
        fixture('views/free-native.php', '<?php declare(strict_types=1); echo $this->escape($view) . "|" . $data . "|" . $path . "|" . $fallback . "|" . $allowEval;');
        check($engine->render('free-native', $data) === '&lt;grid&gt;|data|path|fallback|allowEval');
        $eval = new TemplateEngine($temporary . '/views', new FileCache($temporary, ['enabled' => false]), allowEval: true);
        check($eval->render('free-variables', $data) === $expected);
        check($eval->render('free-native', $data) === '&lt;grid&gt;|data|path|fallback|allowEval');
        fixture('views/loop-variable.arix.php', '@foreach($items as $view){{ $view }}@endforeach|@if($view === "<two>")@json($view)@endif|<?php $view = "<native>"; ?>{{ $view }}');
        $loopExpected = '&lt;one&gt;&lt;two&gt;|"\\u003Ctwo\\u003E"|&lt;native&gt;';
        check($engine->render('loop-variable', ['items' => ['<one>', '<two>']]) === $loopExpected);
        check($eval->render('loop-variable', ['items' => ['<one>', '<two>']]) === $loopExpected);
    });

    test('Free variable names propagate through layouts partials props and slots', function () use ($engine): void {
        fixture('views/free-layout.arix.php', '{{ $view }}|@yield("body")');
        fixture('views/free-partial.arix.php', '{{ $view }}:{{ $path }}');
        fixture('views/free-component.arix.php', '{{ $view }}:{!! $path !!}:{!! $slot !!}');
        fixture('views/free-tree.arix.php', '@extends("free-layout", ["view" => "layout"])@section("body")@include("free-partial", ["view" => "partial"])|@component("free-component", ["view" => "prop", "path" => "overridden"])@slot("path")<b>slot</b>@endslot default@endcomponent@endsection');
        check($engine->render('free-tree', ['view' => 'page', 'path' => 'data']) === 'layout|partial:data|prop:<b>slot</b>: default');
        fixture('views/native-layout.php', '<?php declare(strict_types=1); echo $this->escape($view); echo $this->yieldSection("body");');
        fixture('views/native-child.php', '<?php declare(strict_types=1); $this->extend("native-layout"); $this->startSection("body"); echo $this->escape($path); $this->endSection();');
        check($engine->render('native-child', ['view' => '<layout>', 'path' => '<body>']) === '&lt;layout&gt;&lt;body&gt;');
    });

    test('Template data validation rejects reserved and invalid keys without leaking state', function () use ($engine): void {
        fixture('views/valid-data.arix.php', '{{ $view }}');
        foreach (['__arix', '__arixContext', '__arixPath', 'this', 'GLOBALS', 'bad-key', 'with space', '1value', '', 0] as $key) {
            $level = ob_get_level();
            raises(fn () => $engine->render('valid-data', [$key => 'value']), InvalidArgumentException::class);
            check(ob_get_level() === $level);
        }
        fixture('views/reserved-partial.arix.php', '@include("valid-data", ["__arixPath" => "bad"])');
        fixture('views/reserved-component.arix.php', '@component("valid-data", ["__arixContext" => "bad"])text@endcomponent');
        fixture('views/reserved-slot.arix.php', '@component("valid-data")@slot("__arixData")bad@endslot@endcomponent');
        fixture('views/reserved-layout.arix.php', '@extends("valid-data", ["__arixAllowEval" => true])');
        foreach (['reserved-partial', 'reserved-component', 'reserved-slot', 'reserved-layout'] as $name) {
            $level = ob_get_level();
            raises(fn () => $engine->render($name), InvalidArgumentException::class);
            check(ob_get_level() === $level);
        }
        check($engine->render('valid-data', ['view' => 'restored']) === 'restored');
    });

    test('Balanced expressions, escaping, raw output and native PHP', function () use ($engine): void {
        fixture('views/expression.arix.php', '{{ strtoupper(trim($value)) }}|{{ "}}" }}|{!! $html !!}|@if(strlen(trim($value)) > 0)yes@endif|<?php echo "@if(foo)"; ?>');
        $html = $engine->render('expression', ['value' => ' <b> ', 'html' => '<em>safe</em>']);
        check(str_contains($html, '&lt;B&gt;|}}|<em>safe</em>|yes|@if(foo)'));
    });

    test('JSON helper prevents script-closing injection', function () use ($engine): void {
        fixture('views/json.arix.php', '@json($value)');
        $output = $engine->render('json', ['value' => '</script><script>alert(1)</script>']);
        check(!str_contains($output, '<') && str_contains($output, '\\u003C'));
    });

    test('Template delimiters inside interpolated strings are not expression endings', function () use ($engine): void {
        fixture('views/interpolation.arix.php', '{{ "Hi $name}}" }}|@if(str_contains("Hi $name)", ")"))yes@endif');
        check($engine->render('interpolation', ['name' => 'Arix']) === 'Hi Arix}}|yes');
    });

    test('Nested layouts and named component slots', function () use ($engine): void {
        fixture('views/base.arix.php', '<main>@yield("body")</main>');
        fixture('views/middle.arix.php', '@extends("base")@section("body")<div>@yield("inner")</div>@endsection');
        fixture('views/component.arix.php', '<aside>{{ $label }}:{!! $heading !!}:{!! $slot !!}</aside>');
        fixture('views/nested.arix.php', '@extends("middle")@section("inner")@component("component", ["label" => "<x>"])@slot("heading")<b>Title</b>@endslot Text@endcomponent@endsection');
        $output = $engine->render('nested');
        check($output === '<main><div><aside>&lt;x&gt;:<b>Title</b>: Text</aside></div></main>', $output);
    });

    test('Compiled cache invalidates even with same timestamp and length', function () use ($engine): void {
        $path = fixture('views/change.arix.php', 'one');
        $timestamp = filemtime($path);
        check($engine->render('change') === 'one');
        file_put_contents($path, 'two');
        touch($path, $timestamp);
        check($engine->render('change') === 'two');
    });

    test('Circular templates and malformed captures restore buffers and state', function () use ($engine): void {
        fixture('views/cycle.arix.php', '@include("cycle")');
        fixture('views/broken.arix.php', '@section("body")unclosed');
        fixture('views/throw.arix.php', '<?php ob_start(); throw new RuntimeException("Expected"); ?>');
        $level = ob_get_level();
        foreach (['cycle', 'broken', 'throw'] as $view) {
            raises(fn () => $engine->render($view));
            check(ob_get_level() === $level);
        }
        check($engine->render('change') === 'two');
        raises(fn () => $engine->render('../outside'));
    });

    test('Compiler rejects malformed expressions and preserves comments', function (): void {
        $compiler = new TemplateCompiler();
        raises(fn () => $compiler->compile('@if(foo('));
        raises(fn () => $compiler->compile('{{ }}'));
        check($compiler->compile('{{-- hidden --}}@@include') === '@include');
        check($compiler->compile('<?php /* @if(x) */ echo "{{ x }}"; ?>') === '<?php /* @if(x) */ echo "{{ x }}"; ?>');
    });

    test('Rendering works without persistent cache, including strict native PHP', function () use ($temporary, $root): void {
        $engine = new TemplateEngine($temporary . '/views', new FileCache($temporary, ['enabled' => false]), allowEval: true);
        fixture('views/native.php', '<?php declare(strict_types=1); echo $this->escape($value);');
        check($engine->render('native', ['value' => '<x>']) === '&lt;x&gt;');
        check($engine->render('nested') === '<main><div><aside>&lt;x&gt;:<b>Title</b>: Text</aside></div></main>');
        $app = new Application($root, [], ['enabled' => false, 'allow_eval' => true]);
        check($app->handle(new Request('GET', '/'))->status === 200);
    });

    test('Eval fallback is disabled by default and native PHP needs no cache', function () use ($temporary): void {
        $engine = new TemplateEngine($temporary . '/views', new FileCache($temporary, ['enabled' => false]));
        raises(fn () => $engine->render('nested'));
        check($engine->render('native', ['value' => '<x>']) === '&lt;x&gt;');
    });

    test('Native templates preserve their original directory with cache enabled', function () use ($engine, $temporary): void {
        $file = fixture('views/directory.php', '<?php declare(strict_types=1); echo __DIR__;');
        check($engine->render('directory') === dirname(realpath($file)));
        $file = fixture('views/source-file.php', '<?php declare(strict_types=1); echo __FILE__;');
        check($engine->render('source-file') === realpath($file));
    });

    test('Fingerprints include explicit files even with unchanged timestamps and lengths', function (): void {
        $file = fixture('fingerprint/routes.php', 'one');
        $timestamp = filemtime($file);
        $before = FileCache::fingerprint([$file]);
        file_put_contents($file, 'two');
        touch($file, $timestamp);
        check(FileCache::fingerprint([$file]) !== $before);
    });

    test('File cache is atomic, installation-specific and clears only its own files', function () use ($cache, $temporary): void {
        $path = $cache->write('test', 'complete');
        check($path !== null && $cache->read('test') === 'complete');
        $other = new FileCache($temporary . '/other-site', ['path' => $temporary]);
        check($other->read('test') === null);
        $other->write('test', 'other');
        $cache->clear();
        check($cache->read('test') === null && $other->read('test') === 'other');
    });

    test('HTML cache skips controller construction and action on hits and isolates queries', function () use ($temporary): void {
        foreach (['app/config', 'app/controllers', 'app/views', 'app/lang'] as $directory) {
            if (!is_dir($temporary . '/site/' . $directory)) {
                mkdir($temporary . '/site/' . $directory, 0700, true);
            }
        }
        fixture('site/app/config/app.php', '<?php return ["debug" => false, "base_path" => "", "locales" => ["en"], "default_locale" => "en"];');
        fixture('site/app/config/cache.php', '<?php return ["version" => "test"];');
        fixture('site/app/controllers/CacheTestController.php', '<?php namespace App\\Controllers; final class CacheTestController { public function __construct(private readonly \\Arix\\Routing\\RequestContext $context) { $GLOBALS["constructs"]++; } public function index(): \\Arix\\Views\\Contracts\\View { $GLOBALS["loads"]++; return view("home", ["value" => $this->context->request->query["q"] ?? "default"]); } }');
        controllerRoutes('site', 'CacheTestController', '/', 300, ['q'], 'new \\App\\Controllers\\CacheTestController($c->get(\\Arix\\Routing\\RequestContext::class))');
        $view = fixture('site/app/views/home.arix.php', '{{ $value }}');
        $site = new Application($temporary . '/site', [], ['path' => $temporary]);
        $GLOBALS['loads'] = 0;
        $GLOBALS['constructs'] = 0;
        check($site->handle(new Request('GET', '/'))->body === 'default');
        check($site->handle(new Request('GET', '/'))->body === 'default');
        check($GLOBALS['loads'] === 1);
        check($GLOBALS['constructs'] === 1);
        check($site->handle(new Request('GET', '/?q=one', [], ['q' => 'one']))->body === 'one');
        check($GLOBALS['loads'] === 2);
        $site->handle(new Request('GET', '/?unknown=x', [], ['unknown' => 'x']));
        $site->handle(new Request('GET', '/', ['Cookie' => 'visitor=1']));
        $site->handle(new Request('GET', '/', ['Authorization' => 'Bearer test']));
        check($GLOBALS['loads'] === 5);
        file_put_contents($view, 'updated:{{ $value }}');
        check($site->handle(new Request('GET', '/'))->body === 'updated:default');
        check($GLOBALS['loads'] === 6);
        $site->clearCache();
        $site->handle(new Request('GET', '/'));
        check($GLOBALS['loads'] === 7);
        // Routes, bindings and controller source all participate in HTML invalidation.
        foreach (['routes.php', 'services.php', 'controllers/CacheTestController.php'] as $relative) {
            $file = $temporary . '/site/app/' . $relative;
            file_put_contents($file, file_get_contents($file) . ' /*one*/');
            $site->handle(new Request('GET', '/'));
            $before = $GLOBALS['loads'];
            $timestamp = filemtime($file);
            file_put_contents($file, str_replace('/*one*/', '/*two*/', (string) file_get_contents($file)));
            touch($file, $timestamp);
            $site->handle(new Request('GET', '/'));
            check($GLOBALS['loads'] === $before + 1, 'Missing invalidation: ' . $relative);
        }
        $before = $GLOBALS['loads'];
        $site->handle(new Request('HEAD', '/'));
        check($GLOBALS['loads'] === $before, 'Implicit HEAD must use the GET HTML entry.');
        $site->handle(new Request('GET', '/', [], [], 'body'));
        check($GLOBALS['loads'] === $before + 1, 'Body-dependent GET must bypass HTML cache.');
    });

    test('HTML cache isolates selected controller and action when routes depend on configuration', function () use ($root, $temporary): void {
        fixture('handler-cache/app/config/app.php', (string) file_get_contents($root . '/app/config/app.php'));
        fixture('handler-cache/app/config/cache.php', '<?php return ["version" => "handler-test"];');
        fixture('handler-cache/app/controllers/HandlerCacheController.php', '<?php namespace App\\Controllers; final class HandlerCacheController { public function first(): \\Arix\\Views\\Contracts\\View { $GLOBALS["handler_calls"]++; return view("home", ["value" => "first"]); } public function second(): \\Arix\\Views\\Contracts\\View { $GLOBALS["handler_calls"]++; return view("home", ["value" => "second"]); } }');
        fixture('handler-cache/app/controllers/OtherCacheController.php', '<?php namespace App\\Controllers; final class OtherCacheController { public function first(): \\Arix\\Views\\Contracts\\View { $GLOBALS["handler_calls"]++; return view("home", ["value" => "other"]); } }');
        fixture('handler-cache/app/services.php', '<?php return static function (\\Arix\\Container\\Container $c): void { $c->bind(\\App\\Controllers\\HandlerCacheController::class, static fn () => new \\App\\Controllers\\HandlerCacheController()); $c->bind(\\App\\Controllers\\OtherCacheController::class, static fn () => new \\App\\Controllers\\OtherCacheController()); };');
        fixture('handler-cache/app/routes.php', '<?php \\Arix\\Facades\\Route::get("/", [config("app.controller"), config("app.action")])->cache(300);');
        fixture('handler-cache/app/views/home.php', '<?php echo $this->escape($value);');
        $GLOBALS['handler_calls'] = 0;
        foreach ([['HandlerCacheController', 'first', 'first'], ['HandlerCacheController', 'second', 'second'], ['OtherCacheController', 'first', 'other']] as [$controller, $action, $expected]) {
            $site = new Application($temporary . '/handler-cache', ['controller' => 'App\\Controllers\\' . $controller, 'action' => $action], ['path' => $temporary]);
            check($site->handle(new Request('GET', '/'))->body === $expected, 'HTML cache mixed handlers.');
            check($site->handle(new Request('GET', '/'))->body === $expected);
        }
        check($GLOBALS['handler_calls'] === 3, 'Each handler should warm its own entry once.');
    });

    test('Cache gracefully handles an unwritable path', function () use ($temporary): void {
        $file = fixture('not-a-directory', 'x');
        $cache = new FileCache($temporary, ['path' => $file]);
        check($cache->write('x', 'y') === null && $cache->read('x') === null);
    });

    test('CLI generates pages without overwriting or executing project code', function () use ($temporary, $root): void {
        $directory = $temporary . '/cli-site';
        fixture('cli-site/index.php', '<?php throw new RuntimeException("Must not run");');
        fixture('cli-site/app/bootstrap.php', '<?php throw new RuntimeException("Must not run");');
        fixture('cli-site/core/autoload.php', '<?php throw new RuntimeException("Must not run");');
        mkdir($directory . '/app/pages');
        mkdir($directory . '/app/views');
        $run = static function (array $args) use ($directory, $root): array {
            $process = proc_open([PHP_BINARY, $root . '/cli/arix', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
            check(is_resource($process));
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [proc_close($process), $output];
        };
        check($run(['make:page', 'Example'])[0] === 0);
        $source = file_get_contents($directory . '/app/pages/Example.php');
        check(str_contains($source, "#[Get('/example')]"));
        check(is_file($directory . '/app/views/example.arix.php'));
        check($run(['make:page', 'Example'])[0] === 1);
        check(file_get_contents($directory . '/app/pages/Example.php') === $source);
        check($run(['make:page', '../Escape'])[0] === 1);
        check($run(['make:page', 'Match'])[0] === 1);
        check(!is_file($directory . '/app/pages/Match.php'));
        $before = FileCache::fingerprint([$directory]);
        check($run(['update'])[0] === 1);
        check(FileCache::fingerprint([$directory]) === $before);
        $previous = getenv('APP_DEBUG');
        try {
            putenv('APP_DEBUG=false');
            [$status, $output] = $run(['make:page', '../Escape']);
            check($status === 1 && !str_contains($output, 'InvalidArgumentException in '));
            putenv('APP_DEBUG=true');
            [$status, $output] = $run(['make:page', '../Escape']);
            check($status === 1 && str_contains($output, 'InvalidArgumentException in '));
            check(!str_contains($output, 'APP_DEBUG=') && !is_file($directory . '/Escape.php'));
        } finally {
            $previous === false ? putenv('APP_DEBUG') : putenv('APP_DEBUG=' . $previous);
        }
    });

} finally {
    // Only remove the unique fixture directory created by this test run.
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($temporary);
}

echo "\n$passed passed, $failed failed\n";
ob_end_flush();
exit($failed === 0 ? 0 : 1);
