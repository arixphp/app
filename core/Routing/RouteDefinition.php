<?php

declare(strict_types=1);

namespace Arix\Routing;

use InvalidArgumentException;
use LogicException;

class RouteDefinition
{
    public string|null $name = null;

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

    public function lock(): void
    {
        $this->locked = true;
    }
}
