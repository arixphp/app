# ArixPHP

ArixPHP is a PHP application skeleton that provides a starting point for building
your own web applications. It includes an application structure and a bundled
framework core, with routes, controllers, views, and translations you can
customize for your project.

Designed for traditional PHP hosting, applications built with ArixPHP can be
deployed without SSH, Composer, Node, or frontend build steps on the server.

## Local development and deployment caching

Run `arix dev` from this directory.
It manages FrankenPHP and defaults to `http://127.0.0.1:8181`; use
`--port`, `--strict-port`, `--path`, `--frankenphp` or `--no-reload` as needed.
The layout subscribes to local change events only when FrankenPHP supplies
reload metadata. Starting development does not modify application files.

CSS/JS entry URLs use `style('app.css')` and `script('app.js')` content hashes. Production server
configuration requires revalidation (`Cache-Control: no-cache`) for HTML and
mutable CSS/JS, including imports; it does not impose this on images/fonts.
Framework files under `scripts/arix/` and `styles/arix/` are managed by the
CLI (`css:update`, `js:update`): edit `scripts/app.js`,
`styles/app.css`, `styles/theme.css` and your own components instead.
Keep static-file validators current when deploying. Existing fresh caches,
CDN overrides and service workers may require a URL change or purge.
Open production tabs do not update automatically. LiteSpeed and IIS still need
native verification; Apache requires 2.4+, headers/mime/rewrite modules,
AllowOverride and `TraceEnable Off` in the virtual host.

## Public resources

Helpers return URL strings, not markup. Use escaped Blade output:

```blade
<link rel="stylesheet" href="{{ style('app.css') }}">
<script type="module" src="{{ script('app.js') }}"></script>
<img src="{{ media('arix-icon.webp', versioned: true) }}" alt="Arix">
```

`font('inter.woff2')` targets `fonts/`; `media('logo.webp')` targets `media/`.
Both default to unversioned URLs and accept `versioned: true` for SHA-256 hashes.
Versioned files must exist and have exact casing. Do not repeat directory names
or supply queries/fragments. `app.base_path` is added once. CSS uses relative
references such as `url('../fonts/inter.woff2')`, not PHP helpers.

`fonts/` and `media/` belong to the application; updates never replace them.
The skeleton ships its three logos in `media/`. Only trusted developer media is
allowed: no SVG, uploads, hidden files, PHP or links/junctions. Audit public trees
before deployment: static servers may follow links. Files normally use 0644,
directories 0755; avoid world-writable code/public resources. No hosting build
or CLI command is required.

## Learning Arix

The official documentation will be available at
[arix.omarbarbosa.com](https://arix.omarbarbosa.com). It has not been
published yet.

## Security Vulnerabilities

If you discover a security vulnerability within Phenix, please send an e-mail to Omar Barbosa via [contacto@omarbarbosa.com](mailto:contacto@omarbarbosa.com). All security vulnerabilities will be promptly addressed.

## License

The Phenix framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
