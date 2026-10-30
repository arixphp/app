<?php

declare(strict_types=1);

use Arix\Autoload\AutoloadConfiguration;
use Arix\Autoload\ClassLoader;

if (PHP_VERSION_ID < 80401) {
    throw new RuntimeException('Arix core requires PHP >= 8.4.1.');
}

if (! extension_loaded('mbstring')) {
    throw new RuntimeException('Arix core requires ext-mbstring.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'Autoload' . DIRECTORY_SEPARATOR . 'AutoloadConfiguration.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'Autoload' . DIRECTORY_SEPARATOR . 'ClassLoader.php';

$classLoader = new ClassLoader(AutoloadConfiguration::read(dirname(__DIR__)));
$classLoader->register();

return $classLoader;
