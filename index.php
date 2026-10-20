<?php

declare(strict_types=1);

use Arix\Http\Kernel;
use Arix\Runtime\HttpRuntime;

$debug = match (strtolower(trim((string) getenv('APP_DEBUG')))) {
    'true', '1' => true,
    default => false,
};

require_once __DIR__ . '/core/autoload.php';

$status = new HttpRuntime($debug)
    ->run(static fn (): Kernel => require __DIR__ . '/app/bootstrap.php');

exit($status);
