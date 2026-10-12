<?php

declare(strict_types=1);

return [
    'debug' => match (strtolower(trim((string) env('APP_DEBUG', '')))) {
        'true', '1' => true,
        default => false,
    },
    'base_path' => '',
    'locales' => ['es', 'en'],
    'default_locale' => 'en',
    'name' => 'ArixPHP',
    'max_body_bytes' => 1048576,
];
