<?php

declare(strict_types=1);

return [
    'timezone' => 'UTC',
    'grace' => 3600,
    // The scheduler log is framework-owned and always lives in
    // storage/logs/cron.log inside the application root. It is not configurable.
];
