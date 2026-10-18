<?php

declare(strict_types=1);

namespace Arix\Routing;

use InvalidArgumentException;
use LogicException;

use function is_array;
use function is_string;

class RouteDefinition
{
    public string|null $name = null;

    /** @var list<class-string> */
    public array $middleware = [];

    private bool $locked = false;

    /** @param list<string> $methods */
    public function __construct(
        public readonly array $methods,
        public readonly string $path,
        public readonly string $controller,
        public readonly string $action,
    ) {}

    public function name(string $name): static
    {
        if ($this->locked) {
            throw new LogicException('Routes cannot be renamed after compilation.');
        }

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $name) || str_starts_with($name, '_arix_')) {
            throw new InvalidArgumentException('Invalid route name.');
        }

        $this->name = $name;

        return $this;
    }

    public function middleware(string|array $middleware): static
    {
        if ($this->locked) {
            throw new LogicException('Middleware cannot be assigned after route compilation.');
        }

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

        return $this;
    }

    public function lock(): void
    {
        $this->locked = true;
    }
}
