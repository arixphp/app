# ArixPHP

ArixPHP lets you publish simple, multilingual sites on traditional PHP hosting,
without SSH, Composer, Node, or a frontend build. It is inspired by Astro's page
composition and Inertia's separation of pages, data, and presentation;
it does not implement their compilers or an SPA protocol.

It does not use sessions or provide database support. Explicit routes target
controller actions and support GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS,
QUERY, and extension methods. Input validation is not included at this stage.

## Requirements

- PHP 8.3+, tokenizer, JSON, and PCRE (standard extensions).
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
    controllers/          App\Controllers\ classes
    routes.php            Explicit Route declarations
    services.php          Optional service and controller factories
    views/                Page views, layouts, partials, and components
core/                     Replaceable framework, Arix\ namespace
    autoload.php          Framework autoloading and helpers
    functions.php         view(), trans(), url(), asset(), and config() helpers
    Config/               Read-only configuration repository
    Container/            Explicit services and request scope
    Testing/              Minimal test framework (TestCase and asserts)
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
APP_DEBUG=true SERVER_NAME=http://127.0.0.1:8181 ./frankenphp run --config Caddyfile --adapter caddyfile
```

`app/config/app.php` reads the process variable using Environment::boolean().
Explicit Application overrides take precedence over that configuration
for testing. The application displays escaped exceptions in debug mode; otherwise,
it returns a generic error. Error responses render the application template
`app/views/errors/{status}.arix.php` (for example `500.arix.php`) with the
application name from `config('app.name')`; if that template is missing or
fails, a standalone inline page independent of views and assets is served.
The bootstrap uses the same environment conversion even
if autoloading fails; it returns plain text, preserves HEAD behavior, and does not cache errors.
Neither HTTP headers nor parameters can enable debugging. Errors are always
recorded in the PHP log.

The front controller sets display_errors=1 in debug mode or 0 otherwise;
log_errors remains enabled. In debug mode it sets E_ALL. Debugging is not
automatically enabled on a local server. For native errors that occur before
the application's PHP code runs, configure PHP from startup:

```sh
APP_DEBUG=true php -d display_errors=1 -d log_errors=1 -d error_reporting=E_ALL \
  -S 127.0.0.1:8080 index.php
```

Do not use debugging in production:
it may expose paths, code, and internal messages. Also ensure display_errors=Off
in the hosting server's PHP configuration for failures before the bootstrap.

## Controllers and Routes

`app/controllers/WelcomeController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use Arix\Views\Contracts\View;

final class WelcomeController
{
    public function index(): View
    {
        return view('index', ['title' => 'Example', 'description' => '']);
    }
}
```

`app/routes.php` registers URLs; there is no attribute or page discovery:

```php
use App\Controllers\WelcomeController;
use Arix\Facades\Route;

Route::get('/', [WelcomeController::class, 'index'])->cache(ttl: 300);
// Route::post('/contact', [ContactController::class, 'store']);
```

The facade resolves `RouteCollector` from the active container, following the
Phenix pattern. Registering routes does not instantiate controllers. Each request
loads `app/routes.php` again; there is no persistent route cache or compilation.

`app/services.php` is optional. When present it returns an explicit registration closure:

```php
use App\Controllers\WelcomeController;
use Arix\Container\Container;

