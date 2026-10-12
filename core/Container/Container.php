<?php

declare(strict_types=1);

namespace Arix\Container;

use Closure;
use InvalidArgumentException;

use function in_array;
use function is_object;

class Container
{
    private array $bindings = [];

    private array $instances = [];

    private array $resolving = [];

    public function bind(string $id, Closure $factory): void
    {
        $this->register($id, $factory, false);
    }

    public function singleton(string $id, Closure $factory): void
    {
        $this->register($id, $factory, true);
    }

    public function instance(string $id, object $instance): void
    {
        $this->validateRegistration($id);
        unset($this->bindings[$id]);
        $this->instances[$id] = $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->bindings[$id]);
    }

    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (! isset($this->bindings[$id])) {
            throw new UnknownServiceException("Service is not registered: {$id}");
        }
        if (in_array($id, $this->resolving, true)) {
            throw new CircularServiceException('Circular dependency: '.implode(' -> ', [...$this->resolving, $id]));
        }
        $this->resolving[] = $id;
        try {
            $binding = $this->bindings[$id];
            $instance = ($binding['factory'])($this);
            if (! is_object($instance)) {
                throw new InvalidServiceException("Service factories must return objects: {$id}");
            }
            if ($binding['shared']) {
                $this->instances[$id] = $instance;
            }

            return $instance;
        } finally {
            array_pop($this->resolving);
        }
    }

    private function register(string $id, Closure $factory, bool $shared): void
    {
        $this->validateRegistration($id);
        unset($this->instances[$id]);
        $this->bindings[$id] = ['factory' => $factory, 'shared' => $shared];
    }

    private function validateRegistration(string $id): void
    {
        if ($id === '') {
            throw new InvalidArgumentException('Service identifiers cannot be empty.');
        }
        if (in_array($id, $this->resolving, true)) {
            throw new ContainerRuntimeException("Cannot replace a service while resolving it: {$id}");
        }
    }
}
