# ArixPHP

ArixPHP permite publicar sitios simples y multilingües en hosting PHP tradicional,
sin SSH, Composer, Node ni build de frontend. Está inspirado en la composición de
páginas de Astro y en la separación entre página, datos y presentación de Inertia;
no implementa sus compiladores ni un protocolo SPA.

No utiliza sesiones ni ofrece soporte de base de datos. Las páginas admiten
**GET y HEAD**; otros métodos reciben `405` con `Allow: GET, HEAD`.

## Requisitos

- PHP 8.2+, tokenizer, JSON y PCRE (extensiones estándar).
- Apache con mod_rewrite, mod_authz_core y AllowOverride apropiado, o IIS con PHP
  y URL Rewrite. Otros servidores requieren reglas equivalentes.
- Caché privada y escribible para vistas `.arix.php`. Las vistas `.php` nativas
  funcionan sin caché. El fallback con eval está desactivado por defecto.
- La CLI es exclusivamente local: PHP CLI y proc_open para el servidor.

## Estructura

```text
index.php                 Front controller público
app/
    bootstrap.php         Arranque pequeño y estable, propiedad de la aplicación
    config/               Configuración de aplicación y caché
    lang/                 Traducciones
    pages/                Clases App\Pages\
    views/                Vistas de páginas, layouts, partials y componentes
core/                     Framework reemplazable, namespace Arix\
    autoload.php          Autoload del framework y helpers
    functions.php         Helpers view() y trans(), resueltos desde el contenedor
    Container/            Servicios explícitos y scope de petición
    Version.php           Versión incluida en las claves de caché
styles/                   CSS público, sin build
scripts/                  JavaScript público, sin build
assets/                   Imágenes y vídeo públicos
    fonts/                Fuentes públicas; preferir WOFF2
cli/arix                  CLI externa y autónoma; no desplegar en producción
tests/                    Pruebas locales
```

`app/` y `core/` no son accesibles por HTTP. El servidor sirve `styles/`,
`scripts/` y `assets/` directamente: no existe un endpoint PHP de assets. No hay
`app/views/pages/` ni `app/src/`. La configuración continúa en `app/config/`.

## CLI global

`cli/arix` es un ejecutable autónomo que no carga código del proyecto para generar
páginas. Puede copiarse o enlazarse en un directorio propio incluido en `PATH`:

```sh
mkdir -p "$HOME/.local/bin"
ln -s /ruta/absoluta/a/arix/cli/arix "$HOME/.local/bin/arix"
```

Asegúrate de que `$HOME/.local/bin` esté en tu `PATH`. Desde la raíz del proyecto:

```sh
arix make:page Example
arix server
arix server --port=8090
```

El generador crea `app/pages/Example.php` y `app/views/example.arix.php` con la
ruta `/example`; exige nombres PascalCase válidos y nunca sobrescribe archivos.
`arix server` escucha únicamente en `127.0.0.1` y aplica las protecciones del
router local. No debe utilizarse como servidor de producción.

**`arix update` está deshabilitado de forma segura.** Todavía no existe un canal
de releases confiable ni una clave de firma. El comando devuelve un error y no
descarga, ejecuta ni modifica archivos. El futuro actualizador reemplazará solo
`core/`, con autenticación del paquete, compatibilidad y rollback. `app/`,
`styles/`, `scripts/` y `assets/` pertenecen a la aplicación y no deben modificarse.

No se instala nada globalmente automáticamente. La CLI puede mantenerse fuera
del sitio y no es necesaria en el hosting.

## Debug por variable de entorno

El debug está desactivado por defecto. Solo `APP_DEBUG=true` o `APP_DEBUG=1`
lo activan (sin distinguir mayúsculas y tolerando espacios alrededor). Ausente,
vacía, false, 0 o cualquier valor inválido lo dejan desactivado. No uses un cast
booleano de strings: `"false"` es truthy en PHP. No se cargan archivos `.env`.

```sh
APP_DEBUG=true arix server
APP_DEBUG=true arix server --port=8090
APP_DEBUG=true php tests/run.php

# Sin instalar la CLI:
APP_DEBUG=true php -S 127.0.0.1:8080 core/Development/router.php
```

