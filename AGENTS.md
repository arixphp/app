# AGENTS.md

This project is an ArixPHP application: a static PHP framework without Composer, Node,
builds, a database, or sessions. The Arix CLI (`arix`) is installed globally and provides
everything the application needs at development time: the test runner, the formatter, code
generators, the development server, and the managed core and frontend updates.

Sources of truth: `README.md` (usage). Every command referenced below comes from the CLI.

## Commands

Run every command from the application root.

- Tests: `arix tests` runs the Pest suite under `tests/`. The CLI supplies Pest; failures and
  empty suites return nonzero. Test and assertion counts are not contracts.
- Formatting: `arix format` applies the rules in `.arix-format.json`; `arix format --test`
  reports violations without modifying files. The application has no local runner, Composer
  installation, static analyzer, or bundled formatter; both the tests and the formatter come
  from the CLI.
- Generators: `arix make:controller`, `arix make:middleware`, `arix make:view` and
  `arix make:test` (`--unit` for a unit test). They never replace an existing file unless
  `--force` is passed.
- Development server: `arix dev` serves the application with a managed FrankenPHP runtime and
  defaults to `http://127.0.0.1:8181`; `--port`, `--strict-port`, `--path`, `--frankenphp` and
  `--no-reload` adjust it. `php -S` and `php index.php` are unsupported.
- Managed updates: `arix core:update` replaces the framework in `core/`; `arix css:update` and
  `arix js:update` refresh `styles/arix/` and `scripts/arix/`. All three accept `--dry-run`.
- Server policy: `arix assets:policy apache|caddy|iis` renders or verifies the static asset
  exposure rules.
- Debugging: only through the `APP_DEBUG=true|1` environment variable (`APP_DEBUG=true arix
  tests`). There are no `.env` files.
- PHP ≥ 8.4.1 and ext-mbstring, enforced by `core/autoload.php`.
- To check syntax: `php -l <file>`.

## Adding or Changing Tests

`tests/**/*Test.php` contains only tests of application-owned `app/` code, written in Pest
syntax (`it()`/`test()` and `expect()`). The framework's own test suite lives in the CLI
project and is not part of this application. `tests/autoload.php` loads the local core and the
application and test classes without Composer or the HTTP entry point. `tests/Pest.php` binds
the framework `Arix\Testing\TestCase`, so `$this->get('/path')` dispatches in-process and
returns an `Arix\Testing\TestResponse` with chainable `assertStatus()`, `assertOk()`, and
`assertSee()` assertions. Run the suite through the CLI; do not reintroduce `tests/run.php` or
install Pest in the application. XML and tests are trusted developer code, not a sandbox.
`phpunit.xml` and `phpunit.xml.dist` must stay protected in Apache, Caddy and IIS.

## Architecture

