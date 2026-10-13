<?php

declare(strict_types=1);

namespace Arix;

use Arix\Config\ConfigServices;
use Arix\Config\Repository;
use Arix\Container\Container;
use Arix\Http\Kernel;
use Arix\HttpFoundation\Request;
use Arix\Routing\RouteRegistry;
use Arix\Routing\RoutingHandler;
use Arix\Support\Path;
use Arix\Views\ViewServices;
use Closure;
use RuntimeException;

use function is_array;
use function is_string;

class Application
{
    private function __construct(
        private readonly string $root,
        private readonly string $configDirectory,
        private readonly string $controllerDirectory,
        private readonly string $servicesFile,
        private readonly string $routesFile,
        private readonly string $viewsDirectory,
        private readonly string $languageDirectory,
        private readonly Repository $defaults
    ) {}

    public static function create(string $root): self
    {
        $base = self::canonicalDirectory($root, 'Application root');
        $configDirectory = Path::join($base, 'app', 'config');
        $routesFile = Path::join($base, 'app', 'routes.php');

        if (! is_dir($configDirectory)) {
            throw new RuntimeException("Application configuration is missing: {$configDirectory}");
        }

        if (! is_file($routesFile) || ! is_readable($routesFile)) {
            throw new RuntimeException("Application routes are missing: {$routesFile}");
        }

        $defaults = ConfigServices::register(new Container(), $configDirectory);
        $servicesFile = Path::join($base, 'app', 'services.php');

        if (is_file($servicesFile)) {
            self::servicesClosure($servicesFile);
        }

        return new self(
            $base,
            $configDirectory,
            Path::join($base, 'app', 'controllers'),
            $servicesFile,
            $routesFile,
            Path::join($base, 'app', 'views'),
            Path::join($base, 'app', 'lang'),
            $defaults,
        );
    }

    public function kernel(): Kernel
    {
        return new Kernel(
            new RoutingHandler($this->controllerDirectory),
            configure: function (Container $container): void {
                $this->register($container);
            },
            debug: (bool) $this->defaults->get('app.debug', false),
            maxBodyBytes: (int) $this->defaults->get('app.max_body_bytes', 1048576),
            basePath: (string) $this->defaults->get('app.base_path', ''),
        );
    }

    private function register(Container $container): void
    {
        $config = ConfigServices::register($container, $this->configDirectory);
        $container->instance(RouteRegistry::class, new RouteRegistry());

        if (is_file($this->servicesFile)) {
            self::servicesClosure($this->servicesFile)($container);
        }

        $config = $container->get(Repository::class);
        $cachePath = $config->get('cache.path', 'storage/cache');

        if (! is_string($cachePath) || $cachePath === '') {
            throw new RuntimeException('Application cache path is missing.');
        }

        if (! str_starts_with($cachePath, DIRECTORY_SEPARATOR)) {
            $cachePath = Path::join($this->root, $cachePath);
        }

        $app = $config->get('app');

        if (! is_array($app)) {
            throw new RuntimeException('Application configuration is missing.');
        }

        $app['locale'] = $app['default_locale'] ?? null;
        $container->instance(Repository::class, new Repository(array_replace($config->get(), ['app' => $app])));

        ViewServices::register($container, $this->root, $container->get(Request::class), [
            'app' => $app,
            'cache' => ['path' => $cachePath, 'version' => $config->get('cache.version', '1')],
            'views' => $this->viewsDirectory,
            'lang' => $this->languageDirectory,
        ]);

        if (is_link($this->routesFile)) {
            throw new RuntimeException("Application routes are linked: {$this->routesFile}");
        }

        if (! is_file($this->routesFile) || ! is_readable($this->routesFile)) {
            throw new RuntimeException("Application routes are missing: {$this->routesFile}");
        }

        require $this->routesFile;
    }

    private static function servicesClosure(string $file): Closure
    {
        if (is_link($file)) {
            throw new RuntimeException("Application services are linked: {$file}");
        }

        if (! is_file($file) || ! is_readable($file)) {
            throw new RuntimeException("Application services are missing: {$file}");
        }

        $configureServices = require $file;

        if (! $configureServices instanceof Closure) {
            throw new RuntimeException('Application services must return a registration closure.');
        }

        return $configureServices;
    }

    private static function canonicalDirectory(string $path, string $label): string
    {
        $ancestor = $path;

        while (true) {
            if (is_link($ancestor)) {
                throw new RuntimeException("{$label} is linked.");
            }

            $parent = dirname($ancestor);

            if ($parent === $ancestor) {
                break;
            }

            $ancestor = $parent;
        }

        $real = realpath($path);

        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException("{$label} is missing: {$path}");
        }

        return $real;
    }
}