`app/config/app.php` lee la variable del proceso mediante Environment::boolean().
Los overrides explícitos de Application tienen prioridad sobre esa configuración,
para pruebas. La aplicación muestra excepciones escapadas en debug; fuera de él
devuelve un error genérico. El bootstrap usa la misma conversión de entorno aun
si falla el autoload; devuelve texto plano, conserva HEAD y no cachea errores.
Ni headers ni parámetros HTTP pueden activar debug. Los errores siempre se
registran en el log PHP.

`arix server` hereda el entorno del padre y pasa a PHP display_errors=1 en debug
o 0 sin debug; log_errors permanece activo. En debug configura E_ALL. No activa
debug automáticamente por ser un servidor local. El front controller también
aplica estos ajustes durante el arranque. Para errores nativos que ocurren antes
de que se ejecute PHP de la aplicación, configura PHP desde su inicio:

```sh
APP_DEBUG=true php -d display_errors=1 -d log_errors=1 -d error_reporting=E_ALL \
  -S 127.0.0.1:8080 core/Development/router.php
```

En errores de otros comandos la CLI añade clase y ubicaciones de la excepción
solo en debug, sin volcar entorno ni argumentos de la traza. Mantiene todas sus
validaciones y no habilita actualizaciones inseguras. No uses debug en producción:
puede mostrar rutas, código y mensajes internos. Asegura también display_errors=Off
en la configuración PHP del hosting para fallos anteriores al bootstrap.

## Páginas y rutas

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

El atributo determina la URL, no el nombre del archivo. `#[Get('/')]` responde
a `/es` y `/en`; `#[Get('/about')]` responde a `/es/about` y `/en/about`.
La raíz `/` negocia Accept-Language y redirige temporalmente al idioma soportado.
Un idioma explícito en URL nunca cambia por el idioma del navegador.

La ruta debe comenzar con `/`, no tener barra final salvo `/`, query, fragmento
ni traversal. Los duplicados se rechazan. HEAD utiliza la misma página y headers
que GET, sin emitir cuerpo. No se admiten rutas dinámicas en esta versión.

El archivo debe coincidir exactamente con la clase para autoload, incluso en
sistemas sensibles a mayúsculas: `App\Pages\Blog\Article` corresponde a
`app/pages/Blog/Article.php`. Evita clases que solo se distingan por mayúsculas.

La aplicación descubre las clases, inspecciona atributos y cachea un registro
de rutas. En un descubrimiento frío carga declaraciones de todos los archivos
PHP de páginas, **pero no instancia todas las páginas**. Por eso esos archivos
deben contener declaraciones sin salida ni efectos secundarios. Clases abstractas
o que no implementen `Page` no publican rutas. El registro se invalida al cambiar
los archivos o la versión del core, sin comandos obligatorios en producción.

Para acceder al contexto, el constructor puede recibir un único `PageContext`:

```php
public function __construct(private readonly \Arix\Routing\PageContext $context)
{
}
```

Ofrece `request`, `translator`, `path`, `url()` y `asset()`. Los constructores de
página mantienen este contrato; no existe autowiring de dependencias arbitrarias.

## Contenedor y helpers

Cada petición tiene un contenedor pequeño con registros explícitos. `bind()` crea
objetos por resolución, `singleton()` comparte un objeto dentro del contenedor,
`instance()` registra un objeto existente y `get()` resuelve servicios. Servicios
ausentes, fábricas que no devuelven objetos y ciclos generan errores explícitos.
Reemplazar un registro invalida su instancia anterior; no puede reemplazarse un
servicio mientras está siendo resuelto. No se crean clases automáticamente.

La aplicación registra Request, Translator, PageContext (al resolver una página),
FileCache, PageRouter, TemplateEngine y ViewFactory. El traductor y el motor de
renderizado pertenecen a la petición; solo FileCache se comparte entre peticiones
de una misma Application. `view()` resuelve ViewFactory y crea una definición nueva
de nombre y datos, sin compartir propiedades entre vistas. `trans()` resuelve el
traductor del idioma de la URL y conserva reemplazos y fallback:

```php
return view('index', ['title' => trans('home.title')]);

trans('welcome', ['name' => 'Omar']);
```

En templates usa `{{ trans('home.title') }}` para escapar el HTML. `trans()` no
escapa por sí mismo, porque devuelve texto utilizable también fuera de HTML.

Los helpers requieren un contenedor activo durante la petición. Para usarlos en
pruebas o scripts confiables, registra servicios y activa un scope explícito:

