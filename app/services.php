<?php

declare(strict_types=1);

use App\Controllers\AboutController;
use App\Controllers\WelcomeController;
use Arix\Container\Container;

return static function (Container $container): void {
    $container->bind(WelcomeController::class, static fn (): WelcomeController => new WelcomeController());
    $container->bind(AboutController::class, static fn (): AboutController => new AboutController());
};
