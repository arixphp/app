# ArixPHP

ArixPHP is a PHP application skeleton that provides a starting point for building
your own web applications. It includes an application structure and a bundled
framework core, with routes, controllers, views, and translations you can
customize for your project.

Designed for traditional PHP hosting, applications built with ArixPHP can be
deployed without SSH, Composer, Node, or frontend build steps on the server.
ArixPHP is under active development.

## Local development and deployment caching

Run `arix dev` from this directory, or `php ../cli/arix dev` in the workspace.
It manages FrankenPHP and defaults to `http://127.0.0.1:8181`; use
`--port`, `--strict-port`, `--path`, `--frankenphp` or `--no-reload` as needed.
The layout subscribes to local change events only when FrankenPHP supplies
reload metadata. Starting development does not modify application files.

CSS/JS entry URLs use `versioned_asset()` content hashes. Production server
configuration requires revalidation (`Cache-Control: no-cache`) for HTML and
mutable CSS/JS, including imports; it does not impose this on images/fonts.
Framework files under `scripts/arix/` and `styles/arix/` are managed by the
CLI (`css:update`, `js:update`, `frontend:migrate`): edit `scripts/app.js`,
`styles/app.css`, `styles/theme.css` and your own components instead.
Keep static-file validators current when deploying. Existing fresh caches,
CDN overrides and service workers may require a URL change or purge.
Open production tabs do not update automatically. Apache/LiteSpeed and IIS
configuration still needs native header verification on those servers.

## Learning Arix

The official documentation will be available at
[arix.omarbarbosa.com](https://arix.omarbarbosa.com). It has not been
published yet.

## Security Vulnerabilities

If you discover a security vulnerability within Phenix, please send an e-mail to Omar Barbosa via [contacto@omarbarbosa.com](mailto:contacto@omarbarbosa.com). All security vulnerabilities will be promptly addressed.

## License

The Phenix framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
