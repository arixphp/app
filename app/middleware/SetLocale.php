<?php

declare(strict_types=1);

namespace App\Middleware;

use Arix\Config\Repository;
use Arix\Container\ContainerContext;
use Arix\Contracts\Middleware;
use Arix\HttpFoundation\Request;
use Arix\HttpFoundation\Response;
use Closure;

use function str_replace;
use function str_starts_with;

class SetLocale implements Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $config = ContainerContext::current()->get(Repository::class);
        $locales = $config->get('app.locales');
        $preferred = $request->getPreferredLanguage($locales);

        foreach ($request->getLanguages() as $language) {
            foreach ($locales as $locale) {
                $supported = str_replace('-', '_', $locale);

                if (str_starts_with($supported, $language) || str_starts_with($language, $supported)) {
                    $request->setLocale($preferred);

                    return $next($request);
                }
            }
        }

        $request->setLocale($config->get('app.default_locale'));

        return $next($request);
    }
}
