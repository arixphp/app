<?php

declare(strict_types=1);

namespace Arix;

use Arix\Cache\FileCache;
use Arix\Config\Repository;
use Arix\Container\Container;
use Arix\Container\ContainerContext;
use Arix\Http\HttpException;
use Arix\Http\Request;
use Arix\Http\Response;
use Arix\Localization\Translator;
use Arix\Routing\ControllerDispatcher;
use Arix\Routing\RequestContext;
use Arix\Routing\RouteCollector;
use Arix\Routing\Router;
use Arix\Routing\UrlGenerator;
use Arix\Views\TemplateEngine;
use Arix\Views\ViewFactory;
use Throwable;
use InvalidArgumentException;
use Closure;
use RuntimeException;

final class Application
{
    private array $config;
    private array $cacheConfig;
    private FileCache $cache;

    public function __construct(private readonly string $root, array $overrides = [], array $cacheOverrides = [])
    {
        $this->config = array_replace(require $root . '/app/config/app.php', $overrides);
        $limit = $this->config['max_body_bytes'] ?? 1048576;
        if (!is_int($limit) || $limit < 1 || $limit === PHP_INT_MAX) {
            throw new InvalidArgumentException('max_body_bytes must be a positive bounded integer.');
        }
        $this->config['max_body_bytes'] = $limit;
        $this->cacheConfig = array_replace(require $root . '/app/config/cache.php', $cacheOverrides);
        $this->cache = new FileCache($root, $this->cacheConfig);
    }

    public function maxBodyBytes(): int
    {
        return $this->config['max_body_bytes'];
    }

    public function handle(Request $request): Response
    {
        $container = $this->createContainer($request);

        return ContainerContext::run($container, fn (): Response => $this->dispatch($request, $container));
    }

    private function createContainer(Request $request): Container
    {
        $container = new Container();
        $container->instance(Repository::class, $this->configuration($this->config['default_locale']));
        $container->instance(Request::class, $request);
        $container->instance(FileCache::class, $this->cache);
        $container->singleton(ViewFactory::class, static fn (): ViewFactory => new ViewFactory());
        $container->singleton(Translator::class, fn (): Translator => $this->translator($this->config['default_locale']));
        $container->singleton(RouteCollector::class, static fn (): RouteCollector => new RouteCollector());
        $container->singleton(Router::class, static fn (Container $container): Router => new Router($container->get(RouteCollector::class)));
        $container->singleton(ControllerDispatcher::class, fn (Container $container): ControllerDispatcher => new ControllerDispatcher($container, $this->root . '/app/controllers'));
        $container->singleton(TemplateEngine::class, fn (Container $container): TemplateEngine => new TemplateEngine(
            $this->root . '/app/views',
            $container->get(FileCache::class),
            allowEval: (bool) ($this->cacheConfig['allow_eval'] ?? false),
        ));

        return $container;
    }

    private function translator(string $locale): Translator
    {
        return new Translator($this->root . '/app/lang', $this->config['locales'], $this->config['default_locale'], $locale);
    }

    private function configuration(string $locale): Repository
    {
        return new Repository([
            'app' => array_replace($this->config, ['locale' => $locale]),
            'cache' => $this->cacheConfig,
        ]);
    }