return static function (Container $container): void {
    $container->bind(WelcomeController::class, static fn (): WelcomeController => new WelcomeController());
};
```

Controllers without required constructor parameters resolve from their
routes alone; an explicit binding always wins, and is required when the
constructor takes dependencies. A missing file is skipped; a present file
must return the closure.

Only the selected controller is resolved; there is no autowiring or mandatory base
class. Factories can resolve dependencies with `$container->get()`.
`RequestContext` is available when the controller is constructed, not during initial
registration. It provides `request`, `translator`, `path`, `url()`, `asset()`, and `urls()`.

Actions must be public instance methods with zero parameters or exactly one
required `Arix\Http\Request`. They declare a `Response`, `Arix\Views\Contracts\View`,
or union return type. `View` becomes HTML; `Response` preserves its status and
headers. Magic and static actions are not supported.

The `get/post/put/patch/delete/head/options/query` shortcuts and
`Route::match(['CUSTOM'], '/resource', [Controller::class, 'action'])`
share the same registry. Methods are case-sensitive. Duplicate method + path
pairs are rejected; GET and POST can coexist on the same path.
A missing path returns 404; an undeclared method returns 405 with a route-specific
Allow header. Explicit HEAD takes precedence; otherwise, GET handles it without
sending a body. Explicit OPTIONS takes precedence; otherwise, an existing route
returns 204 with Allow. This does not configure CORS.

URLs are **exactly as declared**: `/` responds directly and `/about` does not become
`/es/about`. There is no Accept-Language negotiation or language redirect;
`app.default_locale` selects translations. `/es` exists only if the application
registers that route. `base_path` still supports subdirectory installations.

Routes are static, absolute, and canonical, without queries, fragments, traversal,
or trailing slashes except for `/`. A request's trailing slash is canonicalized
with 308. There are no `{id}` parameters, groups, names, or middleware at this stage.
Filenames match classes exactly: `App\Controllers\Blog\ArticleController`
→ `app/controllers/Blog/ArticleController.php`. Symlinks are not supported.

**Breaking migration:** replace `app/pages/`, `Page::render()`, and Get/Cache
attributes with controllers, `app/routes.php`, bindings in `app/services.php`, and
`->cache()`. Replace `PageContext` with `RequestContext` and register
`registerControllers()` in bootstrap. Views do not need conversion. There is no
page compatibility layer or automatic migration of external applications.

## Request and Body

```php
public function store(\Arix\Http\Request $request): \Arix\Http\Response
{
    $body = $request->body(); // Raw bytes; repeated reads return the same value.
    return new \Arix\Http\Response('', 204);
}
```

`body()` does not parse JSON, forms, or files and does not merge query and body.
The constructor accepts the body as its fifth argument for tests.
The front controller captures Content-Type/Length and reads up to `max_body_bytes + 1`
bytes to detect overflow without relying solely on Content-Length.
`app.max_body_bytes` defaults to 1048576; overflow returns an uncached 413.
Server/PHP limits and buffering must also be configured.

There are no built-in Form Requests, validation, uploads, sessions, authentication,
CSRF, or CORS. Multipart is unsupported: PHP may process it before Arix and not
expose it through `php://input`. Sensitive operations require application protections.
QUERY requires a compatible server/proxy: PHP's built-in server
rejects it with 501 before executing any code.

## Container and Helpers

Each request has a small container with explicit registrations. `bind()` creates
objects on each resolution, `singleton()` shares an object within the container,
`instance()` registers an existing object, and `get()` resolves services. Missing
services, factories that do not return objects, and cycles produce explicit errors.
Replacing a registration invalidates its previous instance; a service cannot be replaced
while it is being resolved. Classes are not instantiated automatically.

The application registers Request, Translator, Config\Repository, RequestContext,
UrlGenerator, FileCache, RouteCollector, Router, ControllerDispatcher, TemplateEngine
and ViewFactory. The collector, translator, and engine belong to the request;
only FileCache is shared across an Application's requests. `view()` creates a new
definition; `trans()` uses the configured language with replacements and fallback:

```php
return view('index', ['title' => trans('home.title')]);

trans('welcome', ['name' => 'Omar']);
```

In templates, use `{{ trans('home.title') }}` to escape HTML. `trans()` does not
escape text itself, because it returns text that can also be used outside HTML.

Catalogs in `app/lang/` are PHP files returning nested arrays; dots in message
keys traverse those arrays. Literal keys containing dots are rejected with an
`InvalidArgumentException`:

```php
// app/lang/en.php
return [
    'home' => ['title' => 'Simple sites. Native PHP.'],
    'footer' => 'Server-rendered HTML.',
];
```

A message resolves only when the catalog stores a non-empty string; otherwise
lookup falls back to the default language and finally returns the key.

`config()` queries a read-only repository using dot notation:

```php
config('app.locale');                 // Configured translation language
config('app.locales');                // Supported languages
config('app.default_locale');         // Default translation language
config('app.name');
config('app.missing', 'Default');      // Value for missing keys
config('cache.allow_eval', false);
config();                            // Full configuration array
```

Configuration includes Application overrides; `app.locale` uses
`app.default_locale`, not a URL prefix or the browser's language. An existing
null value does not use the default. Writes are not supported.

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
    '/site', '/about',
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
TemplateEngine, even when passing RequestContext, does not activate a container:
if the template uses helpers, register their services and activate the scope explicitly.

