<?php

declare(strict_types=1);

namespace Arix\Testing\Concerns;

use Arix\Support\Arr;
use Arix\Testing\Assert;

use function is_array;

trait InteractWithJson
{
    /** @return array<mixed> */
    public function decodedBody(): array
    {
        $decoded = json_decode($this->response->body, true);
        Assert::assertTrue(is_array($decoded), 'Response body is not valid JSON.');

        return $decoded;
    }

    public function assertJsonPath(string $path, mixed $expected): static
    {
        $decoded = $this->decodedBody();
        Assert::assertTrue(Arr::has($decoded, $path), "Path '{$path}' does not exist in the JSON response.");
        Assert::assertEquals($expected, Arr::get($decoded, $path), "JSON path '{$path}' does not match the expected value.");

        return $this;
    }
}
