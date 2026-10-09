# ArixPHP

ArixPHP lets you publish simple, multilingual sites on traditional PHP hosting,
without SSH, Composer, Node, or a frontend build. It is inspired by Astro's page
composition and Inertia's separation of pages, data, and presentation;
it does not implement their compilers or an SPA protocol.

It does not use sessions or provide database support. Pages support
**GET and HEAD**; other methods receive `405` with `Allow: GET, HEAD`.

## Requirements

- PHP 8.2+, tokenizer, JSON, and PCRE (standard extensions).
- Apache with mod_rewrite, mod_authz_core, and appropriate AllowOverride settings,
  or IIS with PHP and URL Rewrite. Other servers require equivalent rules.
- A private, writable cache for `.arix.php` views. Native `.php` views
  work without a cache. The eval fallback is disabled by default.

## Structure

```text
index.php                 Public front controller
app/
    bootstrap.php         Small, stable bootstrap owned by the application
    config/               Application and cache configuration
    lang/                 Translations
    pages/                App\Pages\ classes
    views/                Page views, layouts, partials, and components
core/                     Replaceable framework, Arix\ namespace
    autoload.php          Framework autoloading and helpers
    functions.php         view(), trans(), url(), asset(), and config() helpers
    Config/               Read-only configuration repository
    Container/            Explicit services and request scope
    Version.php           Version included in cache keys
styles/                   Public CSS, no build
scripts/                  Public JavaScript, no build
assets/                   Public images and video
    fonts/                Public fonts; prefer WOFF2
tests/                    Local tests
```

`app/` and `core/` are not accessible over HTTP. The server serves `styles/`,
`scripts/`, and `assets/` directly: there is no PHP asset endpoint. There is no
`app/views/pages/` or `app/src/`. Configuration remains in `app/config/`.

## Debugging via an Environment Variable

Debugging is disabled by default. Only `APP_DEBUG=true` or `APP_DEBUG=1`
enable it (case-insensitive and allowing surrounding whitespace). Missing,
empty, false, 0, or any invalid value leaves it disabled. Do not cast
strings to booleans: `"false"` is truthy in PHP. No `.env` files are loaded.

```sh
APP_DEBUG=true php tests/run.php
APP_DEBUG=true php -S 127.0.0.1:8080 core/Development/router.php
```

`app/config/app.php` reads the process variable using Environment::boolean().
Explicit Application overrides take precedence over that configuration
for testing. The application displays escaped exceptions in debug mode; otherwise,
it returns a generic error. The bootstrap uses the same environment conversion even
if autoloading fails; it returns plain text, preserves HEAD behavior, and does not cache errors.
Neither HTTP headers nor parameters can enable debugging. Errors are always
recorded in the PHP log.

The front controller sets display_errors=1 in debug mode or 0 otherwise;
log_errors remains enabled. In debug mode it sets E_ALL. Debugging is not
automatically enabled on a local server. For native errors that occur before
the application's PHP code runs, configure PHP from startup:

```sh
APP_DEBUG=true php -d display_errors=1 -d log_errors=1 -d error_reporting=E_ALL \
  -S 127.0.0.1:8080 core/Development/router.php
```

Do not use debugging in production:
it may expose paths, code, and internal messages. Also ensure display_errors=Off
in the hosting server's PHP configuration for failures before the bootstrap.

## Pages and Routes

`app/pages/Index.php`:

```php
<?php

declare(strict_types=1);

namespace App\Pages;

use Arix\Pages\Attributes\Get;
use Arix\Pages\Contracts\Page;
use Arix\Views\Contracts\View;

#[Get('/')]
final class Index implements Page
{
    public function render(): View
    {
        return view('index', ['title' => 'Example', 'description' => '']);
    }
}
```

The attribute determines the URL, not the filename. `#[Get('/')]` responds
to `/es` and `/en`; `#[Get('/about')]` responds to `/es/about` and `/en/about`.
The root `/` negotiates Accept-Language and temporarily redirects to a supported language.
An explicit language in the URL never changes based on the browser's language.

