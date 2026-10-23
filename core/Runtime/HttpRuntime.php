<?php

declare(strict_types=1);

namespace Arix\Runtime;

use Arix\Constants\HttpMethod;
use Arix\Constants\HttpStatusCode;
use Arix\Http\ErrorResponder;
use Arix\Http\HttpException;
use Arix\Http\Kernel;
use Arix\HttpFoundation\Request;
use Arix\HttpFoundation\Response;
use Closure;
use RuntimeException;
use Throwable;

use function in_array;

class HttpRuntime
{
    private const array SUPPORTED_SAPIS = [
        'apache2handler',
        'cgi-fcgi',
        'fpm-fcgi',
        'frankenphp',
        'litespeed',
    ];

    public function __construct(
        private readonly string $applicationRoot,
        private readonly Closure|null $logger = null,
    ) {}

    public function handle(Closure $bootstrap, string $sapi = PHP_SAPI): Response
    {
        if (! in_array($sapi, self::SUPPORTED_SAPIS, true)) {
            return $this->failure(new HttpException(HttpStatusCode::SERVICE_UNAVAILABLE, 'This PHP runtime is not supported.'));
        }

        $errors = null;

        try {
            $execute = function () use ($bootstrap, &$errors): Response {
                $errors = new ErrorResponder(Environment::debug(), $this->logger);
                $kernel = $bootstrap();

                if (! $kernel instanceof Kernel) {
                    throw new RuntimeException('Application bootstrap must return a Arix\\Http\\Kernel.');
                }

                return $kernel->handle($kernel->requestFactory()->fromGlobals());
            };

            return Environment::run($this->applicationRoot, $execute);
        } catch (Throwable $exception) {

            return $this->failure($exception, $errors);
        }
    }

    public function run(Closure $bootstrap): int
    {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');

        $response = $this->handle($bootstrap);

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "This PHP runtime is not supported.\n");

            return 1;
        }

        $response->send();

        return 0;
    }

    private function failure(Throwable $exception, ErrorResponder|null $errors = null): Response
    {
        $method = ($_SERVER['REQUEST_METHOD'] ?? '') === HttpMethod::HEAD->value ? HttpMethod::HEAD->value : HttpMethod::GET->value;
        $request = new Request(server: ['REQUEST_METHOD' => $method], content: '');

        return ($errors ?? new ErrorResponder(false, $this->logger))->respond($exception)->prepare($request);
    }
}
