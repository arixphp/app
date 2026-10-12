<?php

declare(strict_types=1);

use Arix\Config\ConfigServices;
use Arix\Container\Container;
use Arix\Http\Kernel;
use Arix\HttpFoundation\Request;
use Arix\Routing\RouteRegistry;
use Arix\Routing\RoutingHandler;
use Arix\Support\Path;
use Arix\Views\ViewServices;

$root = dirname(__DIR__);
$configDirectory = Path::join($root, 'app', 'config');
$defaults = ConfigServices::register(new Container(), $configDirectory);

return new Kernel(
    new RoutingHandler(Path::join($root, 'app', 'controllers')),
    configure: static function (Container $container) use ($root, $configDirectory): void {
        $config = ConfigServices::register($container, $configDirectory);
        $container->instance(RouteRegistry::class, new RouteRegistry());

        $configureServices = require Path::join($root, 'app', 'services.php');

        if (! $configureServices instanceof Closure) {
            throw new RuntimeException('Application services must return a registration closure.');
        }

        $configureServices($container);

        $cachePath = $config->get('cache.path', 'storage/cache');

        if (! is_string($cachePath) || $cachePath === '') {
            throw new RuntimeException('Application cache path is missing.');
        }

        if (! str_starts_with($cachePath, DIRECTORY_SEPARATOR)) {
            $cachePath = Path::join($root, $cachePath);
        }

        ViewServices::register($container, $root, $container->get(Request::class), [
            'app' => $config->get('app'),
            'cache' => ['path' => $cachePath, 'version' => $config->get('cache.version', '1')],
            'views' => Path::join($root, 'app', 'views'),
            'lang' => Path::join($root, 'app', 'lang'),
        ]);

        require Path::join($root, 'app', 'routes.php');
    },
    debug: (bool) $defaults->get('app.debug', false),
    maxBodyBytes: (int) $defaults->get('app.max_body_bytes', 1048576),
    basePath: (string) $defaults->get('app.base_path', ''),
);
