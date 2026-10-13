<?php

declare(strict_types=1);

namespace Arix\Testing;

use Arix\Constants\HttpStatusCode;
use Arix\HttpFoundation\Response;
use PHPUnit\Framework\Assert;

class TestResponse
{
    public readonly string $content;

    public function __construct(public readonly Response $response)
    {
        $this->content = (string) $response->getContent();
    }

    public function assertStatus(HttpStatusCode|int $status): static
    {
        $expected = $status instanceof HttpStatusCode ? $status->value : HttpStatusCode::tryFrom($status)?->value ?? $status;

        Assert::assertSame($expected, $this->response->getStatusCode());

        return $this;
    }

    public function assertOk(): static
    {
        return $this->assertStatus(HttpStatusCode::OK);
    }

    /** @param string|list<string> $needles */
    public function assertSee(string|array $needles): static
    {
        foreach ((array) $needles as $needle) {
            Assert::assertStringContainsString($needle, $this->content);
        }

        return $this;
    }
}
