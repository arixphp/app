# AGENTS.md

Project **under development**: ArixPHP, a static PHP framework without Composer, Node, builds, a database, or sessions. **It has its own Git repository** (initial commit, working tree with ongoing edits): do not run `checkout`/`restore`/`stash` or commit unless explicitly instructed, and read files before editing them because they may change during the session. This repository lives in the `arix-universe/` workspace (see `../AGENTS.md` from this repository's root).

Sources of truth: `README.md` (usage) and `docs/IMPLEMENTATION.md` (architecture and limitations). `SECURITY_AUDIT.md` documents the known open finding ARIX-SEC-001 (per-request fingerprinting before reading the cache): do not rediscover it; read it before touching cache invalidation.

## Commands

- Tests (the only verification): `php tests/run.php` — a single suite, ≈1 s, nonzero exit code if anything fails. Test/assertion counts change on every run: do not cite them as a contract.
- There is no single-test selector, linter, formatter, type checker, CI, or `composer.json`/`package.json`. Do not invent commands or add tooling. To check syntax: `php -l <file>`.
- Development server (from the root): `php -S 127.0.0.1:8080 core/Development/router.php`. Never use `php -S` without the router: it would not apply `.htaccess`/`web.config`.
- Debugging: only through the `APP_DEBUG=true|1` environment variable (`APP_DEBUG=true php tests/run.php`). There are no `.env` files.
- PHP ≥ 8.3 (the local default is 8.4). Although README says 8.2+, `core/Version.php` uses typed class constants (8.3+): parsing fails on PHP 8.2 and 9 tests fail.
- Local PHP prints an Xdebug warning to stderr on every run: this is noise, not a failure.

## Adding or Changing Tests

Everything is in `tests/run.php`: `test('name', fn () => ...)` with `check()`, `raises()`, and `fixture()` (temporary files that clean themselves up). Run the full suite; iterate using `grep FAIL` on the output.

## Architecture

- Ownership boundary: `app/` = the user's site (`App\`), `core/` = the replaceable framework (`Arix\`), `styles/ scripts/ assets/` = public directories served directly (no PHP asset endpoint).
- Entry points: `index.php` → `app/bootstrap.php` → `core/autoload.php`; the development server enters through `core/Development/router.php` → `index.php`.
- Routes: the `#[Get('/route')]` attribute defines the URL, not the filename. The filename must match the class exactly, including case: `App\Pages\Blog\Article` → `app/pages/Blog/Article.php`.
- Page files contain declarations only: cold discovery loads them all, without output or side effects.
- Explicit container, no autowiring: a page constructor accepts 0 parameters or a single `PageContext`.
- `view()` and `trans()` require an active container (`ContainerContext::run()`); outside a scope they throw `LogicException` (write tests that use them accordingly).
- GET/HEAD only (405 with `Allow` for other methods). No dynamic routes in this version.

## Pitfalls

- **Cache versioning (the easiest to get wrong):** cache keys include `Version::ID`, `TemplateCompiler::VERSION`, and file fingerprints, but tests always use a fresh temporary directory, so they **never detect** an outdated version. If you change discovery/rendering logic in `core/`, bump `core/Version.php` (`ID`); if you change `core/Views/TemplateCompiler.php`, bump `TemplateCompiler::VERSION`. Without that bump, existing sites keep serving stale cached content.
- **Duplicated asset extensions:** the allowlist lives in `core/Http/PublicAssets.php` (used by the development router and tests), in the `.htaccess` files (root, `styles/`, `scripts/`, `assets/`, `assets/fonts/`), and in `web.config`. Changing allowed types = updating PHP + Apache + IIS. `styles/` allows only `.css`, `scripts/` only `.js`; SVG is intentionally excluded.
- Views: `view('x')` resolves `app/views/x.arix.php` **or** `x.php`, not both (if both exist, it fails). `.arix.php` views are compiled into a private, writable cache; without write access they fail with 500 — do not enable `allow_eval` to hide the problem (disabled by default, only for trusted-code templates).
- HTML caching (`#[Cache]`, e.g. in `app/pages/Index.php`) is bypassed when cookies, `Authorization`, or unknown query parameters are present, and invalidated by fingerprints of `app/`, `styles/`, `scripts/`, and `assets/`.
- Sensitive extensions/files are blocked in `.htaccess`, `web.config`, and `core/Development/router.php`: if you touch routes or assets, verify that all three layers remain aligned.

## Conventions

- Code comments, messages, and names in English; documentation (`README.md`, `docs/`) in Spanish.
- `declare(strict_types=1)` in all PHP files (except `.arix.php` templates).
- No commands are run during deployment: do not add build steps or mandatory scripts on the hosting server.
