<?php

declare(strict_types=1);

namespace Arix;

use Arix\Assets\AssetServices;
use Arix\Config\ConfigServices;
use Arix\Config\Repository;
use Arix\Constants\HttpMethod;
use Arix\Container\Container;
use Arix\Contracts\RequestHandler;
use Arix\Http\Kernel;
use Arix\Http\MiddlewareResolver;
use Arix\HttpFoundation\Request;
use Arix\HttpFoundation\Response;
use Arix\Routing\RouteRegistry;
use Arix\Routing\RoutingHandler;
use Arix\Routing\UrlGenerator;
use Arix\Scheduling\Schedule;
use Arix\Storage\StorageServices;
use Arix\Support\ApplicationAutoloader;
use Arix\Support\Path;
use Arix\Support\RuntimePaths;
use Arix\Views\ViewServices;
use Closure;
use RuntimeException;

use function array_key_exists;
use function array_values;
use function is_array;
use function is_string;

class Application
{
    private function __construct(
        private readonly string $root,
        private readonly string $configDirectory,
        private readonly string $controllerDirectory,
        private readonly string $routesFile,
        private readonly string $viewsDirectory,
        private readonly string $languageDirectory,
        private readonly Repository $defaults
    ) {}

    public static function create(string $root): self
    {
        $base = RuntimePaths::fromApplicationRoot($root)->root;

        return ApplicationAutoloader::run($base, static fn (): self => self::load($base));
    }

    private static function load(string $base): self
    {
        $paths = RuntimePaths::fromStrictRoot($base);
        $configDirectory = $paths->resolve(['app', 'config'])
            ?? throw new RuntimeException('Application configuration is missing.');
        $routesFile = $paths->resolve(['app', 'routes.php'])
            ?? throw new RuntimeException('Application routes are missing.');

        if (! is_dir($configDirectory)) {
            throw new RuntimeException("Application configuration is missing: {$configDirectory}");
        }

        if (! is_file($routesFile) || ! is_readable($routesFile)) {
            throw new RuntimeException("Application routes are missing: {$routesFile}");
        }

        $defaults = ConfigServices::register(new Container(), $configDirectory);
        $servicesFile = $paths->resolve(['app', 'services.php']);

        if ($servicesFile !== null && is_file($servicesFile)) {
            self::servicesClosure($servicesFile);
        }

        return new self(
            $base,
            $configDirectory,
            Path::join($base, 'app', 'Controllers'),
            $routesFile,
            Path::join($base, 'app', 'views'),
            Path::join($base, 'app', 'lang'),
            $defaults,
        );
    }

    public static function configure(string $basePath): ApplicationBuilder
    {
        return new ApplicationBuilder($basePath);
    }

    public function kernel(array $middleware = []): Kernel
    {
        $routing = new RoutingHandler($this->controllerDirectory);
        $register = $this->registerPresentation(...);

        return new Kernel(
            new class($routing, $register) implements RequestHandler
            {
                public function __construct(private readonly RequestHandler $routing, private readonly Closure $register) {}

                public function handle(Request $request, Container $container): Response
                {
                    ($this->register)($container);

                    return $this->routing->handle($request, $container);
                }
            },
            configure: function (Container $container): void {
                $this->register($container);
            },
            debug: (bool) $this->defaults->get('app.debug', false),
            maxBodyBytes: (int) $this->defaults->get('app.max_body_bytes', 1048576),
            basePath: (string) $this->defaults->get('app.base_path', ''),
            middleware: $middleware,
            applicationRoot: $this->root,
        );
    }

    public function registerConsole(Container $container): void
    {
        ApplicationAutoloader::run($this->root, function () use ($container): void {
            $trustedHosts = array_map(static fn (string $pattern): string => substr($pattern, 1, -2), Request::getTrustedHosts());

            try {
                $container->instance(Request::class, new Request(server: ['REQUEST_METHOD' => HttpMethod::GET->value], content: ''));

                $this->register($container);
                $this->registerPresentation($container, routes: false);
            } finally {
                Request::setTrustedHosts($trustedHosts);
            }
        });
    }

    public function schedules(Schedule $schedule): void
    {
        ApplicationAutoloader::run($this->root, function () use ($schedule): void {
            $this->registerSchedules($schedule);
        });
    }

