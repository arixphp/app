<?php

declare(strict_types=1);

namespace Arix\Http;

use Arix\Constants\HttpMethod;
use Arix\Container\Container;
use Arix\Container\ContainerContext;
use Arix\Contracts\RequestHandler;
use Arix\HttpFoundation\Request;
use Arix\HttpFoundation\Response;
use Arix\Views\ErrorViewRenderer;
use Closure;
use Throwable;

class Kernel
{
    private readonly RequestFactory $requests;

    private readonly QueryPathValidator $paths;

    private readonly ErrorResponder $errors;

    public function __construct(
        private readonly RequestHandler $handler,
        private readonly null|Closure $configure = null,
        bool $debug = false,
        int $maxBodyBytes = 1048576,
        string $basePath = '',
        null|Closure $logger = null,
        private readonly array $middleware = [],
    ) {
        $this->requests = new RequestFactory($maxBodyBytes);
        $this->paths = new QueryPathValidator($basePath);
        $this->errors = new ErrorResponder($debug, $logger);
    }

    public function requestFactory(): RequestFactory
    {
        return $this->requests;
    }

    public function handle(Request $request): Response
    {
        $container = new Container();

        return ContainerContext::run($container, function () use ($request, $container): Response {
            try {
                Request::setAllowedHttpMethodOverride([]);

                $this->requests->capture($request);

                $request->attributes->set('_arix_path', $this->paths->path($request));
                $container->instance(Request::class, $request);

                if ($this->configure !== null) {
                    ($this->configure)($container);
                }

                $response = (new MiddlewareDispatcher())->dispatch(
                    $request,
                    $container,
                    $this->middleware,
                    fn (Request $request): Response => $this->handler->handle($request, $container),
                );

                return $response->prepare($request);
            } catch (Throwable $exception) {
                $method = $request->server->get('REQUEST_METHOD') === HttpMethod::HEAD->value ? HttpMethod::HEAD->value : HttpMethod::GET->value;
                $fallback = new Request(server: ['REQUEST_METHOD' => $method], content: '');

                return $this->errors->respond($exception, static function (int $status, string $message) use ($container): string|null {
                    if (! $container->has(ErrorViewRenderer::class)) {

                        return null;
                    }

                    return $container->get(ErrorViewRenderer::class)->render($status, $message);
                })->prepare($fallback);
            }
        });
    }
}
