<?php

declare(strict_types=1);

namespace Arix\Testing\Concerns;

use Arix\Testing\Assert;

trait InteractWithHtml
{
    public function assertSee(string $needle, string $message = ''): static
    {
        Assert::assertStringContainsString($needle, $this->response->body, $message !== '' ? $message : "Response does not contain '{$needle}'.");

        return $this;
    }

    public function assertDontSee(string $needle, string $message = ''): static
    {
        Assert::assertStringNotContainsString($needle, $this->response->body, $message !== '' ? $message : "Response contains unexpected '{$needle}'.");

        return $this;
    }

    public function assertSeeEscaped(string $raw): static
    {
        $escaped = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $this->assertSee($escaped, 'Response does not contain the escaped value.');
        $this->assertDontSee($raw, 'Response contains the unescaped value.');

        return $this;
    }
}