`Request::httpMethod()` uses the `Arix\Constants\HttpMethod` enum and returns null
for unknown methods, preserving the original string in `Request::$method`.
The router supports registered methods. `Response::send()` accepts an enum or
string; HEAD and statuses 204/304 omit the body.

## Arrays

`Arix\Support\Arr` is a static utility for arrays with dot notation, the same
notation `config()` uses. A dot always separates segments; there is no escaping
for keys that contain a literal dot:

```php
use Arix\Support\Arr;

Arr::get($array, 'user.profile.name');        // Resolve a path
Arr::get($array, 'user.name', 'Default');     // Value for missing paths
Arr::set($array, 'user.profile.name', 'Ada'); // Creates intermediate arrays
Arr::has($array, 'user.profile');             // Whether the path exists
Arr::forget($array, 'user.profile');          // Remove a path
Arr::add($array, 'user.name', 'Ada');         // Set only when missing
Arr::except($array, ['user.profile']);        // Copy without paths
Arr::only($array, ['user', 'post']);          // Top-level keys only
Arr::dot($array);                             // Flatten to dot keys
Arr::pluck($rows, 'user.name');               // One path per element
Arr::first($array, fn ($value) => $value > 1);
Arr::where($array, fn ($value, $key) => $value === 1);
```

An existing null value is returned as null and does not use the default; the
default only applies to missing paths. `set()`, `forget()`, and `add()` follow
the same notation, and `Config\Repository` resolves its keys with these methods.

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

All templates and controllers are trusted code:
they are not a sandbox for files uploaded by visitors. HTML escaping does not validate
URLs or replace JavaScript encoding; use `@json` and validate protocols.

The engine takes layouts, sections, partials, and compilation from the Phenix
engine as a reference, adapting them to native PHP without its dependencies.

## Languages and Assets

Configure `locales`, `default_locale`, and `base_path` in `app/config/app.php`.
Catalogs are in `app/lang/` and return nested arrays; message keys use dot
notation over them, and literal dotted keys are rejected. Missing messages use
fallback and then the key. Translations do not modify URLs or negotiate
Accept-Language.

To install under `/site`, set `base_path` to that value. Examples:

```php
url('/about');                    // /site/about
url('/');                        // /site/
url()->currentPath();           // /about (on the about page)
url()->current();                // /site/about, without a query string
url(url()->currentPath());       // /site/about
asset('styles/app.css');          // /site/styles/app.css
asset('scripts/app.js');          // /site/scripts/app.js
asset('assets/images/logo.webp'); // /site/assets/images/logo.webp
asset('assets/fonts/site.woff2');  // /site/assets/fonts/site.woff2
```

`url()` returns UrlGenerator without arguments and generates an internal URL with
a path. It takes no language argument and adds no language prefixes.
`currentPath()` excludes base_path and query and encodes reusable segments without
double encoding. The generator retains protocol, traversal, and separator checks.
`RequestContext::urls()` exposes the container's instance; the context's `url()`
and `asset()` delegate to it.

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
`path` is the private, writable parent directory. It defaults to
`base_path('storage/cache')`, protected by the bundled Apache, IIS, and Caddy
rules. Deploy those protections before enabling this location. Prefer a path
outside the web root when hosting permits it; explicit `null` uses
`sys_get_temp_dir()`. Arix creates an installation-specific folder with private
permissions and checks ownership when POSIX is available.

`base_path(string $path = '')` returns the application's filesystem root or a
path beneath it, not the URL prefix configured by `app.base_path`. It is available
while loading application configuration and inside the active request container;
outside a container scope it throws `LogicException`. Paths are trusted application
input, not a containment check for visitor-supplied filenames.

`storage/logs/` is reserved for application logs; the framework continues using
PHP's configured `error_log()` destination and does not redirect it automatically.

Compiled views are invalidated by content and compiler version.
Native PHP views retain their `__DIR__` and work without write access.
By default, if the cache for `.arix.php` cannot be written, rendering fails with
a logged error and a generic 500 response: it does not silently enable eval.
`allow_eval => true` explicitly allows compiling and evaluating trusted templates
in memory if the hosting server permits it. Visitor data is never evaluated.