```php
$container = new \Arix\Container\Container();
$container->singleton(\Arix\Views\ViewFactory::class, static fn () => new \Arix\Views\ViewFactory());
$container->instance(\Arix\Localization\Translator::class, $translator);

\Arix\Container\ContainerContext::run($container, static function () {
    return view('index', ['title' => trans('home.title')]);
});
```

Fuera de un scope los helpers generan LogicException; no hay inicialización global
silenciosa. `ContainerContext::run()` restaura el contexto anterior mediante
finally, incluidos errores, redirecciones y hits de caché. Admite scopes anidados
síncronos; no es un contexto para tareas concurrentes con fibers o coroutines.

`Request::httpMethod()` usa el enum `Arix\Constants\HttpMethod` y devuelve null
para métodos desconocidos, conservando el string original en `Request::$method`.
La aplicación solo permite GET/HEAD y responde 405 para los demás. `Response::send()`
acepta el enum o un string, y HEAD omite el cuerpo en ambos casos.

## Plantillas

`view('index')` resuelve `app/views/index.arix.php` o `app/views/index.php`, pero
no ambos. Los layouts y partials usan puntos: `layouts.main`, `partials.navigation`.

```php
@extends('layouts.main')

@section('content')
    <h1>{{ $title }}</h1>
    @include('partials.navigation')
@endsection
```

| Sintaxis | Uso |
| --- | --- |
| `{{ $value }}` | Escape HTML |
| `{!! $html !!}` | HTML confiable sin escape |
| `@extends('layouts.main')` | Layout |
| `@section('content') … @endsection` | Sección capturada |
| `@section('title', $title)` | Sección inline escapada |
| `@yield('content', 'Default')` | Insertar sección |
| `@include('partials.nav', ['items' => $items])` | Partial |
| `@if(...)`, `@elseif(...)`, `@else`, `@endif` | Condicionales |
| `@foreach(...) … @endforeach` | Iteración |
| `@for(...) … @endfor`, `@while(...) … @endwhile` | Bucles |
| `@component('components.card', ['heading' => $title]) … @endcomponent` | Componente |
| `@slot('heading') … @endslot` | Slot nombrado |
| `@json($value)` | JSON con caracteres HTML codificados |
| `{{-- comentario --}}` | Comentario eliminado |
| `@@include` | Texto literal `@include` |

Los slots contienen HTML generado: se insertan mediante `{!! $slot !!}`. Escapa
los datos de visitantes al construir ese contenido. Las vistas PHP nativas usan
`$view->extend()`, `startSection()`, `endSection()`, `yieldSection()` y `escape()`.

`$view` también ofrece `translate()`, `url()`, `asset()`, `locale()`, `locales()`
y `currentPath()`. Los nombres `view`, `data`, `path`, `fallback` y `allowEval`
están reservados en el scope. Todos los templates y páginas son código confiable:
no son un sandbox para archivos subidos por visitantes. El escape HTML no valida
URLs ni sustituye la codificación JavaScript; usa `@json` y valida protocolos.

El motor toma layouts, secciones, partials y compilación como referencia del
motor de Phenix, adaptándolo a PHP nativo sin sus dependencias.

## Idiomas y assets

Configura `locales`, `default_locale` y `base_path` en `app/config/app.php`.
Los catálogos están en `app/lang/`. Mensajes ausentes usan el idioma predeterminado
y después la propia clave. La negociación respeta `q` y variantes como en-US.
Los slugs se comparten entre idiomas; no hay slugs traducidos.

Para instalar en `/site`, configura `base_path` con ese valor. Ejemplos:

```php
$view->url('/about');             // /site/es/about
$view->asset('styles/app.css');   // /site/styles/app.css
$view->asset('scripts/app.js');   // /site/scripts/app.js
$view->asset('assets/images/logo.webp'); // /site/assets/images/logo.webp
$view->asset('assets/fonts/site.woff2'); // /site/assets/fonts/site.woff2
```

CSS y JS se sirven sin transformación. Versiona sus URLs cuando cambien para
controlar la caché del navegador. Los directorios públicos solo deben contener
sus assets permitidos: CSS en styles y JS en scripts. No se admiten scripts PHP.

