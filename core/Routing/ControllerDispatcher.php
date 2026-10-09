<?php

declare(strict_types=1);

namespace Arix\Routing;

use Arix\Container\Container;
use Arix\Http\Request;
use Arix\Http\Response;
use Arix\Views\Contracts\View;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;
use RuntimeException;

use function count;
use function in_array;
use function is_string;

class ControllerDispatcher
{
    public function __construct(private readonly Container $container, private readonly string $directory)
    {
    }

    public function dispatch(RouteDefinition $route, Request $request): Response|View
    {
        $file = $this->controllerFile($route->controller);
        if (!class_exists($route->controller, false)) {
            require_once $file;
        }
        if (!class_exists($route->controller, false)) {
            throw new RuntimeException('Controller file does not declare ' . $route->controller);
        }
        $class = new ReflectionClass($route->controller);
        if ($class->getName() !== $route->controller || $class->getFileName() !== $file || !$class->isInstantiable()) {
            throw new RuntimeException('Controller class conflicts with its application file: ' . $route->controller);
        }
        if (!$class->hasMethod($route->action) || str_starts_with($route->action, '__')) {
            throw new RuntimeException('Controller action does not exist: ' . $route->action);
        }
        $method = $class->getMethod($route->action);
        if (!$method->isPublic() || $method->isStatic() || $method->getName() !== $route->action || $method->returnsReference()) {
            throw new RuntimeException('Controller action must be a public instance method.');
        }
        $parameters = $method->getParameters();
        if (count($parameters) > 1) {
            throw new RuntimeException('Controller action accepts zero parameters or one Request.');
        }
        if ($parameters !== []) {
            $parameter = $parameters[0];
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->getName() !== Request::class || $type->allowsNull()
                || $parameter->isOptional() || $parameter->isVariadic() || $parameter->isPassedByReference()) {
                throw new RuntimeException('Controller action parameter must be a required Request.');
            }
        }
        $return = $method->getReturnType();
        $types = $return instanceof ReflectionUnionType ? $return->getTypes() : [$return];
        foreach ($types as $type) {
            if (!$type instanceof ReflectionNamedType || $type->allowsNull() || !in_array($type->getName(), [Response::class, View::class], true)) {
                throw new RuntimeException('Controller action must declare a Response or View return type.');
            }
        }
        $controller = $this->container->get($route->controller);
        if (!$controller instanceof $route->controller) {
            throw new RuntimeException('Controller binding returned an incompatible instance.');
        }

        return $controller->{$route->action}(...($parameters === [] ? [] : [$request]));
    }

    /** @param class-string $class */
    private function controllerFile(string $class): string
    {
        if (!preg_match('/^App\\\\Controllers\\\\([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)$/D', $class, $matches)) {
            throw new RuntimeException('Invalid application controller class.');
        }
        $base = realpath($this->directory);
        if ($base === false || is_link($this->directory)) {
            throw new RuntimeException('Controller directory is missing or linked.');
        }
        $path = $base;
        $segments = explode('\\', $matches[1]);
        $segments[array_key_last($segments)] .= '.php';
        foreach ($segments as $segment) {
            if (!is_dir($path) || !in_array($segment, scandir($path) ?: [], true)) {
                throw new RuntimeException('Controller filename must match its class exactly.');
            }
            $path .= '/' . $segment;
            if (is_link($path)) {
                throw new RuntimeException('Controller symlinks are not supported.');
            }
        }
        $file = realpath($path);
        if ($file === false || !is_file($file) || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Controller file is missing or outside its application.');
        }

        return $file;
    }
}
