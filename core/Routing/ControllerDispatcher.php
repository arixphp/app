<?php

declare(strict_types=1);

namespace Arix\Routing;

use Arix\Container\Container;
use Arix\HttpFoundation\Request;
use Arix\HttpFoundation\Response;
use Arix\Views\TemplateEngine;
use Arix\Views\Contracts\View as ViewContract;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;
use RuntimeException;

use function count;
use function in_array;

class ControllerDispatcher
{
    public function __construct(private readonly string $directory) {}

    public function dispatch(RouteDefinition $route, Request $request, Container $container): Response
    {
        $file = $this->controllerFile($route->controller);

        if (! class_exists($route->controller, false)) {
            require_once $file;
        }

        if (! class_exists($route->controller, false)) {
            throw new RuntimeException("Controller file does not declare {$route->controller}");
        }

        $class = new ReflectionClass($route->controller);

        if ($class->getName() !== $route->controller || $class->getFileName() !== $file || ! $class->isInstantiable()) {
            throw new RuntimeException("Controller class conflicts with its application file: {$route->controller}");
        }

        if (! $class->hasMethod($route->action) || str_starts_with($route->action, '__')) {
            throw new RuntimeException("Controller action does not exist: {$route->action}");
        }

        $method = $class->getMethod($route->action);

        if (! $method->isPublic() || $method->isStatic() || $method->getName() !== $route->action || $method->returnsReference()) {
            throw new RuntimeException('Controller action must be a public instance method.');
        }

        $parameters = $method->getParameters();

        if (count($parameters) > 1) {
            throw new RuntimeException('Controller action accepts zero parameters or one Request.');
        }

        if ($parameters !== []) {
            $parameter = $parameters[0];
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->getName() !== Request::class || $type->allowsNull()
                || $parameter->isOptional() || $parameter->isVariadic() || $parameter->isPassedByReference()) {
                throw new RuntimeException('Controller action parameter must be a required Request.');
            }
        }

        $return = $method->getReturnType();
        $types = $return instanceof ReflectionUnionType ? $return->getTypes() : [$return];

        foreach ($types as $type) {
            if (! $type instanceof ReflectionNamedType || $type->allowsNull() || (! is_a($type->getName(), Response::class, true) && ! is_a($type->getName(), ViewContract::class, true))) {
                throw new RuntimeException('Controller action must declare a Response or View return type.');
            }
        }

        $controller = $container->get($route->controller);

        if (! $controller instanceof $route->controller) {
            throw new RuntimeException('Controller binding returned an incompatible instance.');
        }

        $result = $controller->{$route->action}(...($parameters === [] ? [] : [$request]));

        if ($result instanceof Response) {
            return $result;
        }

        if (! $result instanceof ViewContract) {
            throw new RuntimeException('Controller action must return a Response or View.');
        }

        $engine = $container->get(TemplateEngine::class);

        if (! $engine instanceof TemplateEngine) {
            throw new RuntimeException('TemplateEngine binding returned an incompatible instance.');
        }

        return new Response($engine->render($result->name(), $result->data()), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function controllerFile(string $class): string
    {
        if (! preg_match('/^App\\\\Controllers\\\\([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)$/D', $class, $matches)) {
            throw new RuntimeException('Invalid application controller class.');
        }

        // Check ancestors before realpath can hide a linked directory.
        $ancestor = $this->directory;

        while (true) {
            if (is_link($ancestor)) {
                throw new RuntimeException('Controller directory is linked.');
            }

            $parent = dirname($ancestor);

            if ($parent === $ancestor) {
                break;
            }

            $ancestor = $parent;
        }

        $base = realpath($this->directory);

        if ($base === false || ! is_dir($base)) {
            throw new RuntimeException('Controller directory is missing.');
        }

        $path = $base;
        $segments = explode('\\', $matches[1]);
        $segments[array_key_last($segments)] .= '.php';

        foreach ($segments as $segment) {
            if (! is_dir($path) || ! in_array($segment, scandir($path) ?: [], true)) {
                throw new RuntimeException('Controller filename must match its class exactly.');
            }

            $path .= DIRECTORY_SEPARATOR.$segment;

            if (is_link($path)) {
                throw new RuntimeException('Controller symlinks are not supported.');
            }
        }

        $file = realpath($path);

        if ($file === false || ! is_file($file) || ! str_starts_with($file, $base.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Controller file is missing or outside its application.');
        }

        return $file;
    }
}
