<?php

declare(strict_types=1);

namespace Arix\Testing\Concerns;

use Arix\Testing\Assert;

trait InteractWithHeaders
{
    public function assertHeader(string $name, string $value): static
    {
        $actual = $this->header($name);
        Assert::assertNotNull($actual, "Response is missing expected header: {$name}.");
        Assert::assertSame($value, $actual, "Response header {$name} is '{$actual}', expected '{$value}'.");

        return $this;
    }

    public function assertHasHeader(string $name): static
    {
        Assert::assertNotNull($this->header($name), "Response is missing expected header: {$name}.");

        return $this;
    }

    public function assertHeaderMissing(string $name): static
    {
        Assert::assertNull($this->header($name), "Response has unexpected header: {$name}.");

        return $this;
    }
}
