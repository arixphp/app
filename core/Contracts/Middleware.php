<?php

declare(strict_types=1);

namespace Arix\Contracts;

use Arix\HttpFoundation\Request;
use Arix\HttpFoundation\Response;
use Closure;

interface Middleware
{
    public function handle(Request $request, Closure $next): Response;
}
