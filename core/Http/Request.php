<?php

declare(strict_types=1);

namespace Arix\Http;

use Arix\Constants\HttpMethod;
use InvalidArgumentException;
use RuntimeException;

use function strlen;

class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array $headers = [],
        public readonly array $query = [],
        private readonly string $body = '',
    ) {
    }

    public static function fromGlobals(int $maxBodyBytes = 1048576): self
    {
        if ($maxBodyBytes < 1 || $maxBodyBytes === PHP_INT_MAX) {
            throw new InvalidArgumentException('Body limit must be a positive bounded integer.');
        }
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = (string) $value;
            }
        }

        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($_SERVER[$key])) {
                $headers[$name] = (string) $_SERVER[$key];
            }
        }
        $body = file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);
        if ($body === false) {
            throw new RuntimeException('Cannot read request body.');
        }
        if (strlen($body) > $maxBodyBytes) {
            throw new HttpException(413, 'Request body is too large.');
        }

        return new self($_SERVER['REQUEST_METHOD'] ?? HttpMethod::GET->value, $_SERVER['REQUEST_URI'] ?? '/', $headers, $_GET, $body);
    }

    public function body(): string
    {
        return $this->body;
    }

    public function httpMethod(): ?HttpMethod
    {
        return HttpMethod::tryFrom($this->method);
    }

    public function header(string $name): string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return (string) $value;
            }
        }

        return '';
    }

    public function path(string $basePath = ''): string
    {
        $raw = explode('?', $this->uri, 2)[0];
        if (!str_starts_with($raw, '/') || preg_match('/%(?![a-f0-9]{2})|%(?:2f|5c)/i', $raw)) {
            throw new HttpException(400, 'Invalid path.');
        }

        $path = rawurldecode($raw);
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $path) || !preg_match('//u', $path)) {
            throw new HttpException(400, 'Invalid path.');
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                throw new HttpException(400, 'Invalid path.');
            }
        }

        if (str_contains($path, '//')) {
            throw new HttpException(400, 'Ambiguous path.');
        }

        if ($basePath !== '') {
            if ($path !== $basePath && !str_starts_with($path, $basePath . '/')) {
                throw new HttpException(404, 'Page not found.');
            }
            $path = substr($path, strlen($basePath));
        }

        return $path === '' ? '/' : $path;
    }
}
