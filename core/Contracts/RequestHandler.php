<?php

declare(strict_types=1);

namespace Arix\Contracts;

use Arix\Container\Container;
use Arix\HttpFoundation\Request;
use Arix\HttpFoundation\Response;

interface RequestHandler
{
    public function handle(Request $request, Container $container): Response;
}
