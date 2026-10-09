<?php

declare(strict_types=1);

if (!function_exists('view')) {
    function view(string $name, array $data = []): Arix\Views\Contracts\View
    {
        return Arix\Container\ContainerContext::current()->get(Arix\Views\ViewFactory::class)->make($name, $data);
    }
}

if (!function_exists('trans')) {
    function trans(string $key, array $replace = []): string
    {
        return Arix\Container\ContainerContext::current()->get(Arix\Localization\Translator::class)->get($key, $replace);
    }
}
