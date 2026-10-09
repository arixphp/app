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
use Arix\Routing\PageRouter;
use Arix\Routing\PageContext;
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

$root = dirname(__DIR__);
$app = new Application($root, [], ['path' => $temporary]);

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
        fixture('debug-site/app/pages/DebugTestPage.php', '<?php namespace App\\Pages; #[\\Arix\\Pages\\Attributes\\Get("/")] final class DebugTestPage implements \\Arix\\Pages\\Contracts\\Page { public function render(): \\Arix\\Views\\Contracts\\View { throw new \\RuntimeException("debug-marker <script>alert(1)</script>"); } }');
        $previous = getenv('APP_DEBUG');
        $previousLog = ini_get('error_log');
        ini_set('error_log', $temporary . '/debug-errors.log');
        try {
            foreach ([null, 'false', '0', 'invalid', 'true', '1'] as $value) {
                $value === null ? putenv('APP_DEBUG') : putenv('APP_DEBUG=' . $value);
                $site = new Application($temporary . '/debug-site', [], ['enabled' => false]);
                $response = $site->handle(new Request('GET', '/es', ['APP_DEBUG' => 'true'], ['APP_DEBUG' => 'true']));
                $expected = in_array($value, ['true', '1'], true);
                check($response->status === 500 && $response->headers['Cache-Control'] === 'no-store');
                check(str_contains($response->body, 'debug-marker') === $expected);
                check(!str_contains($response->body, '<script>'));
                if ($expected) {
                    check(str_contains($response->body, '&lt;script&gt;') && str_contains($response->body, 'Stack trace:'));
                } else {
                    check(str_contains($response->body, 'Internal server error.'));
                    check(!str_contains($response->body, 'DebugTestPage.php'));
                }
            }
            putenv('APP_DEBUG=true');
            $site = new Application($temporary . '/debug-site', ['debug' => false], ['enabled' => false]);
            check(!str_contains($site->handle(new Request('GET', '/es'))->body, 'debug-marker'));
            putenv('APP_DEBUG=false');
            $site = new Application($temporary . '/debug-site', ['debug' => true], ['enabled' => false]);
            check(str_contains($site->handle(new Request('GET', '/es'))->body, 'debug-marker'));
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

    test('Request and Response use HttpMethod without rejecting unknown method names', function (): void {
        foreach (HttpMethod::cases() as $method) {
            $request = new Request($method->value, '/');
            check($request->method === $method->value && $request->httpMethod() === $method);
        }
        check((new Request('QUERY', '/'))->httpMethod() === null);
        check((new Request('TRACE', '/'))->httpMethod() === null);
        $response = new Arix\Http\Response('body');
        ob_start();
        $response->send(HttpMethod::HEAD);
        check(ob_get_clean() === '');
        ob_start();
        $response->send(HttpMethod::GET);
        check(ob_get_clean() === 'body');
    });

    test('Language negotiation redirects and varies only at root', function () use ($app): void {
        $response = $app->handle(new Request('GET', '/', ['Accept-Language' => 'es;q=0.3,en-US;q=0.9']));
        check($response->status === 302);
        check($response->headers['Location'] === '/en');
        check($response->headers['Vary'] === 'Accept-Language');
        check($app->handle(new Request('GET', '/', ['Accept-Language' => 'fr']))->headers['Location'] === '/es');
    });

    test('Explicit locales render translated layouts, partials and components', function () use ($app): void {
        $spanish = $app->handle(new Request('GET', '/es', ['Accept-Language' => 'en']));
        $english = $app->handle(new Request('GET', '/en'));
        check($spanish->status === 200 && str_contains($spanish->body, 'Sitios simples'));
        check($english->status === 200 && str_contains($english->body, 'Simple sites'));
        check(str_contains($spanish->body, '<article class="card">'));
        check(!str_contains($spanish->body, '@extends'));
        check($spanish->headers['Content-Language'] === 'es');
        check($app->handle(new Request('GET', '/en/about'))->status === 200);
    });

    test('Unsupported methods return 405 with Allow', function () use ($app): void {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'QUERY', 'OPTIONS', 'TRACE'] as $method) {
            $response = $app->handle(new Request($method, '/es'));
            check($response->status === 405 && $response->headers['Allow'] === 'GET, HEAD');
        }
    });

    test('HEAD has GET headers and sends no body', function () use ($app): void {
        $get = $app->handle(new Request('GET', '/en'));
        $head = $app->handle(new Request('HEAD', '/en'));
        check($head->headers === $get->headers && $head->body === $get->body);
        ob_start();
        $head->send('HEAD');
        check(ob_get_clean() === '');
    });

    test('Canonical redirects preserve queries', function () use ($app): void {
        $response = $app->handle(new Request('GET', '/es/about/?q=hello', [], ['q' => 'hello']));
        check($response->status === 308 && $response->headers['Location'] === '/es/about?q=hello');
    });

    test('Subdirectory installation generates correct page and asset URLs', function () use ($root, $temporary): void {
        $app = new Application($root, ['base_path' => '/site'], ['path' => $temporary]);
        check($app->handle(new Request('GET', '/site/'))->headers['Location'] === '/site/es');
        $response = $app->handle(new Request('GET', '/site/en/about'));
        check($response->status === 200 && str_contains($response->body, '/site/styles/app.css'));
        check($app->handle(new Request('GET', '/en'))->status === 404);
    });

    test('Unknown pages and locales return 404', function () use ($app): void {
        foreach (['/es/missing', '/fr', '/about', '/es/about.php', '/app/config/app.php'] as $uri) {
            check($app->handle(new Request('GET', $uri))->status === 404, $uri);
        }
    });

    test('Unsafe and ambiguous paths are rejected', function () use ($app): void {
        foreach (['/es/../about', '/es/%2e%2e/about', '/es/%00', '/es/%2Fabout', '/es/%5cabout', '/es//about', '//evil.test', '/es/%zz', '/es/\\about', '/es/.hidden'] as $uri) {
            check($app->handle(new Request('GET', $uri))->status === 400, $uri);
        }
    });

    test('Attribute router maps URLs independently of filenames and caches discoveries', function () use ($temporary): void {
        $file = fixture('routing/RouteBlog.php', '<?php namespace App\\Pages; #[\\Arix\\Pages\\Attributes\\Get("/journal")] final class RouteBlog implements \\Arix\\Pages\\Contracts\\Page { public function render(): \\Arix\\Views\\Contracts\\View { return view("index"); } }');
        fixture('routing/RouteHelper.php', '<?php namespace App\\Pages; final class RouteHelper {}');
        $cache = new FileCache($temporary . '/routing', ['path' => $temporary]);
        $router = new PageRouter($temporary . '/routing', $cache);
        check($router->resolve('/journal')['file'] === realpath($file));
        check($router->resolve('/journal')['class'] === 'App\\Pages\\RouteBlog');
        raises(fn () => $router->resolve('/RouteBlog'), HttpException::class);
        raises(fn () => $router->resolve('/RouteHelper'), HttpException::class);
        raises(fn () => $router->resolve('/missing'), HttpException::class);
        fixture('routing/RouteAdded.php', '<?php namespace App\\Pages; #[\\Arix\\Pages\\Attributes\\Get("/added")] final class RouteAdded implements \\Arix\\Pages\\Contracts\\Page { public function render(): \\Arix\\Views\\Contracts\\View { return view("index"); } }');
        check($router->resolve('/added')['class'] === 'App\\Pages\\RouteAdded');
        fixture('routing/RouteDuplicate.php', '<?php namespace App\\Pages; #[\\Arix\\Pages\\Attributes\\Get("/journal")] final class RouteDuplicate implements \\Arix\\Pages\\Contracts\\Page { public function render(): \\Arix\\Views\\Contracts\\View { return view("index"); } }');
        raises(fn () => $router->resolve('/journal'));
    });

    test('Route attributes reject traversal, fragments, relative paths and queries', function (): void {
        foreach (['index', '/../secret', '/hello?x=1', '/hello#fragment', '//host', '/hello/', '/hello.php'] as $path) {
            raises(fn () => new Arix\Pages\Attributes\Get($path), InvalidArgumentException::class);
        }
        check((new Arix\Pages\Attributes\Get('/'))->path === '/');
    });

    test('Page symlinks cannot escape the application', function () use ($temporary): void {
        fixture('outside.php', '<?php throw new RuntimeException("Must not execute");');
        mkdir($temporary . '/symlink-pages');
        symlink($temporary . '/outside.php', $temporary . '/symlink-pages/Outside.php');
        $router = new PageRouter($temporary . '/symlink-pages', new FileCache($temporary, ['path' => $temporary]));
        raises(fn () => $router->resolve('/outside'));
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
        check(class_exists(App\Pages\Index::class));
    });

    test('Public assets allow media and fonts but reject executable and ambiguous paths', function () use ($root): void {
        foreach (['styles/app.css', 'scripts/app.js', 'assets/images/logo.png', 'assets/video/demo.mp4', 'assets/fonts/site.woff2', 'assets/fonts/site.ttf'] as $path) {
            check(Arix\Http\PublicAssets::allows($path), $path);
        }
        foreach (['assets/evil.php', 'assets/evil.php.png', 'assets/.hidden.png', 'assets/../app/config/app.php', 'assets//logo.png', 'assets/logo.svg', 'assets/fonts/logo.png', 'assets/Fonts/logo.png', 'scripts/style.css', 'styles/script.js'] as $path) {
            check(!Arix\Http\PublicAssets::allows($path), $path);
        }
        $translator = new Translator($root . '/app/lang', ['es', 'en'], 'es', 'es');
        $context = new Arix\Routing\PageContext(new Request('GET', '/site/es'), $translator, '/', '/site');
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
        fixture('scoped-site/app/pages/ScopedTestPage.php', '<?php namespace App\\Pages; #[\\Arix\\Pages\\Attributes\\Get("/")] #[\\Arix\\Pages\\Attributes\\Cache(ttl: 300)] final class ScopedTestPage implements \\Arix\\Pages\\Contracts\\Page { public function render(): \\Arix\\Views\\Contracts\\View { $container = \\Arix\\Container\\ContainerContext::current(); $context = $container->get(\\Arix\\Routing\\PageContext::class); $request = $container->get(\\Arix\\Http\\Request::class); if ($request !== $context->request) { throw new \\RuntimeException("Wrong request"); } if (isset($request->query["error"])) { throw new \\RuntimeException("Expected scope test failure"); } return view("index", ["title" => trans("hello", ["name" => "Arix"])]); } }');
        fixture('scoped-site/app/views/index.arix.php', '{{ $title }}|{{ trans("markup") }}|{{ config("app.locale") }}:{{ url()->current() }}');
        $site = new Application($temporary . '/scoped-site', [], ['path' => $temporary]);
        $outer = new Container();
        $outer->instance(Translator::class, new Translator($temporary . '/scoped-site/app/lang', ['es', 'en'], 'es', 'es'));
        $outer->instance(Repository::class, new Repository(['app' => ['locale' => 'outer']]));
        $outer->instance(UrlGenerator::class, new UrlGenerator('/outer', 'es', ['es']));
        ContainerContext::run($outer, function () use ($site, $outer): void {
            foreach (['en', 'es', 'en'] as $locale) {
                $response = $site->handle(new Request('GET', '/' . $locale));
                check($response->status === 200);
                check(str_contains($response->body, $locale === 'en' ? 'Hello Arix' : 'Hola Arix'));
                check(str_contains($response->body, '&lt;b&gt;'));
                check(str_ends_with($response->body, "$locale:/$locale"));
                check(ContainerContext::current() === $outer && trans('hello', ['name' => 'outer']) === 'Hola outer');
                check(config('app.locale') === 'outer' && url()->current() === '/outer/es');
            }
            foreach ([new Request('GET', '/'), new Request('POST', '/en'), new Request('GET', '/missing'), new Request('GET', '/en', [], ['error' => '1'])] as $request) {
                $response = $site->handle($request);
                check(in_array($response->status, [302, 405, 404, 500], true));
                check(ContainerContext::current() === $outer);
                check(config('app.locale') === 'outer' && url()->current() === '/outer/es');
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
        fixture('helper-site/app/pages/ScopedHelpersPage.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Pages;
#[\Arix\Pages\Attributes\Get('/about')]
#[\Arix\Pages\Attributes\Cache(ttl: 300)]
final class ScopedHelpersPage implements \Arix\Pages\Contracts\Page
{
    public function __construct(\Arix\Routing\PageContext $context)
    {
        if (config('app.locale') !== $context->translator->locale || url() !== $context->urls()) {
            throw new \RuntimeException('Incoherent request services.');
        }
    }

    public function render(): \Arix\Views\Contracts\View
    {
        return view('index', ['view' => 'grid']);
    }
}
PHP);
        fixture('helper-site/app/views/index.arix.php', '@extends("layout")@section("body")@include("partial")|@component("component"){{ $view }}@endcomponent@endsection');
        fixture('helper-site/app/views/layout.arix.php', '{{ config("app.name") }}|{{ config("app.locale") }}|{{ config("app.default_locale") }}|{{ url()->current_path() }}|{{ url()->current() }}|{{ asset("styles/app.css") }}|@yield("body")');
        fixture('helper-site/app/views/partial.arix.php', '{{ trans("hello") }}:{{ implode(",", config("app.locales")) }}');
        fixture('helper-site/app/views/component.arix.php', '{{ url(url()->current_path(), "en") }}:{!! $slot !!}');
        $first = new Application($temporary . '/helper-site', ['name' => 'First', 'base_path' => '/first'], ['path' => $temporary, 'version' => 'first']);
        $second = new Application($temporary . '/helper-site', ['name' => 'Second', 'base_path' => '/second', 'default_locale' => 'en'], ['path' => $temporary, 'version' => 'second']);
        $outer = new Container();
        $outer->instance(Repository::class, new Repository(['app' => ['locale' => 'outer']]));
        $outer->instance(UrlGenerator::class, new UrlGenerator('/outer', 'es', ['es']));
        ContainerContext::run($outer, function () use ($first, $second): void {
            foreach (['es', 'en', 'es'] as $locale) {
                foreach ([[$first, 'First', '/first', 'es'], [$second, 'Second', '/second', 'en']] as [$site, $name, $base, $default]) {
                    $expected = "$name|$locale|$default|/about|$base/$locale/about|$base/styles/app.css|" . ($locale === 'es' ? 'Hola' : 'Hello') . ":es,en|$base/en/about:grid";
                    for ($hit = 0; $hit < 2; $hit++) {
                        $response = $site->handle(new Request('GET', "$base/$locale/about"));
                        check($response->status === 200 && $response->body === $expected, $response->body);
                        check(config('app.locale') === 'outer' && url()->current() === '/outer/es');
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

    test('URL generator localizes internal paths and delegates PageContext consistently', function () use ($root): void {
        $translator = new Translator($root . '/app/lang', ['es', 'en'], 'es', 'en');
        $context = new PageContext(new Request('GET', '/site/en/about?x=1'), $translator, '/about', '/site');
        $urls = $context->urls();
        check($urls->to('/') === '/site/en');
        check($urls->to('about') === '/site/en/about');
        check($urls->to('/about/', 'es') === '/site/es/about');
        check($urls->current_path() === '/about');
        check($urls->current() === '/site/en/about');
        check($urls->to($urls->current_path(), 'es') === '/site/es/about');
        check($context->url('/about', 'es') === $urls->to('/about', 'es'));
        check($context->asset('styles/app.css') === $urls->asset('styles/app.css'));
        check((new UrlGenerator('', 'es', ['es'], '/'))->current() === '/es');
        $encoded = new UrlGenerator('', 'es', ['es'], '/café/100%');
        check($encoded->current_path() === '/caf%C3%A9/100%25');
        check($encoded->current() === '/es/caf%C3%A9/100%25');
        check($encoded->to($encoded->current_path()) === $encoded->current());
        foreach (['https://example.com', '//example.com', '../secret', '/a/../b', '/%2e%2e/b', '/a//b', '/a\\b', '/%2fb', '/%5cb', '/bad%', '/x?y=1', '/x#y', '/%3f', '/%00', '/.hidden'] as $path) {
            raises(fn () => $urls->to($path), InvalidArgumentException::class);
        }
        raises(fn () => $urls->to('/', 'fr'), InvalidArgumentException::class);
        foreach (['app/config/app.php', 'styles/file.php', 'assets/logo.svg', '../styles/app.css'] as $path) {
            raises(fn () => $urls->asset($path), InvalidArgumentException::class);
        }
    });

    test('URL config and asset helpers use explicit nested container scopes', function () use ($engine, $temporary): void {
        foreach ([fn () => url(), fn () => config(), fn () => asset('styles/app.css')] as $helper) {
            raises($helper, LogicException::class);
        }
        $outer = new Container();
        $outerUrls = new UrlGenerator('/site', 'es', ['es', 'en'], '/about');
        $outer->instance(UrlGenerator::class, $outerUrls);
        $outer->instance(Repository::class, new Repository(['app' => ['locale' => 'es', 'locales' => ['es', 'en']]]));
        $inner = new Container();
        $inner->instance(UrlGenerator::class, new UrlGenerator('', 'en', ['es', 'en']));
        $inner->instance(Repository::class, new Repository(['app' => ['locale' => 'en']]));
        fixture('views/helpers.arix.php', '{{ config("app.locale") }}|{{ url()->current_path() }}|{{ url()->current() }}|{{ url("/", "en") }}|{{ asset("styles/app.css") }}');
        fixture('views/helpers-native.php', '<?php declare(strict_types=1); echo $this->escape(config("app.locale")) . "|" . url()->current();');
        ContainerContext::run($outer, function () use ($outerUrls, $outer, $inner, $engine, $temporary): void {
            check(url() === $outerUrls);
            check(config('app.locales') === ['es', 'en']);
            check($engine->render('helpers') === 'es|/about|/site/es/about|/site/en|/site/styles/app.css');
            check($engine->render('helpers-native') === 'es|/site/es/about');
            $eval = new TemplateEngine($temporary . '/views', new FileCache($temporary, ['enabled' => false]), allowEval: true);
            check($eval->render('helpers') === 'es|/about|/site/es/about|/site/en|/site/styles/app.css');
            raises(fn () => ContainerContext::run($inner, function (): void {
                check(config('app.locale') === 'en' && url()->current() === '/en');
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
        check($app->handle(new Request('GET', '/es'))->status === 200);
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

    test('File cache is atomic, installation-specific and clears only its own files', function () use ($cache, $temporary): void {
        $path = $cache->write('test', 'complete');
        check($path !== null && $cache->read('test') === 'complete');
        $other = new FileCache($temporary . '/other-site', ['path' => $temporary]);
        check($other->read('test') === null);
        $other->write('test', 'other');
        $cache->clear();
        check($cache->read('test') === null && $other->read('test') === 'other');
    });

    test('HTML cache skips page construction and render on hits and isolates queries', function () use ($temporary): void {
        foreach (['app/config', 'app/pages', 'app/views', 'app/lang'] as $directory) {
            if (!is_dir($temporary . '/site/' . $directory)) {
                mkdir($temporary . '/site/' . $directory, 0700, true);
            }
        }
        fixture('site/app/config/app.php', '<?php return ["debug" => false, "base_path" => "", "locales" => ["en"], "default_locale" => "en"];');
        fixture('site/app/config/cache.php', '<?php return ["version" => "test"];');
        fixture('site/app/pages/CacheTestPage.php', '<?php namespace App\\Pages; #[\\Arix\\Pages\\Attributes\\Get("/")] #[\\Arix\\Pages\\Attributes\\Cache(ttl: 300, query: ["q"])] final class CacheTestPage implements \\Arix\\Pages\\Contracts\\Page { public function __construct(private readonly \\Arix\\Routing\\PageContext $context) { $GLOBALS["constructs"]++; } public function render(): \\Arix\\Views\\Contracts\\View { $GLOBALS["loads"]++; return view("home", ["value" => $this->context->request->query["q"] ?? "default"]); } }');
        $view = fixture('site/app/views/home.arix.php', '{{ $value }}');
        $site = new Application($temporary . '/site', [], ['path' => $temporary]);
        $GLOBALS['loads'] = 0;
        $GLOBALS['constructs'] = 0;
        check($site->handle(new Request('GET', '/en'))->body === 'default');
        check($site->handle(new Request('GET', '/en'))->body === 'default');
        check($GLOBALS['loads'] === 1);
        check($GLOBALS['constructs'] === 1);
        check($site->handle(new Request('GET', '/en?q=one', [], ['q' => 'one']))->body === 'one');
        check($GLOBALS['loads'] === 2);
        $site->handle(new Request('GET', '/en?unknown=x', [], ['unknown' => 'x']));
        $site->handle(new Request('GET', '/en', ['Cookie' => 'visitor=1']));
        $site->handle(new Request('GET', '/en', ['Authorization' => 'Bearer test']));
        check($GLOBALS['loads'] === 5);
        file_put_contents($view, 'updated:{{ $value }}');
        check($site->handle(new Request('GET', '/en'))->body === 'updated:default');
        check($GLOBALS['loads'] === 6);
        $site->clearCache();
        $site->handle(new Request('GET', '/en'));
        check($GLOBALS['loads'] === 7);
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
