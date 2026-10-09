<?php

declare(strict_types=1);

namespace Arix\Http;

use Arix\Constants\HttpMethod;

final class Response
{
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {
    }

    public function send(HttpMethod|string $method = HttpMethod::GET): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        $method = is_string($method) ? HttpMethod::tryFrom($method) : $method;
        if ($method !== HttpMethod::HEAD && !in_array($this->status, [204, 304], true)) {
            echo $this->body;
        }
    }
}
