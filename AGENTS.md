# AGENTS.md

Project **under development**: ArixPHP, a static PHP framework without Composer, Node, builds, a database, or sessions. **It has its own Git repository** (initial commit, working tree with ongoing edits): do not run `checkout`/`restore`/`stash` or commit unless explicitly instructed, and read files before editing them because they may change during the session. This repository lives in the `arix-universe/` workspace (see `../AGENTS.md` from this repository's root).

Sources of truth: `README.md` (usage) and `docs/IMPLEMENTATION.md` (architecture and limitations). `SECURITY_AUDIT.md` documents the known open finding ARIX-SEC-001 (per-request fingerprinting before reading the cache): do not rediscover it; read it before touching cache invalidation.

## Commands

- Tests (the only verification): `php tests/run.php` — a single suite, ≈1 s, nonzero exit code if anything fails. Test/assertion counts change on every run: do not cite them as a contract.
- There is no single-test selector, linter, formatter, type checker, CI, or `composer.json`/`package.json`. Do not invent commands or add tooling. To check syntax: `php -l <file>`.
- Development server (from the root): `SERVER_NAME=http://127.0.0.1:8181 ./frankenphp run --config Caddyfile --adapter caddyfile` with the local FrankenPHP binary. Bare `php-server` loads no access rules; `php -S` and direct `php index.php` are unsupported.
- Debugging: only through the `APP_DEBUG=true|1` environment variable (`APP_DEBUG=true php tests/run.php`). There are no `.env` files.
- PHP ≥ 8.3 (the local default is 8.4); `core/Version.php` uses typed class constants.
- Local PHP prints an Xdebug warning to stderr on every run: this is noise, not a failure.

## Adding or Changing Tests

Everything is in `tests/*Test.php`: classes extending `Arix\Testing\TestCase` with `test*` methods, using `$this->assert*()`, `$this->raises()`, and `$this->fixture()` (temporary files that clean themselves up). `tests/run.php` is the thin runner. Run the full suite; iterate using `grep FAIL` on the output.

## Architecture

- Ownership boundary: `app/` = the user's site (`App\`), `core/` = the replaceable framework (`Arix\`), `styles/ scripts/ assets/` = public directories served directly (no PHP asset endpoint).
- Entry points: `index.php` → `core/autoload.php`, then `Arix\Runtime\RuntimeFactory` accepts only `frankenphp`, `fpm-fcgi`, `cgi-fcgi`, and `apache2handler` through the HTTP runtime; `cli`, `cli-server`, and anything else are rejected before `app/bootstrap.php` runs.
- Routes: `app/routes.php` calls `Arix\Facades\Route::get('/route', [Controller::class, 'action'])`. The facade uses the active container's RouteCollector; registration never constructs controllers. The filename must match class/case exactly: `App\Controllers\Blog\ArticleController` → `app/controllers/Blog/ArticleController.php`.
- `app/services.php` returns a registration closure; explicit bindings only, no autowiring. Actions are public instance methods with zero parameters or one required Request, returning Response or Views\Contracts\View. RequestContext is available when factories resolve, not during initial registration.
- Controller files contain declarations only; only the selected controller is loaded. Page/attributes/PageContext are removed, without aliases.
- URLs have no automatic language prefix or negotiation: `/` renders directly, translations use `app.default_locale`. UrlGenerator accepts base_path and current path, not locale arguments.
- `view()` and `trans()` require an active container (`ContainerContext::run()`); outside a scope they throw `LogicException` (write tests that use them accordingly).
- GET/HEAD/POST/PUT/PATCH/DELETE/OPTIONS/QUERY and extension methods via match(). Per-path 405/Allow; HEAD falls back to GET; OPTIONS automatic 204 unless explicitly registered. No dynamic routes or middleware. PHP's local server may reject QUERY with 501 before running Arix.
- Request::body() returns repeatable raw bytes, not parsed or validated data. app.max_body_bytes controls the bounded capture (default 1048576), excess returns 413. No FormRequests, uploads, auth, CSRF or CORS in this stage.

## Pitfalls

- **Cache versioning (the easiest to get wrong):** cache keys include `Version::ID`, `TemplateCompiler::VERSION`, and file fingerprints, but tests always use a fresh temporary directory, so they **never detect** an outdated version. If you change discovery/rendering logic in `core/`, bump `core/Version.php` (`ID`); if you change `core/Views/TemplateCompiler.php`, bump `TemplateCompiler::VERSION`. Without that bump, existing sites keep serving stale cached content.
- **Duplicated asset extensions:** the allowlist lives in `core/Http/PublicAssets.php` (used by tests), in the development `Caddyfile`, in the `.htaccess` files (root, `styles/`, `scripts/`, `assets/`, `assets/fonts/`), and in `web.config`. Changing allowed types = updating PHP + Caddy + Apache + IIS. `styles/` allows only `.css`, `scripts/` only `.js`; SVG is intentionally excluded.
- Views: `view('x')` resolves `app/views/x.arix.php` **or** `x.php`, not both (if both exist, it fails). `.arix.php` views are compiled into a private, writable cache; without write access they fail with 500 — do not enable `allow_eval` to hide the problem (disabled by default, only for trusted-code templates).
- HTML caching (`Route::get(...)->cache()`) stores View output only and bypasses cookies, Authorization, unknown query parameters and nonempty body. Implicit HEAD shares GET; explicit HEAD does not. Fingerprints include controllers, routes.php, services.php, views, languages, configuration and public assets. Response output and non-GET routes are never stored; HTTP writes do not invalidate external data.
- Sensitive extensions/files are blocked in `.htaccess`, `web.config`, and the development `Caddyfile`: if you touch routes or assets, verify that all layers remain aligned. Caddy follows symlinks, so public asset directories must not contain any.

## Conventions

- Code comments, messages, names, README and documentation in English. Translation catalogs retain their target languages.
- `declare(strict_types=1)` in all PHP files (except `.arix.php` templates).
- Do not declare classes as `final`, including application classes, framework classes, tests, and fixtures.
- No commands are run during deployment: do not add build steps or mandatory scripts on the hosting server.
- Use `type|null` instead `?type` for nullable parameters.
