<?php

declare(strict_types=1);

namespace Arix;

use Arix\Http\Kernel;
use Arix\Http\MiddlewareConfiguration;
use Closure;

class ApplicationBuilder
{
    private MiddlewareConfiguration $middleware;

    public function __construct(private readonly string $basePath)
    {
        $this->middleware = new MiddlewareConfiguration();
    }

    public function withMiddleware(Closure $configure): static
    {
        $configure($this->middleware);

        return $this;
    }

    public function create(): Kernel
    {
        return Application::create($this->basePath)->kernel($this->middleware->all());
    }
}
