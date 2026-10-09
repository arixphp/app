<?php

declare(strict_types=1);

return [
    'debug' => Arix\Support\Environment::boolean('APP_DEBUG'),
    // Empty for the domain root; e.g. '/website' for a subdirectory installation.
    'base_path' => '',
    'locales' => ['es', 'en'],
    'default_locale' => 'es',
    'name' => 'ArixPHP',
];
