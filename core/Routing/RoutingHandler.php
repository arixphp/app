<?php

declare(strict_types=1);

namespace Arix\Routing;

use Arix\Constants\HttpStatusCode;
use Arix\Container\Container;
use Arix\Contracts\RequestHandler;
use Arix\Http\MiddlewareDispatcher;
use Arix\HttpFoundation\Request;
use Arix\HttpFoundation\Response;
use LogicException;

use function is_array;
use function is_string;

class RoutingHandler implements RequestHandler
{
    private readonly ControllerDispatcher $controllers;

    public function __construct(string $controllerDirectory)
    {
        $this->controllers = new ControllerDispatcher($controllerDirectory);
    }

    public function handle(Request $request, Container $container): Response
    {
        $path = $request->attributes->get('_arix_path');

        if (! is_string($path)) {
            throw new LogicException('Routing requires the path validated by Kernel.');
        }

        $previous = $request->attributes->get('_arix_route_parameters', []);

        if (! is_array($previous) || ! array_is_list($previous)) {
            throw new LogicException('Invalid internal route parameter state.');
        }

        foreach ($previous as $name) {
            if (! is_string($name) || str_starts_with($name, '_arix_')) {
                throw new LogicException('Invalid internal route parameter name.');
            }
        }

        foreach ($previous as $name) {
            $request->attributes->remove($name);
        }

        $request->attributes->set('_arix_route_parameters', []);
        $registry = $container->get(RouteRegistry::class);

        if (! $registry instanceof RouteRegistry) {
            throw new LogicException('RouteRegistry binding returned an incompatible instance.');
        }

        $match = new Router($registry)->resolve($request->getMethod(), $path);

        if ($match->route === null) {
            return new Response('', HttpStatusCode::NO_CONTENT->value, ['Allow' => implode(', ', $match->allowedMethods)]);
        }

        foreach ($match->parameters as $name => $value) {
            $request->attributes->set($name, $value);
        }

        $request->attributes->set('_arix_route_parameters', array_keys($match->parameters));

        return (new MiddlewareDispatcher())->dispatch(
            $request,
            $container,
            $match->route->middleware,
            fn (Request $request): Response => $this->controllers->dispatch($match->route, $request, $container),
        );
    }
}
