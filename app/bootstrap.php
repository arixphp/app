<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/autoload.php';

Arix\Autoloader::registerControllers(__DIR__ . '/controllers');

return new Arix\Application(dirname(__DIR__));