The route must start with `/`, have no trailing slash except for `/`, and contain no
query string, fragment, or traversal. Duplicates are rejected. HEAD uses the same page and headers
as GET without emitting a body. Dynamic routes are not supported in this version.

The filename must match the class exactly for autoloading, including on
case-sensitive systems: `App\Pages\Blog\Article` maps to
`app/pages/Blog/Article.php`. Avoid classes whose names differ only in case.

The application discovers classes, inspects attributes, and caches a route
registry. During cold discovery it loads declarations from all page PHP files,
**but does not instantiate every page**. Those files must therefore
contain declarations without output or side effects. Abstract classes
or classes that do not implement `Page` do not publish routes. The registry is invalidated when
files or the core version change, without mandatory commands in production.

To access the context, the constructor can receive a single `PageContext`:

```php
public function __construct(private readonly \Arix\Routing\PageContext $context)
{
}
```

It provides `request`, `translator`, `path`, `url()`, and `asset()`. Page constructors
follow this contract; arbitrary dependencies are not autowired.

## Container and Helpers

Each request has a small container with explicit registrations. `bind()` creates
objects on each resolution, `singleton()` shares an object within the container,
`instance()` registers an existing object, and `get()` resolves services. Missing
services, factories that do not return objects, and cycles produce explicit errors.
Replacing a registration invalidates its previous instance; a service cannot be replaced
while it is being resolved. Classes are not instantiated automatically.

The application registers Request, Translator, Config\Repository, PageContext and
Routing\UrlGenerator (the latter two when resolving a page), FileCache,
PageRouter, TemplateEngine, and ViewFactory. The translator and rendering engine
belong to the request; only FileCache is shared across requests
within the same Application. `view()` resolves ViewFactory and creates a new definition
with a name and data, without sharing properties between views. `trans()` resolves the
translator for the URL's language and preserves replacements and fallback behavior:

```php
return view('index', ['title' => trans('home.title')]);

trans('welcome', ['name' => 'Omar']);
```

In templates, use `{{ trans('home.title') }}` to escape HTML. `trans()` does not
escape text itself, because it returns text that can also be used outside HTML.

`config()` queries a read-only repository using dot notation:

```php
config('app.locale');                 // Effective language from the URL
config('app.locales');                // Supported languages
config('app.default_locale');         // Translation and negotiation fallback
config('app.name');
config('app.missing', 'Default');      // Value for missing keys
config('cache.allow_eval', false);
config();                            // Full configuration array
```

Configuration includes Application overrides. `app.locale` is an effective
per-request value: before language resolution it uses `app.default_locale`, then
the explicit language from the URL. It does not modify the file or shared configuration.
An existing key with a `null` value returns `null`, not the default value.
Writes through `config()` are not supported.

Helpers require an active container during the request. To use them in
tests or trusted scripts, register services and activate an explicit scope:

```php
$container = new \Arix\Container\Container();
$container->singleton(\Arix\Views\ViewFactory::class, static fn () => new \Arix\Views\ViewFactory());
$container->instance(\Arix\Localization\Translator::class, $translator);
$container->instance(\Arix\Config\Repository::class, new \Arix\Config\Repository([
    'app' => ['locale' => $translator->locale, 'locales' => $translator->locales],
]));
$container->instance(\Arix\Routing\UrlGenerator::class, new \Arix\Routing\UrlGenerator(
    '/site', $translator->locale, $translator->locales, '/about',
));

\Arix\Container\ContainerContext::run($container, static function () {
    return view('index', ['title' => trans('home.title')]);
});
```

Outside a scope, helpers throw LogicException; there is no silent global
initialization. `ContainerContext::run()` restores the previous context using
finally, including on errors, redirects, and cache hits. It supports synchronous nested
scopes; it is not a context for concurrent tasks using fibers or coroutines.
Within a scope, an unregistered service fails explicitly. Rendering directly with
TemplateEngine, even when passing PageContext, does not activate a container:
if the template uses helpers, register their services and activate the scope explicitly.

`Request::httpMethod()` uses the `Arix\Constants\HttpMethod` enum and returns null
for unknown methods, preserving the original string in `Request::$method`.
The application only allows GET/HEAD and responds with 405 for other methods. `Response::send()`
accepts the enum or a string, and HEAD omits the body in both cases.

