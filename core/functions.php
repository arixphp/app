<?php

declare(strict_types=1);

use Arix\Config\Repository;
use Arix\Container\ContainerContext;
use Arix\Localization\Translator;
use Arix\Routing\UrlGenerator;
use Arix\Views\Contracts\View;
use Arix\Views\ViewFactory;

use function array_key_exists as arrayKeyExists;

/*
 * trigger_deprecation() is copied from symfony/deprecation-contracts.
 *
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

if (! function_exists('trigger_deprecation')) {
    function trigger_deprecation(string $package, string $version, string $message, mixed ...$args): void
    {
        @trigger_error(($package || $version ? "Since $package $version: " : '') . ($args ? vsprintf($message, $args) : $message), \E_USER_DEPRECATED);
    }
}

if (! function_exists('view')) {
    function view(string $name, array $data = []): View
    {
        return ContainerContext::current()->get(ViewFactory::class)->make($name, $data);
    }
}

if (! function_exists('trans')) {
    function trans(string $key, array $replace = []): string
    {
        return ContainerContext::current()->get(Translator::class)->get($key, $replace);
    }
}

if (! function_exists('url')) {
    function url(null|string $path = null): UrlGenerator|string
    {
        $generator = ContainerContext::current()->get(UrlGenerator::class);

        return $path === null ? $generator : $generator->to($path);
    }
}

if (! function_exists('config')) {
    function config(string|null $key = null, mixed $default = null): mixed
    {

        return ContainerContext::current()->get(Repository::class)->get($key, $default);
    }
}

if (! function_exists('asset')) {
    function asset(string $path): string
    {
        return ContainerContext::current()->get(UrlGenerator::class)->asset($path);
    }
}

if (! function_exists('env')) {
    function env(string $key, array|string|float|int|bool|Closure|null $default = null): array|string|float|int|bool|null
    {
        if (arrayKeyExists($key, $_ENV)) {
            $value = $_ENV[$key];
        } else {
            $value = getenv($key);

            if ($value === false) {
                return $default instanceof Closure ? $default() : $default;
            }
        }

        return match ($value) {
            'true' => true,
            'false' => false,
            default => $value,
        };
    }
}
