<?php

declare(strict_types=1);

return [
    'debug' => Arix\Support\Environment::boolean('APP_DEBUG'),
    'base_path' => '',
    'locales' => ['es', 'en'],
    'default_locale' => 'en',
    'name' => 'ArixPHP',
    'max_body_bytes' => 1048576,
];