Las fuentes pertenecen a `assets/fonts/`; no hace falta un directorio fonts en
la raíz. `assets/` admite AVIF, GIF, ICO, JPEG/JPG, PNG, WebP, MP4, WebM, OGV,
WOFF/WOFF2, TTF y OTF. `assets/fonts/` admite únicamente fuentes. Los nombres
usan letras ASCII, números, guiones y guiones bajos, con una sola extensión.
Se permiten subdirectorios. SVG se excluye por defecto porque puede contener
scripts y recursos externos. No aceptes uploads no confiables en estos directorios.
Sus `.htaccess` desactivan listados y CGI y restringen formatos; Apache configura
además los tipos MIME para vídeo y fuentes. En IIS verifica que esos MIME estén
registrados en el hosting. El router local aplica la misma lista de extensiones.

## Caché

`app/config/cache.php` configura `enabled`, `path`, `version` y `allow_eval`.
`path` es el directorio padre privado y escribible, fuera del web root; `null`
usa `sys_get_temp_dir()`. Arix crea una carpeta propia de la instalación con
permisos privados y comprueba propietario cuando POSIX está disponible.

Las vistas compiladas se invalidan por contenido y versión del compilador.
Las vistas PHP nativas conservan su `__DIR__` y funcionan sin escritura.
Por defecto, si no puede escribirse caché para `.arix.php`, el render falla con
un error registrado y una respuesta 500 genérica: no activa eval silenciosamente.
`allow_eval => true` permite explícitamente compilar y evaluar templates confiables
en memoria si el hosting lo permite. Nunca se evalúan datos de visitantes.

La caché HTML se habilita por página mediante un atributo:

```php
use Arix\Pages\Attributes\Cache;

#[Get('/')]
#[Cache(ttl: 300, query: ['category'])]
final class Index implements Page
{
    // ...
}
```

Un hit no construye ni renderiza la página. Las claves incluyen idioma, ruta,
base URL, versión del core, versión de despliegue y query permitida. Cookies,
Authorization o parámetros desconocidos evitan caché HTML. No cachees páginas
dependientes de IP, otros headers, datos personales o valores de frescura inmediata.

La invalidación detecta cambios en páginas, vistas, idiomas, configuración,
styles, scripts y assets. Es conservadora y calcula hashes de archivos: es adecuada para
sitios pequeños, no un sistema de coste constante para proyectos extensos.
Las escrituras son atómicas; no hay bloqueo de regeneración ni anti-stampede.
Los temporales eliminados por el hosting se regeneran. Puedes cambiar `version`
desde FTP/panel o llamar `$application->clearCache()` desde código confiable,
sin publicar un endpoint de mantenimiento.

## Despliegue sin SSH

1. Sube `index.php`, `app/`, `core/`, `styles/`, `scripts/`, `assets/`, `robots.txt` y
   `.htaccess` o `web.config`. Incluye las protecciones de cada directorio.
2. No despliegues CLI, pruebas ni documentación.
3. Configura idioma, base URL y caché desde el panel/FTP.
4. Verifica `/`, `/es`, `/en/about`, assets, 404, HEAD y POST 405.
5. Verifica que `/app/config/app.php` y `/core/functions.php` respondan 403/404,
   nunca ejecuten código públicamente ni revelen archivos.
6. Verifica que styles/scripts/assets no sirvan archivos ocultos ni ejecutables.

No se ejecutan comandos obligatorios en el hosting. Mantén `debug => false`,
desactiva display_errors en producción y consulta el log PHP del panel. Apache
e IIS necesitan pruebas en el servidor real. Si el hosting permite elegir document
root, colocar los directorios privados fuera de él proporciona mayor protección.

Para actualizar manualmente, usa una versión compatible del core y mantenimiento
durante la subida: FTP archivo por archivo no ofrece reemplazo atómico. No mezcles
versiones mientras hay peticiones ni sobrescribas los archivos de aplicación.

## Pruebas

```sh
php tests/run.php
```

Cubren atributos, registro de rutas, autoload, idiomas, HEAD, traversal, caché,
plantillas y generación segura de páginas. Para arrancar sin instalar la CLI:

```sh
php -S 127.0.0.1:8080 core/Development/router.php
```

Nunca uses `php -S` sin router: PHP no aplica `.htaccess` ni `web.config`.
Más detalles en [docs/IMPLEMENTATION.md](docs/IMPLEMENTATION.md).