- Ownership boundary: `app/` = the user's site (`App\`), `core/` = the replaceable framework
  (`Arix\`), `styles/ scripts/ fonts/ media/` = public directories served directly (no PHP asset
  endpoint). Only `scripts/arix/` and `styles/arix/` are managed frontend update targets;
  fonts/media remain application-owned.
- `core/` is managed by the CLI: do not edit it and do not change its version constants. Run
  `arix core:update` to replace it.
- Entry points: `index.php` → `core/autoload.php`, then `Arix\Runtime\RuntimeFactory` accepts
  only `frankenphp`, `fpm-fcgi`, `cgi-fcgi`, and `apache2handler` through the HTTP runtime;
  `cli`, `cli-server`, and anything else are rejected before `app/bootstrap.php` runs.
- Routes: `app/routes.php` calls `Arix\Facades\Route::get('/route', [Controller::class,
  'action'])`. The facade uses the active container's RouteCollector; registration never
  constructs controllers. The filename must match class/case exactly:
  `App\Controllers\Blog\ArticleController` → `app/Controllers/Blog/ArticleController.php`.
- Classes: the core autoloads `App\` classes, interfaces, traits and enums from `app/` on
  demand, with exact case and no Composer: `App\Utils\Formatter` → `app/Utils/Formatter.php`.
  Create directories when the site needs them; the skeleton ships no extra ones. The
  lowercase resource directories `config`, `lang` and `views` are not namespaces, and the
  `bootstrap`, `routes`, `services` and `schedules` entry names are not class names.
- `app/services.php` is optional and returns a registration closure when present; explicit
  bindings only, no autowiring. Controllers without required constructor parameters resolve
  from their routes alone; explicit bindings always win. Actions are public instance methods
  with zero parameters or one required Request, returning Response or Views\Contracts\View.
  RequestContext is available when factories resolve, not during initial registration.
- Controller files contain declarations only; only the selected controller is loaded.
  Page/attributes/PageContext are removed, without aliases.
- URLs have no automatic language prefix or negotiation: `/` renders directly, translations use
  `app.default_locale`. UrlGenerator accepts base_path and current path, not locale arguments.
- `view()` and `trans()` require an active container (`ContainerContext::run()`); outside a
  scope they throw `LogicException` (write tests that use them accordingly).
- GET/HEAD/POST/PUT/PATCH/DELETE/OPTIONS/QUERY and extension methods via match(). Per-path
  405/Allow; HEAD falls back to GET; OPTIONS automatic 204 unless explicitly registered.
  Middleware implements `Arix\Contracts\Middleware` and is registered explicitly in
  `app/bootstrap.php`. PHP's local server may reject QUERY with 501 before running Arix.
- Dynamic routes are supported: `Route::get('/articles/{id:\d+}', ...)` and optional segments
  `Route::get('/archive[/{year:\d+}]', ...)`. A parameter is exactly one path segment: the
  captured value never contains `/`, a control character or a `.`/`..` segment, and a request
  that does not respect it is answered with 404. The constraint is compiled into the route
  pattern, so it must be a valid pattern without capturing groups and without the `~`
  delimiter. A parameter value is controlled by the visitor: escape it before it reaches a
  response. Parameter names beginning with `_arix_` are reserved.
- The request path is decoded exactly once. A path that still carries an escape after that
  decoding is rejected with 400, and a path longer than 4096 bytes is rejected with 400.
- The compiled template cache and the scheduler log belong to the framework: they are always
  `storage/cache` and `storage/logs/cron.log` inside the application root. `cache.path` and
  `schedule.log` are reserved configuration keys and are rejected when a configuration file
  declares them. `cache.version` and `cache.templates` stay configurable, as do
  `schedule.timezone` and `schedule.grace`.
- `app.trusted_hosts` accepts a list of host patterns. Without it the `Host` header of a
  request is controlled by the visitor, and application code that builds absolute URLs from it
  is exposed to host header poisoning.
- Request::body() returns repeatable raw bytes, not parsed or validated data.
  app.max_body_bytes controls the bounded capture (default 1048576), excess returns 413. No
  FormRequests, uploads, auth, CSRF or CORS in this stage.

## Pitfalls

- **Never patch the framework by hand:** `core/` is replaced by `arix core:update`. A change that
  looks like a framework fix belongs in the CLI project, not in this application.
- **Public resource policy:** `core/Assets/public-policy.json` is the runtime policy; the CLI
  generates matching Apache, Caddy and IIS blocks. All four directory `.htaccess` files must
  match it too. `style('app.css')` and `script('app.js')` always hash content;
  `font('inter.woff2')` and `media('logo.webp')` accept `versioned: true`. Paths are relative
  to the helper's root. SVG and public uploads are excluded; public trees must contain no
  links. Apache requires 2.4+, headers/mime/rewrite modules, AllowOverride and virtual-host
  `TraceEnable Off`. LiteSpeed and IIS still require native deployment verification.
- Views: `view('x')` resolves `app/views/x.arix.php` **or** `x.php`, not both (if both exist, it
  fails). `.arix.php` views are compiled into a private, writable cache; without write access
  they fail with 500 — do not enable `allow_eval` to hide the problem (disabled by default,
  only for trusted-code templates).
- HTML caching (`Route::get(...)->cache()`) stores View output only and bypasses cookies,
  Authorization, unknown query parameters and nonempty body. Implicit HEAD shares GET; explicit
  HEAD does not. Fingerprints include controllers, routes.php, services.php, views, languages,
  configuration and public assets. Response output and non-GET routes are never stored; HTTP
  writes do not invalidate external data.
- Sensitive extensions/files are blocked in `.htaccess`, `web.config`, and the development
  `Caddyfile`: if you touch routes or assets, verify that all layers remain aligned. Caddy
  follows symlinks, so public asset directories must not contain any.

## Conventions

- Code comments, messages, names, README and documentation in English. Translation catalogs
  retain their target languages.
- `declare(strict_types=1)` in all PHP files (except `.arix.php` templates).
- Do not declare classes as `final`, including application classes, framework classes, tests,
  and fixtures.
- No commands are run during deployment: do not add build steps or mandatory scripts on the
  hosting server.
- Use `type|null` instead `?type` for nullable parameters.

## Working on the skeleton repository

This section applies to this repository (`arixphp/app`) only. It does not apply to
applications created with `arix new`, which receive the document above from the CLI.

- ArixPHP is under active development. This repository has its own Git history and an active
  working tree: do not run `checkout`, `restore`, `stash`, `reset` or commit unless explicitly
  instructed, and read files before editing them because they may change during the session.
- Framework development happens in the CLI project (`arixphp/cli`). Use the installed `arix`
  executable, or a local build of that project when a specific build is required.
- Framework architecture and limitations: `docs/IMPLEMENTATION.md`. The known open finding
  ARIX-SEC-001 (per-request fingerprinting before reading the cache) is tracked there; do not
  rediscover it, and read it before touching cache invalidation.
- Changes to `core/` require bumping `core/Version.php` (`ID`) or
  `core/Views/TemplateCompiler.php` (`VERSION`); without the bump, existing sites keep serving
  stale cached content.
- CI: `.github/workflows/ci.yml` runs on pushes and pull requests targeting `main` and
  `develop`. It runs on Ubuntu only, installs the published Arix CLI with Homebrew
  (`brew install arixphp/tap/arix`), then runs `arix tests` and `arix format --test` with that
  executable. It installs nothing in the application and needs no secret. The installed CLI
  version appears in the job log. The formatting step runs `arix format --test` directly and
  preserves the CLI output and exit status. Test configuration is `phpunit.xml`, with bootstrap
  `tests/autoload.php`.
