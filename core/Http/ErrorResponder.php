<?php

declare(strict_types=1);

namespace Arix\Http;

use Arix\Constants\HttpStatusCode;
use Arix\HttpFoundation\Exception\RequestExceptionInterface;
use Arix\HttpFoundation\Response;
use Closure;
use Throwable;

class ErrorResponder
{
    public function __construct(
        private readonly bool $debug = false,
        private readonly null|Closure $logger = null
    ) {}

    /** @param Closure(int, string): (string|null)|null $renderView */
    public function respond(Throwable $exception, Closure|null $renderView = null): Response
    {
        $headers = [];
        $diagnostics = false;

        if ($exception instanceof HttpException) {
            $status = $exception->status;
            $message = $exception->getMessage();
            $headers = $exception->headers;
        } elseif ($exception instanceof RequestExceptionInterface) {
            $status = HttpStatusCode::BAD_REQUEST;
            $message = 'Bad request.';
        } else {
            $status = HttpStatusCode::INTERNAL_SERVER_ERROR;
            $diagnostics = $this->debug;
            $message = $this->debug ? (string) $exception : 'Internal server error.';
            $log = "Arix: {$exception}";

            try {
                if ($this->logger !== null) {
                    ($this->logger)($log);
                } else {
                    error_log($log);
                }
            } catch (Throwable $loggingException) {
                try {
                    @error_log("Arix logger failed: {$loggingException}\n{$log}");
                } catch (Throwable) {
                    // Logging is best-effort and must never prevent an HTTP error response.
                }
            }
        }

        $html = null;

        if (! $diagnostics) {
            $level = ob_get_level();

            try {
                if ($renderView !== null) {
                    // Discard incidental output, including flushed template buffers.
                    ob_start(static fn (string $output): string => '');
                    $html = $renderView($status->value, $message);
                }
            } catch (Throwable) {
                // A failed application view must never replace the original HTTP error.
            } finally {
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
            }

            $html ??= new ErrorPageRenderer()->render($status->value, $message);
        }

        $response = new Response($html ?? $message, $status->value, $headers);
        $response->headers->set('Content-Type', $html === null ? 'text/plain; charset=UTF-8' : 'text/html; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
