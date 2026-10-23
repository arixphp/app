<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'ArixPHP'),
    'url' => env('APP_URL', 'http://127.0.0.1:8181'),
    'debug' => env('APP_DEBUG', false),
    'locales' => ['es', 'en'],
    'default_locale' => 'en',
    'max_body_bytes' => 1048576,
];
