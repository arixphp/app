# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

PHP developers, including agencies and freelancers, who need to build,
deliver, and maintain multilingual content sites on traditional PHP hosting.

## Product Purpose

ArixPHP is a small framework for publishing simple sites with server-rendered
HTML. The site included in `app/` demonstrates its capabilities; it is not a
standalone product. Success means developing locally and publishing
by uploading files, without mandatory commands on the hosting server.

## Positioning

Separate PHP pages, data, and presentation, with layouts, components, and translations,
without Composer, Node, or a frontend build. It is inspired by Astro's composition
and Inertia's separation, but does not implement their compilers or an SPA protocol.

## Operating Context

- Local development with PHP and direct editing of pages, views, and assets.
- Development server: `php -S 127.0.0.1:8080 core/Development/router.php`,
  from the project root; never start PHP without that router.
- Deployment by uploading files to traditional PHP hosting, with rules
  equivalent to those provided for Apache or IIS.
- `app/`, `styles/`, `scripts/`, and `assets/` belong to the user; `core/` defines
  the replaceable framework boundary.

## Capabilities and Constraints

- Server-rendered HTML; JavaScript only when the application needs it.
- Explicit routes declared through page attributes; GET and HEAD only,
  with no dynamic routes in this version.
- Translations and language prefixes in URLs; the demo includes Spanish and English.
- Native PHP or `.arix.php` views, layouts, includes, components, and optional
  HTML caching. Compiled views require a private, writable cache.
- No database support, sessions, autowiring, or SPA protocol.
- Do not add build steps or mandatory deployment scripts.
- Do not promise that automatic updates are available: the update mechanism
  remains disabled until a trusted release channel is available.
- The local CLI is described in README, but `cli/arix` is missing from the current tree;
  its availability and continued support are a pending decision, not a capability
  that should be advertised as operational.
- The current code requires PHP 8.3 or later; README still states 8.2+.
  Keep this discrepancy as a pending documentation issue; do not promise 8.2 support.

## Brand Commitments

Existing name: ArixPHP. Preserve product truth and avoid presenting the
demo as evidence of customers or business results. The specific voice
and other brand commitments remain undefined.

## Evidence on Hand

- `README.md`: usage, positioning, and API examples.
- `docs/IMPLEMENTATION.md`: framework architecture and limitations.
- `app/views/`, `app/pages/`, and `app/lang/`: a working demonstration of pages,
  view composition, and translations.
- `tests/run.php`: tests of implemented behavior; not evidence of
  benchmarks or business results.
- No testimonials, customers, performance metrics, pricing,
  or licensing terms have been confirmed for inclusion in future interfaces.

## Product Principles

1. Publish by uploading files, without mandatory tools on the hosting server.
2. Keep the separation between user code and the replaceable framework explicit.
3. Favor server-rendered HTML and simple capabilities over SPA complexity.
4. Make multilingual content part of the normal publishing workflow.
5. Communicate only available capabilities and distinguish pending decisions.

## Open Decisions

No specific accessibility requirements, required standard,
or binding editorial voice have been established. Do not invent approved needs or commitments.
