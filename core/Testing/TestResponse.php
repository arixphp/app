<?php

declare(strict_types=1);

namespace Arix\Testing;

use Arix\Http\Response;
use Arix\Testing\Concerns\InteractWithHeaders;
use Arix\Testing\Concerns\InteractWithHtml;
use Arix\Testing\Concerns\InteractWithJson;
use Arix\Testing\Concerns\InteractWithStatusCode;

class TestResponse
{
    use InteractWithStatusCode;
    use InteractWithHeaders;
    use InteractWithHtml;
    use InteractWithJson;

    public function __construct(public readonly Response $response)
    {
    }

    public function status(): int
    {
        return $this->response->status;
    }

    public function body(): string
    {
        return $this->response->body;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->response->headers;
    }

    public function header(string $name): ?string
    {
        foreach ($this->response->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return (string) $value;
            }
        }

        return null;
    }
}
