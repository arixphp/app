<?php

declare(strict_types=1);

use Arix\Runtime\ConsoleRuntime;

require_once __DIR__ . '/autoload.php';

$status = new ConsoleRuntime()->run(dirname(__DIR__));

exit($status);
