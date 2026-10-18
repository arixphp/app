<?php

declare(strict_types=1);

use App\Middleware\SetLocale;
use Arix\Application;
use Arix\Http\MiddlewareConfiguration;

require_once dirname(__DIR__) . '/core/autoload.php';

return Application::configure(dirname(__DIR__))
    ->withMiddleware(static function (MiddlewareConfiguration $middleware): void {
        $middleware->append(SetLocale::class);
    })
    ->create();
