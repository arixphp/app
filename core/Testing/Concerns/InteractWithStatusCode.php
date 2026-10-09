<?php

declare(strict_types=1);

namespace Arix\Testing\Concerns;

use Arix\Constants\HttpStatusCode;
use Arix\Testing\Assert;

use function in_array;
use function is_int;

trait InteractWithStatusCode
{
    public function assertStatus(HttpStatusCode|int $expected): static
    {
        if (is_int($expected)) {
            $expected = HttpStatusCode::from($expected);
        }

        Assert::assertSame(
            $expected->value,
            $this->response->status,
            "Expected status {$expected->value}, got {$this->response->status}."
        );

        return $this;
    }

    public function assertOk(): static
    {
        return $this->assertStatus(HttpStatusCode::OK);
    }

    public function assertNotFound(): static
    {
        return $this->assertStatus(HttpStatusCode::NOT_FOUND);
    }

    public function assertRedirect(HttpStatusCode|int|null $status = null): static
    {
        Assert::assertTrue(
            in_array($this->response->status, [
                HttpStatusCode::REDIRECT_MOVED_PERMANENTLY->value,
                HttpStatusCode::REDIRECT_FOUND->value,
                HttpStatusCode::REDIRECT_SEE_OTHER->value,
                HttpStatusCode::REDIRECT_TEMPORARY->value,
                HttpStatusCode::REDIRECT_PERMANENT->value,
            ], true),
            "Expected a redirect status, got {$this->response->status}.",
        );

        if ($status !== null) {
            $this->assertStatus($status instanceof HttpStatusCode ? $status->value : $status);
        }

        return $this;
    }
}
