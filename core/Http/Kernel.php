<?php

declare(strict_types=1);

namespace Arix\Http;

use Arix\Constants\HttpMethod;
use Arix\Constants\HttpStatusCode;
use Arix\Container\Container;
use Arix\Container\ContainerContext;
use Arix\Contracts\RequestHandler;
use Arix\HttpFoundation\Request;
use Arix\HttpFoundation\Response;
use Arix\Support\ApplicationAutoloader;
use Arix\Views\ErrorViewRenderer;
use Closure;
use Throwable;

use function array_map;

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
        private readonly string|null $applicationRoot = null,
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

        return $this->applicationRoot === null
            ? $this->dispatch($request)
            : ApplicationAutoloader::run($this->applicationRoot, fn (): Response => $this->dispatch($request));
    }

    private function dispatch(Request $request): Response
    {
        $container = new Container();

        return ContainerContext::run($container, function () use ($request, $container): Response {
            // HttpFoundation's getter returns {pattern}i; its setter takes raw patterns.
            $trustedHosts = array_map(static fn (string $pattern): string => substr($pattern, 1, -2), Request::getTrustedHosts());

            try {
                Request::setAllowedHttpMethodOverride([]);

                $this->requests->capture($request);

                $request->attributes->set('_arix_path', $this->paths->path($request));
                $container->instance(Request::class, $request);

                if ($this->configure !== null) {
                    ($this->configure)($container);
                }

                // Validate against this application's policy before middleware or routing.
                $this->validateHost($request);

                // Application configuration must not re-enable the method
                // override: the routed method is always the sent one.
                Request::setAllowedHttpMethodOverride([]);

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
            } finally {
                Request::setTrustedHosts($trustedHosts);
            }
        });
    }

    private function validateHost(Request $request): void
    {
        // A previously rejected Request can return an empty host instead of throwing
        // again. Validate its current server/header data with fresh validity state.
        $validation = new Request(server: $request->server->all());
        $validation->headers->replace($request->headers->all());
        $host = $validation->getHost();

        // Consumers must see the same host, even if an injected Request cached
        // forwarded-header values before proxy configuration changed.
        if ($request->getHost() !== $host) {
            throw new HttpException(HttpStatusCode::BAD_REQUEST, 'Invalid host.');
        }
    }
}
