<?php

declare(strict_types=1);

use Arix\Http\Kernel;
use Arix\Runtime\HttpRuntime;

require_once __DIR__ . '/core/autoload.php';

$bootstrap = static fn (): Kernel => require __DIR__ . '/app/bootstrap.php';

$status = new HttpRuntime(applicationRoot: __DIR__)->run($bootstrap);

exit($status);
