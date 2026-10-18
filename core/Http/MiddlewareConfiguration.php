<?php

declare(strict_types=1);

namespace Arix\Http;

use InvalidArgumentException;

use function is_array;
use function is_string;

class MiddlewareConfiguration
{
    /** @var list<class-string> */
    private array $middleware = [];

    public function append(string|array $middleware): void
    {
        $items = is_array($middleware) ? $middleware : [$middleware];

        if ($items === [] || ! array_is_list($items)) {
            throw new InvalidArgumentException('Middleware must be a nonempty ordered class-string list.');
        }

        foreach ($items as $item) {
            if (! is_string($item) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $item)) {
                throw new InvalidArgumentException('Middleware must be a valid class string.');
            }

            $this->middleware[] = $item;
        }
    }

    /** @return list<class-string> */
    public function all(): array
    {
        return $this->middleware;
    }
}