## Templates

`view('index')` resolves `app/views/index.arix.php` or `app/views/index.php`, but
not both. Layouts and partials use dot notation: `layouts.main`, `partials.navigation`.

```php
@extends('layouts.main')

@section('content')
    <h1>{{ $title }}</h1>
    @include('partials.navigation')
@endsection
```

| Syntax | Usage |
| --- | --- |
| `{{ $value }}` | HTML escaping |
| `{!! $html !!}` | Unescaped trusted HTML |
| `@extends('layouts.main')` | Layout |
| `@section('content') … @endsection` | Captured section |
| `@section('title', $title)` | Escaped inline section |
| `@yield('content', 'Default')` | Insert a section |
| `@include('partials.nav', ['items' => $items])` | Partial |
| `@if(...)`, `@elseif(...)`, `@else`, `@endif` | Conditionals |
| `@foreach(...) … @endforeach` | Iteration |
| `@for(...) … @endfor`, `@while(...) … @endwhile` | Loops |
| `@component('components.card', ['heading' => $title]) … @endcomponent` | Component |
| `@slot('heading') … @endslot` | Named slot |
| `@json($value)` | JSON with encoded HTML characters |
| `{{-- comment --}}` | Removed comment |
| `@@include` | Literal `@include` text |

Slots contain generated HTML: they are inserted using `{!! $slot !!}`. Escape
visitor data when building that content. In components, named slots
take precedence over props with the same name; the default slot occupies `$slot`.
Layouts and includes inherit the view's data, with their explicit data taking precedence;
components receive only their props and slots, along with the request context.

Native PHP views use `$this->extend()`, `$this->startSection()`,
`$this->endSection()`, `$this->yieldSection()`, and `$this->escape()` for composition.
They use the same `trans()`, `url()`, `asset()`, and `config()` helpers as `.arix.php` views.

**Migration from 0.2:** `$view` is no longer injected. Replace its presentation
calls with helpers and its native PHP composition calls with `$this`.
`view`, `data`, `path`, `fallback`, and `allowEval` are now valid data names.
Data keys must be valid PHP variable names; numeric keys, names with the
internal `__arix` prefix, and PHP's special names
`this` and `GLOBALS` are rejected with InvalidArgumentException. Do not use the `__arix` prefix
for local template variables: it is reserved for the compiler and runtime.
There is no compatibility alias for `$view`, and a core update does not migrate `app/`.

All templates and pages are trusted code:
they are not a sandbox for files uploaded by visitors. HTML escaping does not validate
URLs or replace JavaScript encoding; use `@json` and validate protocols.

The engine takes layouts, sections, partials, and compilation from the Phenix
engine as a reference, adapting them to native PHP without its dependencies.

## Languages and Assets

Configure `locales`, `default_locale`, and `base_path` in `app/config/app.php`.
Catalogs are in `app/lang/`. Missing messages fall back to the default language
and then to the key itself. Negotiation respects `q` and variants such as en-US.
Slugs are shared across languages; there are no translated slugs.

To install under `/site`, set `base_path` to that value. Examples:

```php
url('/about');                    // /site/es/about
url('/about', 'en');              // /site/en/about
url('/');                        // /site/es
url()->current_path();           // /about (on the about page)
url()->current();                // /site/es/about, without a query string
url(url()->current_path(), 'en'); // /site/en/about
asset('styles/app.css');          // /site/styles/app.css
asset('scripts/app.js');          // /site/scripts/app.js
asset('assets/images/logo.webp'); // /site/assets/images/logo.webp
asset('assets/fonts/site.woff2');  // /site/assets/fonts/site.woff2
```

`url()` without arguments returns the UrlGenerator service; with a path, it generates
a localized, origin-relative URL. `current_path()` excludes base_path, language, and
query string, and encodes its segments for reuse with `url()` without double encoding.
`url()` accepts internal paths with or without a leading `/`, normalizes the trailing slash,
validates the language, and rejects protocols, external URLs, query strings, fragments, traversal,
and ambiguous separators. Segments are decoded once and re-encoded.
PageContext retains `url()` and `asset()`, delegating to the same generator registered
in the container, accessible through `PageContext::urls()`.

