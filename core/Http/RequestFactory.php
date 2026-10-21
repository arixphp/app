<?php

declare(strict_types=1);

namespace Arix\Http;

use Arix\Constants\HttpStatusCode;
use Arix\HttpFoundation\Request;
use InvalidArgumentException;
use RuntimeException;

use function ctype_digit;
use function is_resource;
use function is_string;
use function strlen;

class RequestFactory
{
    public function __construct(public readonly int $maxBodyBytes = 1048576)
    {
        if ($maxBodyBytes < 1 || $maxBodyBytes === PHP_INT_MAX) {
            throw new InvalidArgumentException('Body limit must be a positive bounded integer.');
        }
    }

    public function fromGlobals(): Request
    {
        $this->assertContentLength();

        $input = fopen('php://input', 'rb');

        if ($input === false) {
            throw new RuntimeException('Cannot open request body.');
        }

        try {
            return $this->create($_SERVER, $_GET, $_POST, $_COOKIE, $_FILES, $input);
        } finally {
            fclose($input);
        }
    }

    /**
     * Refuse an announced body before it is read.
     *
     * The body of a request is bounded before PHP parses it, so an oversized
     * request is rejected without being materialized.
     */
    private function assertContentLength(): void
    {
        $length = $_SERVER['CONTENT_LENGTH'] ?? null;

        if (! is_string($length) || $length === '' || ! ctype_digit($length)) {
            return;
        }

        if ((int) $length > $this->maxBodyBytes) {
            throw new HttpException(HttpStatusCode::CONTENT_TOO_LARGE, 'Request body is too large.');
        }
    }

    /**
     * @param  string|resource  $content  Raw bytes or a readable stream positioned at the beginning.
     */
    public function create(array $server, array $query = [], array $post = [], array $cookies = [], array $files = [], mixed $content = ''): Request
    {
        $body = $this->readBody($content);

        // HttpFoundation accepts the override header by default; Arix does not.
        Request::setAllowedHttpMethodOverride([]);

        return new Request($query, $post, [], $cookies, $files, $server, $body);
    }

    public function capture(Request $request): void
    {
        $body = $this->readBody($request->getContent(true));
        $headers = $request->headers->all();
        $format = $request->getRequestFormat(null);
        $request->initialize(
            $request->query->all(),
            $request->request->all(),
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),
            $request->server->all(),
            $body,
        );
        $request->headers->replace($headers);
        $request->setRequestFormat($format);
    }

    private function readBody(mixed $content): string
    {
        if (is_resource($content) && get_resource_type($content) === 'stream') {
            $body = stream_get_contents($content, $this->maxBodyBytes + 1);

            if ($body === false) {
                throw new RuntimeException('Cannot read request body.');
            }
        } elseif (is_string($content)) {
            $body = $content;
        } else {
            throw new InvalidArgumentException('Request body must be a string or readable stream.');
        }

        if (strlen($body) > $this->maxBodyBytes) {
            throw new HttpException(HttpStatusCode::CONTENT_TOO_LARGE, 'Request body is too large.');
        }

        return $body;
    }
}
