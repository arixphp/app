<?php

declare(strict_types=1);

namespace Arix\Http\Client;

use Arix\Constants\HttpStatusCode;
use JsonException;

readonly class Response
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        private int $status,
        private array $headers,
        private string $body,
    ) {}

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, list<string>> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): null|string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    public function json(bool $associative = true): mixed
    {
        try {
            return json_decode($this->body, $associative, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HttpClientException('The response body is not valid JSON.', previous: $exception);
        }
    }

    public function successful(): bool
    {
        return $this->status >= HttpStatusCode::OK->value && $this->status < HttpStatusCode::MULTIPLE_CHOICES->value;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    public function clientError(): bool
    {
        return $this->status >= HttpStatusCode::BAD_REQUEST->value && $this->status < HttpStatusCode::INTERNAL_SERVER_ERROR->value;
    }

    public function serverError(): bool
    {
        return $this->status >= HttpStatusCode::INTERNAL_SERVER_ERROR->value;
    }

    public function throw(): self
    {
        if ($this->failed()) {
            throw new HttpClientException("HTTP request failed with status {$this->status}", $this->status);
        }

        return $this;
    }
}