CSS and JS are served without transformation. Version their URLs when they change to
control browser caching. Public directories must contain only
their allowed assets: CSS in styles and JS in scripts. PHP scripts are not allowed.

Fonts belong in `assets/fonts/`; there is no need for a fonts directory at
the root. `assets/` allows AVIF, GIF, ICO, JPEG/JPG, PNG, WebP, MP4, WebM, OGV,
WOFF/WOFF2, TTF, and OTF. `assets/fonts/` allows only fonts. Filenames
use ASCII letters, numbers, hyphens, and underscores, with a single extension.
Subdirectories are allowed. SVG is excluded by default because it can contain
scripts and external resources. Do not accept untrusted uploads in these directories.
Their `.htaccess` files disable directory listings and CGI and restrict formats; Apache also
configures MIME types for video and fonts. On IIS, verify that those MIME types are
registered on the hosting server. The local router applies the same extension list.

## Cache

`app/config/cache.php` configures `enabled`, `path`, `version`, and `allow_eval`.
`path` is the private, writable parent directory outside the web root; `null`
uses `sys_get_temp_dir()`. Arix creates an installation-specific folder with
private permissions and checks ownership when POSIX is available.

Compiled views are invalidated by content and compiler version.
Native PHP views retain their `__DIR__` and work without write access.
By default, if the cache for `.arix.php` cannot be written, rendering fails with
a logged error and a generic 500 response: it does not silently enable eval.
`allow_eval => true` explicitly allows compiling and evaluating trusted templates
in memory if the hosting server permits it. Visitor data is never evaluated.

HTML caching is enabled per page through an attribute:

```php
use Arix\Pages\Attributes\Cache;

#[Get('/')]
#[Cache(ttl: 300, query: ['category'])]
final class Index implements Page
{
    // ...
}
```

A cache hit does not construct or render the page. Keys include language, route,
base URL, core version, deployment version, and allowed query parameters. Cookies,
Authorization, or unknown parameters bypass HTML caching. Do not cache pages
that depend on IP addresses, other headers, personal data, or values that must be immediately up to date.

Invalidation detects changes in pages, views, languages, configuration,
styles, scripts, and assets. It is conservative and computes file hashes: it is suitable for
small sites, not a constant-cost system for large projects.
Writes are atomic; there is no regeneration lock or cache stampede protection.
Temporary files deleted by the hosting server are regenerated. You can change `version`
through FTP/the control panel or call `$application->clearCache()` from trusted code,
without publishing a maintenance endpoint.

## Deployment Without SSH

1. Upload `index.php`, `app/`, `core/`, `styles/`, `scripts/`, `assets/`, `robots.txt`, and
   `.htaccess` or `web.config`. Include each directory's protections.
2. Do not deploy tests or documentation.
3. Configure the language, base URL, and cache through the control panel/FTP.
4. Verify `/`, `/es`, `/en/about`, assets, 404, HEAD, and POST 405.
5. Verify that `/app/config/app.php` and `/core/functions.php` return 403/404,
   never execute code publicly, and never expose files.
6. Verify that styles/scripts/assets do not serve hidden or executable files.

No mandatory commands are run on the hosting server. Keep `debug => false`,
disable display_errors in production, and check the PHP log in the control panel. Apache
and IIS require testing on the actual server. If the hosting service lets you choose the document
root, placing private directories outside it provides greater protection.

To update manually, use a compatible core version and put the site into maintenance mode
during the upload: file-by-file FTP does not provide atomic replacement. Do not mix
versions while requests are being served or overwrite application files.

## Tests

```sh
php tests/run.php
```

They cover attributes, the route registry, autoloading, languages, HEAD, traversal, caching,
and templates. To start the development server from the project root:

```sh
php -S 127.0.0.1:8080 core/Development/router.php
```

Never use `php -S` without the router: PHP does not apply `.htaccess` or `web.config`.
More details in [docs/IMPLEMENTATION.md](docs/IMPLEMENTATION.md).