    private function dispatch(Request $request, Container $container): Response
    {
        try {
            if (strlen($request->body()) > $this->maxBodyBytes()) {
                throw new HttpException(413, 'Request body is too large.');
            }
            Autoloader::registerControllers($this->root . '/app/controllers');
            $services = (static function (string $file): mixed { return require $file; })($this->root . '/app/services.php');
            if (!$services instanceof Closure) {
                throw new RuntimeException('app/services.php must return a registration closure.');
            }
            $services($container);
            (static function (string $file): void { require $file; })($this->root . '/app/routes.php');
            $base = rtrim($this->config['base_path'], '/');
            $path = $request->path($base);
            $translator = $container->get(Translator::class);
            $canonical = $path === '/' ? '/' : rtrim($path, '/');
            if ($canonical !== $path) {
                return new Response('', 308, ['Location' => $base . $canonical . $this->querySuffix($request)]);
            }
            $routePath = $path;
            $locale = $translator->locale;
            $context = new RequestContext($request, $translator, $routePath, $base);
            $container->instance(RequestContext::class, $context);
            $container->instance(UrlGenerator::class, $context->urls());
            $router = $container->get(Router::class);
            try {
                $route = $router->resolve($request->method, $routePath);
            } catch (HttpException $exception) {
                if ($request->method === 'OPTIONS' && $exception->status === 405) {
                    return new Response('', 204, ['Allow' => implode(', ', $router->allowedMethods($routePath)), 'Cache-Control' => 'no-store']);
                }
                throw $exception;
            }
            $policy = $route->cachePolicy();
            $ttl = $policy->ttl;
            $queryKeys = $policy->query;
            // Unknown query parameters bypass HTML cache rather than sharing a response.
            $cacheable = $ttl > 0 && $route->methods === ['GET']
                && in_array($request->method, ['GET', 'HEAD'], true) && $request->body() === ''
                && array_diff(array_keys($request->query), $queryKeys) === []
                && $request->header('Authorization') === '' && $request->header('Cookie') === '';
            $key = '';
            if ($cacheable) {
                $query = array_intersect_key($request->query, array_flip($queryKeys));
                ksort($query);
                $key = 'html:' . Version::ID . ':' . $this->cacheConfig['version'] . ':' . $base . ':' . $locale . ':' . $routePath . ':' . serialize($query)
                    . ':' . $route->controller . ':' . $route->action
                    . ':' . FileCache::fingerprint([$this->root . '/app/controllers', $this->root . '/app/routes.php', $this->root . '/app/services.php', $this->root . '/app/views', $this->root . '/app/lang', $this->root . '/app/config', $this->root . '/styles', $this->root . '/scripts', $this->root . '/assets']);
                $entry = json_decode($this->cache->read($key) ?? '', true);
                if (is_array($entry) && ($entry['expires'] ?? 0) > time() && is_string($entry['body'] ?? null)) {
                    return $this->html($entry['body'], $locale);
                }
            }
            $view = $container->get(ControllerDispatcher::class)->dispatch($route, $request);
            if ($view instanceof Response) {
                return $view;
            }
            $body = $container->get(TemplateEngine::class)->render($view->name(), $view->data(), $context);
            if ($cacheable) {
                $this->cache->write($key, json_encode(['expires' => time() + $ttl, 'body' => $body], JSON_THROW_ON_ERROR));
            }

            return $this->html($body, $locale);
        } catch (HttpException $exception) {
            return $this->error($exception->status, $exception->getMessage(), $exception->headers);
        } catch (Throwable $exception) {
            error_log('Arix: ' . $exception);

            return $this->error(500, $this->config['debug'] ? (string) $exception : 'Internal server error.');
        }
    }

    public function clearCache(): void
    {
        $this->cache->clear();
    }

    private function html(string $body, string $locale): Response
    {
        return new Response($body, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Language' => $locale,
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function error(int $status, string $message, array $headers = []): Response
    {
        $message = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // Keep the fallback independent of application views and public assets.
        $body = <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>{$status} — ArixPHP</title>
                <style>
                    :root {
                        color-scheme: light dark;
                        --background: #f5f8f9;
                        --text: #16232b;
                        --muted: #4c5f6b;
                        --border: #d2dce2;
                        --accent: #0a745b;
                        --selection: #c3e8de;
                    }
                    * { box-sizing: border-box; }
                    body {
                        margin: 0;
                        min-height: 100vh;
                        min-height: 100svh;
                        display: grid;
                        align-items: center;
                        padding: clamp(1.5rem, 6vw, 4rem);
                        background: var(--background);
                        color: var(--text);
                        font-family: system-ui, "Segoe UI", sans-serif;
                        line-height: 1.6;
                    }
                    main { width: 100%; max-width: 48rem; margin-inline: auto; }
                    h1 {
                        margin: 0 0 1.5rem;
                        color: var(--accent);
                        font-size: clamp(2.4rem, 7vw, 4.8rem);
                        font-weight: 700;
                        line-height: 1.1;
                        letter-spacing: -0.02em;
                    }
                    pre {
                        margin: 0;
                        padding-top: 1.5rem;
                        border-top: 1px solid var(--border);
                        color: var(--muted);
                        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                        font-size: 1rem;
                        white-space: pre-wrap;
                        overflow-wrap: anywhere;
                        tab-size: 4;
                    }
                    ::selection { background: var(--selection); color: var(--text); }
                    @media (prefers-color-scheme: dark) {
                        :root {
                            --background: #101820;
                            --text: #eaf2f5;
                            --muted: #c3d4df;
                            --border: #38505e;
                            --accent: #80e8c7;
                            --selection: #284f46;
                        }
                    }
                </style>
            </head>
            <body>
                <main>
                    <h1>{$status}</h1>
                    <pre>{$message}</pre>
                </main>
            </body>
            </html>
            HTML;

        return new Response($body, $status, array_replace(['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'], $headers));
    }

    private function querySuffix(Request $request): string
    {
        return $request->query === [] ? '' : '?' . http_build_query($request->query, '', '&', PHP_QUERY_RFC3986);
    }
}
