<?php

declare(strict_types=1);

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return Arix\Container\ContainerContext::current()->get(Arix\Support\ApplicationPaths::class)->base($path);
    }
}

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

if (!function_exists('url')) {
    function url(?string $path = null): Arix\Routing\UrlGenerator|string
    {
        $generator = Arix\Container\ContainerContext::current()->get(Arix\Routing\UrlGenerator::class);

        return $path === null ? $generator : $generator->to($path);
    }
}

if (!function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        return Arix\Container\ContainerContext::current()->get(Arix\Config\Repository::class)->get($key, $default);
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        return Arix\Container\ContainerContext::current()->get(Arix\Routing\UrlGenerator::class)->asset($path);
    }
}
