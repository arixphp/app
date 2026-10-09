# AGENTS.md

Proyecto **en desarrollo**: ArixPHP, framework PHP estático sin Composer, Node, build, BD ni sesiones. No hay repo Git (no ejecutes comandos `git` ni asumas historial).

Fuentes de verdad: `README.md` (uso) y `docs/IMPLEMENTATION.md` (arquitectura y límites). `PROMPT.md` es un prompt histórico con estructura ya obsoleta (`app/src`, rutas `#[Get('index')]`): no lo sigas. `SECURITY_AUDIT.md` documenta el hallazgo abierto conocido ARIX-SEC-001 (fingerprint por solicitud antes de leer caché): no lo redescubras; léelo antes de tocar la invalidación de caché.

## Comandos

- Pruebas (única verificación): `php tests/run.php` — 36 pruebas, ≈15 s, código de salida ≠0 si algo falla.
- No hay selector de prueba única, linter, formatter, typecheck, CI ni `composer.json`/`package.json`. No inventes comandos ni añadas tooling. Para comprobar sintaxis: `php -l <fichero>`.
- Servidor de desarrollo (desde la raíz): `php -S 127.0.0.1:8080 core/Development/router.php`. Nunca `php -S` sin router: no aplicaría `.htaccess`/`web.config`.
- CLI autónoma (debe ejecutarse con cwd en la raíz): `php cli/arix make:page Example`, `php cli/arix server [--port=8090]`. `arix update` falla a propósito: no lo "arregles".
- Debug: solo `APP_DEBUG=true|1` por variable de entorno (`APP_DEBUG=true php tests/run.php`). No hay ficheros `.env`.
- PHP ≥ 8.3 (el default local es 8.4). Pese a que README dice 8.2+, `core/Version.php` usa constantes de clase tipadas (8.3+): con PHP 8.2 el parseo falla y 9 pruebas rompen.
- El PHP local imprime un aviso de Xdebug a stderr en cada ejecución: es ruido, no un fallo.

## Añadir o cambiar pruebas

Todo está en `tests/run.php`: `test('nombre', fn () => ...)` con `check()`, `raises()` y `fixture()` (ficheros temporales que se limpian solos). Se ejecuta la suite completa; itera con `grep FAIL` en la salida.

## Arquitectura

- Frontera de propiedad: `app/` = sitio del usuario (`App\`), `core/` = framework reemplazable (`Arix\`), `styles/ scripts/ assets/` = públicos servidos directamente (sin endpoint PHP de assets). El futuro `arix update` reemplazará solo `core/`; `cli/arix` no carga código del proyecto (lo exigen las pruebas).
- Entradas: `index.php` → `app/bootstrap.php` → `core/autoload.php`; el dev server entra por `core/Development/router.php` → `index.php`.
- Rutas: el atributo `#[Get('/ruta')]` define la URL, no el nombre del fichero. El fichero debe coincidir exactamente con la clase, sensible a mayúsculas: `App\Pages\Blog\Article` → `app/pages/Blog/Article.php`.
- Los ficheros de páginas solo contienen declaraciones: el descubrimiento frío los carga todos, sin salida ni efectos secundarios.
- Contenedor explícito, sin autowiring: el constructor de una página admite 0 parámetros o un único `PageContext`.
- `view()` y `trans()` requieren contenedor activo (`ContainerContext::run()`); fuera de scope lanzan `LogicException` (así se escriben las pruebas que los usan).
- Solo GET/HEAD (405 con `Allow` para el resto). Sin rutas dinámicas en esta versión.

## Trampas

- **Versionado de caché (la más fácil de fallar):** las claves de caché incluyen `Version::ID`, `TemplateCompiler::VERSION` y fingerprints de ficheros, pero las pruebas usan siempre un directorio temporal nuevo, así que **nunca detectan** una versión desactualizada. Si cambias lógica de descubrimiento/render en `core/`, sube `core/Version.php` (`ID`); si cambias `core/Views/TemplateCompiler.php`, sube `TemplateCompiler::VERSION`. Sin ese bump, los sitios existentes siguen sirviendo caché obsoleta.
- **Extensiones de assets duplicadas:** la allowlist vive en `core/Http/PublicAssets.php` (la usan el dev router y las pruebas), en los `.htaccess` (raíz, `styles/`, `scripts/`, `assets/`, `assets/fonts/`) y en `web.config`. Cambiar tipos permitidos = actualizar PHP + Apache + IIS. `styles/` solo `.css`, `scripts/` solo `.js`; SVG está excluido a propósito.
- Vistas: `view('x')` resuelve `app/views/x.arix.php` **o** `x.php`, no ambos (si hay dos, falla). Las `.arix.php` se compilan a una caché privada escribible; sin escritura fallan con 500 — no actives `allow_eval` para ocultar el problema (apagado por defecto, solo para plantillas de código fiable).
- La caché HTML (`#[Cache]`, p. ej. en `app/pages/Index.php`) se salta si hay cookies, `Authorization` o parámetros de query desconocidos, y se invalida por fingerprint de `app/`, `styles/`, `scripts/` y `assets/`.
- Extensiones/archivos sensibles bloqueados en `.htaccess`, `web.config` y `core/Development/router.php`: si tocas rutas o assets, comprueba que las tres capas siguen coincidiendo.

## Convenciones

- Comentarios de código, mensajes y nombres en inglés; documentación (`README.md`, `docs/`) en español.
- `declare(strict_types=1)` en todo PHP (salvo plantillas `.arix.php`).
- Al desplegar no se ejecutan comandos: no añadas pasos de build ni scripts obligatorios en hosting.