HTML caching is enabled only on GET routes:

```php
Route::get('/', [WelcomeController::class, 'index'])
    ->cache(ttl: 300, query: ['category']);
```

A hit skips controller construction and action execution. Only HTML generated
from View is stored, not arbitrary Responses. Implicit HEAD shares GET; explicit
HEAD does not. Cookies, Authorization, unknown query keys, or nonempty bodies
bypass caching. Keys include configured language, route, selected controller/action,
base_path, core/deployment version, and query. Do not cache personalization outside
those factors.

Invalidation includes controllers, routes.php, services.php, views, languages,
configuration, and assets. It still computes hashes and does not promise constant
cost. HTTP writes do not automatically invalidate external data changes.
Writes are atomic; there is no regeneration lock or cache stampede protection.
Temporary files deleted by the hosting server are regenerated. You can change `version`
through FTP/the control panel or call `$application->clearCache()` from trusted code,
without publishing a maintenance endpoint.

## Deployment Without SSH

1. Upload `index.php`, `app/`, `core/`, `styles/`, `scripts/`, `assets/`, `storage/`, `robots.txt`, and
   `.htaccess` or `web.config`. Include each directory's protections.
2. Do not deploy tests or documentation.
3. Configure the language, base URL, and cache through the control panel/FTP.
4. Verify `/`, `/about`, assets, 404, HEAD, OPTIONS, and registered methods.
5. Verify that `/app/config/app.php`, `/core/functions.php`, and files under `/storage/` return 403/404,
   never execute code publicly, and never expose files.
6. Verify that styles/scripts/assets do not serve hidden or executable files.

No mandatory commands are run on the hosting server. Keep `debug => false`,
disable display_errors in production, and check the PHP log in the control panel. Apache
and IIS require testing on the actual server. If the hosting service lets you choose the document
root, placing private directories outside it provides greater protection.

To update manually, use a compatible core version and put the site into maintenance mode
during the upload: file-by-file FTP does not provide atomic replacement. Do not mix
versions while requests are being served or overwrite application files.

## FrankenPHP and Caddy

The included `Caddyfile` runs ArixPHP through FrankenPHP without changing the
system PHP installation. It serves only the asset formats allowed by ArixPHP,
blocks private and hidden paths, and sends every application request to
`index.php`. Worker mode is intentionally not enabled because ArixPHP does not
support class reloading in long-running workers.

With a standalone FrankenPHP binary in the project root (a developer prerequisite,
ignored by Git, never required on Apache/IIS hosting), start the development
server with:

```sh
APP_DEBUG=true SERVER_NAME=http://127.0.0.1:8181 ./frankenphp run --config Caddyfile --adapter caddyfile
```

Open `http://127.0.0.1:8181`. Always load this `Caddyfile` explicitly: bare
`frankenphp php-server` applies no access rules and executes any reachable PHP
file, including private ones. The binary does not replace
the PHP executable installed by Homebrew. `APP_DEBUG` controls application error
pages; PHP startup errors remain hidden and are written to the PHP log.

For production, leave `APP_DEBUG` unset and provide the public address through
`SERVER_NAME`; Caddy will manage HTTPS for a hostname automatically:

```sh
SERVER_NAME=example.com ./frankenphp run --config Caddyfile
```

Run FrankenPHP from the repository root because the `Caddyfile` uses that
directory as its site root. Its request-body limit matches
`app.max_body_bytes` (1 MiB); update both values together if that setting changes.
Caddy may reject oversized bodies before PHP runs, while the application limit
remains the enforced contract for requests PHP actually reads.
Never create symlinks inside `styles/`, `scripts/`, or `assets/`: unlike the
previous PHP router, Caddy follows them and can expose files outside the project.

## Tests

```sh
php tests/run.php
```

Tests cover the collector/facade, controllers, methods, body, autoloading,
configured translations, path safety, caching, templates, and HTTP runtime
selection. Tests run in-process without starting a server. PHP CLI renders
nothing: `php index.php` exits nonzero. `php -S` is unsupported. To start the
development server, use FrankenPHP from the project root:

```sh
SERVER_NAME=http://127.0.0.1:8181 ./frankenphp run --config Caddyfile --adapter caddyfile
```

More details in [docs/IMPLEMENTATION.md](docs/IMPLEMENTATION.md).
