<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/autoload.php';

Arix\Autoloader::registerPages(__DIR__ . '/pages');

return new Arix\Application(dirname(__DIR__));
