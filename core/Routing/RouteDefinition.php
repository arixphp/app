<?php

declare(strict_types=1);

namespace Arix\Routing;

use InvalidArgumentException;

class RouteDefinition
{
    private CachePolicy $policy;

    /** @param list<string> $methods @param class-string $controller */
    public function __construct(
        public readonly array $methods,
        public readonly string $path,
        public readonly string $controller,
        public readonly string $action,
    ) {
        $this->policy = new CachePolicy();
    }

    /** @param list<string> $query */
    public function cache(int $ttl, array $query = []): self
    {
        if ($this->methods !== ['GET']) {
            throw new InvalidArgumentException('HTML cache can only be configured on a GET route.');
        }
        $this->policy = new CachePolicy($ttl, $query);

        return $this;
    }

    public function cachePolicy(): CachePolicy
    {
        return $this->policy;
    }
}
