<?php

declare(strict_types=1);

namespace Arix\Routing;

use Arix\Cache\FileCache;
use Arix\Http\HttpException;
use Arix\Pages\Attributes\Cache;
use Arix\Pages\Attributes\Get;
use Arix\Pages\Contracts\Page;
use Arix\Pages\PageFactory;
use Arix\Version;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class PageRouter
{
    public function __construct(private readonly string $directory, private readonly FileCache $cache)
    {
    }

    public function resolve(string $path): array
    {
        // Paths are map keys, never filesystem paths or class names from request input.
        if (!preg_match('~^/(?:[\pL\pN_-]+(?:/[\pL\pN_-]+)*)?$~uD', $path)) {
            throw new HttpException(404, 'Page not found.');
        }
        $base = realpath($this->directory);
        if ($base === false) {
            throw new HttpException(404, 'Page not found.');
        }
        $key = 'routes:' . Version::ID . ':' . $base . ':' . FileCache::fingerprint([$base]);
        $routes = json_decode($this->cache->read($key) ?? '', true);
        if (!is_array($routes)) {
            $routes = $this->discover($base);
            $this->cache->write($key, json_encode($routes, JSON_THROW_ON_ERROR));
        }
        if (!isset($routes[$path])) {
            throw new HttpException(404, 'Page not found.');
        }
        $route = $routes[$path];
        $file = realpath($base . '/' . $route['file']);
        if ($file === false || !is_file($file) || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Page file is outside the allowed directory.');
        }
        $route['file'] = $file;

        return $route;
    }

    private function discover(string $base): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('Page symlinks are not supported.');
            }
            if ($file->isFile() && $file->getExtension() === 'php') {
                $relative = substr($file->getPathname(), strlen($base) + 1);
                if (!preg_match('~^[A-Za-z_][A-Za-z0-9_]*(?:/[A-Za-z_][A-Za-z0-9_]*)*\.php$~D', $relative)) {
                    throw new RuntimeException('Invalid page filename: ' . $relative);
                }
                $files[$relative] = $file->getPathname();
            }
        }
        ksort($files);
        $routes = [];
        foreach ($files as $relative => $file) {
            $class = 'App\\Pages\\' . str_replace('/', '\\', substr($relative, 0, -4));
            $reflection = PageFactory::reflect($class, $file);
            $attributes = $reflection->getAttributes(Get::class);
            if (!$reflection->isInstantiable() || !$reflection->implementsInterface(Page::class) || $attributes === []) {
                continue;
            }
            if (count($attributes) !== 1) {
                throw new RuntimeException('A page must have exactly one GET route: ' . $class);
            }
            $path = $attributes[0]->newInstance()->path;
            if (isset($routes[$path])) {
                throw new RuntimeException('Duplicate GET route: ' . $path);
            }
            $cacheAttributes = $reflection->getAttributes(Cache::class);
            if (count($cacheAttributes) > 1) {
                throw new RuntimeException('Duplicate cache attributes: ' . $class);
            }
            $policy = $cacheAttributes === [] ? new Cache() : $cacheAttributes[0]->newInstance();
            $routes[$path] = ['class' => $class, 'file' => $relative, 'cache' => ['ttl' => $policy->ttl, 'query' => $policy->query]];
        }

        return $routes;
    }
}
