<?php

declare(strict_types=1);

// Backwards-compatible local test command; production does not need tests/.
return require dirname(__DIR__) . '/core/Development/router.php';
