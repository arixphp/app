<?php

declare(strict_types=1);

namespace Arix;

use Arix\Assets\AssetServices;
use Arix\Config\ConfigServices;
use Arix\Config\Repository;
use Arix\Container\Container;
use Arix\Contracts\RequestHandler;
use Arix\Http\Kernel;
use Arix\Http\MiddlewareResolver;
use Arix\Http\RequestAttributes;
use Arix\Http\RequestUrlContext;
use Arix\HttpFoundation\Request;
use Arix\HttpFoundation\Response;
use Arix\Routing\ApplicationUrl;
use Arix\Routing\RouteRegistry;
use Arix\Routing\RouteResolver;
use Arix\Routing\RouteUrlGenerator;
use Arix\Routing\RoutingHandler;
use Arix\Routing\UrlGenerator;
use Arix\Scheduling\FileScheduleLock;
use Arix\Scheduling\Kernel as ScheduleKernel;
use Arix\Scheduling\Schedule;
use Arix\Scheduling\ScheduleLogger;
use Arix\Storage\Storage;
use Arix\Storage\StorageServices;
use Arix\Support\Path;
use Arix\Support\RuntimePaths;
use Arix\Views\ViewServices;
use Closure;
use RuntimeException;

use function array_key_exists;
use function array_values;
use function is_array;
use function is_bool;
use function is_string;

class Application
{
    private function __construct(
        private readonly string $root,
        private readonly string $configDirectory,
        private readonly string $controllerDirectory,
        private readonly string $viewsDirectory,
        private readonly string $languageDirectory,
        private readonly Repository $defaults
    ) {}

    public static function create(string $root): self
    {
        return self::load(RuntimePaths::fromApplicationRoot($root)->root, requireRoutes: true);
    }

    public static function createForScheduling(string $root): self
    {
        return self::load(RuntimePaths::fromApplicationRoot($root)->root, requireRoutes: false);
    }

    private static function load(string $base, bool $requireRoutes): self
    {
        $paths = RuntimePaths::fromStrictRoot($base);

        $configDirectory = $paths->resolve(['app', 'config'])
            ?? throw new RuntimeException('Application configuration is missing.');

        if (! is_dir($configDirectory)) {
            throw new RuntimeException("Application configuration is missing: {$configDirectory}");
        }

        if ($requireRoutes) {
            $routesFile = $paths->resolve(['app', 'routes.php'])
                ?? throw new RuntimeException('Application routes are missing.');

            if (! is_file($routesFile) || ! is_readable($routesFile)) {
                throw new RuntimeException("Application routes are missing: {$routesFile}");
            }
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
        $debug = $this->defaults->get('app.debug', false);

        if (! is_bool($debug)) {
            $debug = false;
        }

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
            debug: $debug,
            maxBodyBytes: (int) $this->defaults->get('app.max_body_bytes', 1048576),
            middleware: $middleware,
        );
    }

    public function registerConsole(Container $container): void
    {
        $trustedHosts = array_map(static fn (string $pattern): string => substr($pattern, 1, -2), Request::getTrustedHosts());

        try {
            $app = $this->defaults->get('app', []);
            $request = ApplicationUrl::fromConfiguration($app)->request();
            $context = RequestUrlContext::fromRequest($request);
            $request->attributes->set(RequestAttributes::URL_CONTEXT, $context);
            $request->attributes->set(RequestAttributes::PATH, $context->path);
            $container->instance(Request::class, $request);

            $this->register($container);
            // Incoming HTTP host restrictions do not govern configured console URLs.
            Request::setTrustedHosts([]);
            $this->registerPresentation($container, routes: false);
            // Schedules need named URLs, but route registration remains lazy.
            $container->singleton(RouteUrlGenerator::class, fn (): RouteUrlGenerator => RouteResolver::fromRoot($this->root));
        } finally {
            Request::setTrustedHosts($trustedHosts);
        }
    }

    public function schedules(Schedule $schedule): void
    {
        $this->registerSchedules($schedule);
    }

    public function scheduleKernel(Container $container): ScheduleKernel
    {
        $this->registerConsole($container);
        $config = $container->get(Repository::class);
        $timezone = $config->get('schedule.timezone', 'UTC');
        $grace = (int) $config->get('schedule.grace', 3600);
        $schedule = new Schedule(is_string($timezone) ? $timezone : 'UTC');
        $this->schedules($schedule);
        $lock = new FileScheduleLock($container->get(Storage::class));
        $paths = RuntimePaths::fromStrictRoot($this->root);

        return new ScheduleKernel(
            $schedule,
            $lock,
            $grace,
            new ScheduleLogger($paths->scheduleLogPath(), $paths->scheduleLogDirectory()),
        );
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
        $context = $request->attributes->get(RequestAttributes::URL_CONTEXT);

        if (! $context instanceof RequestUrlContext) {
            throw new RuntimeException('Validated request URL context is missing.');
        }

        AssetServices::register($container, $this->root, UrlGenerator::fromRequest($request, $context), UrlGenerator::fromRequest($request, $context, assets: true));
        $cache = ['version' => $config->get('cache.version', '1')];
        $cacheConfiguration = $config->get('cache', []);

        if (is_array($cacheConfiguration) && array_key_exists('templates', $cacheConfiguration)) {
            $cache['templates'] = $cacheConfiguration['templates'];
        }

        ViewServices::register($container, $this->root, [
            'app' => $app,
            'cache' => $cache,
            'views' => $this->viewsDirectory,
            'lang' => $this->languageDirectory,
        ]);

        $storage = RuntimePaths::fromStrictRoot($this->root)->storageDirectory();
        StorageServices::register($container, Path::join($storage, 'app'));

        if ($routes) {
            require $this->routesFile();
        }
    }

    private function routesFile(): string
    {
        $file = RuntimePaths::fromStrictRoot($this->root)->resolve(['app', 'routes.php'])
            ?? throw new RuntimeException('Application routes are missing.');

        if (! is_file($file) || ! is_readable($file)) {
            throw new RuntimeException("Application routes are missing: {$file}");
        }

        return $file;
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
