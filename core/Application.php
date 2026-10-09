<?php

declare(strict_types=1);

namespace Arix;

use Arix\Cache\FileCache;
use Arix\Constants\HttpMethod;
use Arix\Container\Container;
use Arix\Container\ContainerContext;
use Arix\Http\HttpException;
use Arix\Http\Request;
use Arix\Http\Response;
use Arix\Localization\Translator;
use Arix\Pages\PageFactory;
use Arix\Routing\PageContext;
use Arix\Routing\PageRouter;
use Arix\Views\TemplateEngine;
use Arix\Views\ViewFactory;
use Throwable;

final class Application
{
    private array $config;
    private array $cacheConfig;
    private FileCache $cache;

    public function __construct(private readonly string $root, array $overrides = [], array $cacheOverrides = [])
    {
        $this->config = array_replace(require $root . '/app/config/app.php', $overrides);
        $this->cacheConfig = array_replace(require $root . '/app/config/cache.php', $cacheOverrides);
        $this->cache = new FileCache($root, $this->cacheConfig);
    }

    public function handle(Request $request): Response
    {
        $container = $this->createContainer($request);

        return ContainerContext::run($container, fn (): Response => $this->dispatch($request, $container));
    }

    private function createContainer(Request $request): Container
    {
        $container = new Container();
        $container->instance(Request::class, $request);
        $container->instance(FileCache::class, $this->cache);
        $container->singleton(ViewFactory::class, static fn (): ViewFactory => new ViewFactory());
        $container->singleton(Translator::class, fn (): Translator => $this->translator($this->config['default_locale']));
        $container->singleton(PageRouter::class, fn (Container $container): PageRouter => new PageRouter($this->root . '/app/pages', $container->get(FileCache::class)));
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

    private function dispatch(Request $request, Container $container): Response
    {
        try {
            if (!in_array($request->httpMethod(), [HttpMethod::GET, HttpMethod::HEAD], true)) {
                return $this->error(405, 'Method not allowed.', ['Allow' => HttpMethod::GET->value . ', ' . HttpMethod::HEAD->value]);
            }
            $base = rtrim($this->config['base_path'], '/');
            $path = $request->path($base);
            $translator = $container->get(Translator::class);
            if ($path === '/') {
                $locale = $translator->negotiate($request->header('Accept-Language'));

                return new Response('', 302, ['Location' => $base . '/' . $locale . $this->querySuffix($request), 'Vary' => 'Accept-Language', 'Cache-Control' => 'no-store']);
            }
            $canonical = rtrim($path, '/');
            if ($canonical !== $path) {
                return new Response('', 308, ['Location' => $base . $canonical . $this->querySuffix($request)]);
            }
            $segments = explode('/', ltrim($path, '/'));
            $locale = array_shift($segments);
            if (!in_array($locale, $this->config['locales'], true)) {
                throw new HttpException(404, 'Page not found.');
            }
            $pagePath = '/' . implode('/', $segments);
            $translator = $this->translator($locale);
            $container->instance(Translator::class, $translator);
            $context = new PageContext($request, $translator, $pagePath, $base);
            $container->instance(PageContext::class, $context);
            $route = $container->get(PageRouter::class)->resolve($pagePath);
            $ttl = $route['cache']['ttl'];
            $queryKeys = $route['cache']['query'];
            // Unknown query parameters bypass HTML cache rather than sharing a response.
            $cacheable = $ttl > 0 && array_diff(array_keys($request->query), $queryKeys) === []
                && $request->header('Authorization') === '' && $request->header('Cookie') === '';
            $key = '';
            if ($cacheable) {
                $query = array_intersect_key($request->query, array_flip($queryKeys));
                ksort($query);
                $key = 'html:' . Version::ID . ':' . $this->cacheConfig['version'] . ':' . $base . ':' . $locale . ':' . $pagePath . ':' . serialize($query)
                    . ':' . FileCache::fingerprint([$this->root . '/app/pages', $this->root . '/app/views', $this->root . '/app/lang', $this->root . '/app/config', $this->root . '/styles', $this->root . '/scripts', $this->root . '/assets']);
                $entry = json_decode($this->cache->read($key) ?? '', true);
                if (is_array($entry) && ($entry['expires'] ?? 0) > time() && is_string($entry['body'] ?? null)) {
                    return $this->html($entry['body'], $locale);
                }
            }
            $view = PageFactory::make($route['class'], $route['file'], $context)->render();
            $body = $container->get(TemplateEngine::class)->render($view->name(), $view->data(), $context);
            if ($cacheable) {
                $this->cache->write($key, json_encode(['expires' => time() + $ttl, 'body' => $body], JSON_THROW_ON_ERROR));
            }

            return $this->html($body, $locale);
        } catch (HttpException $exception) {
            return $this->error($exception->status, $exception->getMessage());
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
        $body = '<!doctype html><html lang="en"><meta charset="utf-8"><title>' . $status . '</title><h1>' . $status
            . '</h1><pre>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre></html>';

        return new Response($body, $status, array_replace(['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'], $headers));
    }

    private function querySuffix(Request $request): string
    {
        return $request->query === [] ? '' : '?' . http_build_query($request->query, '', '&', PHP_QUERY_RFC3986);
    }
}