    private function registerSchedules(Schedule $schedule): void
    {
        $file = RuntimePaths::fromStrictRoot($this->root)->resolve(['app', 'schedules.php']);

        if ($file === null || ! is_file($file)) {
            return;
        }

        if (is_link($file)) {
            throw new RuntimeException("Application schedules are linked: {$file}");
        }

        $register = require $file;

        if (! $register instanceof Closure) {
            throw new RuntimeException('Application schedules must return a registration closure.');
        }

        $register($schedule);
    }

    private function register(Container $container): void
    {
        $paths = RuntimePaths::fromStrictRoot($this->root);
        $configDirectory = $paths->resolve(['app', 'config'])
            ?? throw new RuntimeException("Application configuration is missing: {$this->configDirectory}");
        $config = ConfigServices::register($container, $configDirectory);
        $servicesFile = $paths->resolve(['app', 'services.php']);
        $container->instance(RouteRegistry::class, new RouteRegistry());
        $container->instance(MiddlewareResolver::class, new MiddlewareResolver(Path::join($this->root, 'app', 'Middleware')));

        if ($servicesFile !== null && is_file($servicesFile)) {
            self::servicesClosure($servicesFile)($container);
        }

        $app = $config->get('app');

        if (! is_array($app) || ! is_string($app['default_locale'] ?? null)) {
            throw new RuntimeException('Application configuration is missing.');
        }

        self::trustHosts($app);
        $container->get(Request::class)->setDefaultLocale($app['default_locale']);
    }

    /**
     * Restrict the accepted host headers.
     *
     * Without this the host of a request is controlled by the visitor, and
     * application code that builds absolute URLs from it is exposed to host
     * header poisoning.
     *
     * @param  array<string, mixed>  $app
     */
    private static function trustHosts(array $app): void
    {
        $hosts = array_key_exists('trusted_hosts', $app) ? $app['trusted_hosts'] : [];

        if (! is_array($hosts) || ! array_is_list($hosts)) {
            throw new RuntimeException('Application trusted_hosts must be a list of host patterns.');
        }

        foreach ($hosts as $host) {
            if (! is_string($host) || $host === '' || ! self::validHostPattern($host)) {
                throw new RuntimeException('Application trusted_hosts must be a list of host patterns.');
            }
        }

        Request::setTrustedHosts(array_values($hosts));
    }

    private static function validHostPattern(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);

        try {

            return preg_match("{(?|(?:{$pattern}))}i", '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    private function registerPresentation(Container $container, bool $routes = true): void
    {
        $config = $container->get(Repository::class);
        $app = $config->get('app');

        if (! is_array($app)) {
            throw new RuntimeException('Application configuration is missing.');
        }

        $app['locale'] = $container->get(Request::class)->getLocale();
        $container->instance(Repository::class, new Repository(array_replace($config->get(), ['app' => $app])));

        $request = $container->get(Request::class);
        $path = $request->attributes->get('_arix_path', '/');
        AssetServices::register($container, $this->root, new UrlGenerator((string) ($app['base_path'] ?? ''), is_string($path) ? $path : '/'));
        $cache = ['version' => $config->get('cache.version', '1')];
        $cacheConfiguration = $config->get('cache', []);

        if (is_array($cacheConfiguration) && array_key_exists('templates', $cacheConfiguration)) {
            $cache['templates'] = $cacheConfiguration['templates'];
        }

        ViewServices::register($container, $this->root, $container->get(Request::class), [
            'app' => $app,
            'cache' => $cache,
            'views' => $this->viewsDirectory,
            'lang' => $this->languageDirectory,
        ]);

        $storage = RuntimePaths::fromStrictRoot($this->root)->storageDirectory();
        StorageServices::register($container, Path::join($storage, 'app'));

        if ($routes) {
            $routesFile = RuntimePaths::fromStrictRoot($this->root)->resolve(['app', 'routes.php'])
                ?? throw new RuntimeException("Application routes are missing: {$this->routesFile}");

            if (! is_file($routesFile) || ! is_readable($routesFile)) {
                throw new RuntimeException("Application routes are missing: {$this->routesFile}");
            }

            require $routesFile;
        }
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
}
