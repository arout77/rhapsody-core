# Rhapsody Module System — Design v1

## Goal
Let contributors ship real functionality (event listeners, routes, Twig
helpers, settings, scoped storage) as installable packages, without giving
their code full access to the container, database, or filesystem. The
manifest is the entire declared attack surface, which is what makes
automated marketplace review possible: a submission can be scored before
any of its code executes.

## Distribution
Modules are ordinary Composer packages with `"type": "rhapsody-module"` in
their own `composer.json`. The marketplace's job is licensing/access to a
private package repository (Satis/Private Packagist), not installation
mechanics — `composer require` already does that. `ModuleRegistry` finds
every installed module via `Composer\InstalledVersions::getInstalledPackagesByType()`.

## The manifest (`module.json`)
Required at the package root. Schema: `schema/module.schema.json`.
Enforced at runtime by `ModuleManifest::fromFile()` (throws
`ManifestValidationException` on anything malformed).

Key fields:
- `name` — `vendor-slug/module-slug`, must match the Composer package name
- `rhapsody_core` — semver constraint; `ModuleRegistry` skips (doesn't
  fatal) any module that doesn't satisfy the installed core version
- `provider` — FQCN implementing `ModuleServiceProviderInterface`
- `permissions` — closed set of capability keys (`ModuleManifest::CAPABILITIES`).
  Anything not on that list is a validation error, not a silent ignore.

## Runtime flow
1. `bootstrap.php` STEP 2.6, after the container is fully built and before
   routes load: `ModuleRegistry::bootAll()`.
2. For each installed module: validate manifest → check core-version
   compatibility → instantiate the provider → build a `ModuleContext`
   scoped to that module → call `provider->boot($context)`.
3. A module that throws during boot, fails compatibility, or has a broken
   manifest is skipped and logged — it never takes down the rest of the
   app (same philosophy as `EventDispatcher::dispatch()` already uses for
   listener failures).

## ModuleContext — the only thing module code touches
Never the raw container. Five facades, each independently re-checking
`ModulePermissions` before doing anything:

| Facade | Permission required | What it restricts |
|---|---|---|
| `events()->listen()` | `events.listen` | only event classes in the manifest's `listen` whitelist |
| `routes()->get/post/...()` | `routes.register` | namespaced under `/{prefix}/...`, prefix defaults to the module slug — no forced literal segment (e.g. no mandatory `/modules/`) |
| `routes()->root()` | `routes.register` | exact, unprefixed path — only if it's in the manifest's `routes.register.paths` whitelist (e.g. `/sitemap.xml`) |
| `twig()->addExtension/addFunction()` | `twig.extensions` / `twig.functions` | function names auto-prefixed `mod_{slug}_...` |
| `storage()->put/get/delete()` | `storage.access` | hard-confined to `storage/modules/{slug}/`, path traversal rejected |
| `settings()->get/set()` | `settings.manage` (writes only) | JSON file scoped to the module; reads always allowed |

Reaching for anything not granted throws `ModulePermissionException` —
loud and immediate, not a silent no-op, so this fails fast in the sandbox
test stage rather than surprising anyone in production.

## What this buys the marketplace review pipeline
- **Manifest lint** (schema validation) catches malformed/over-broad
  requests in milliseconds, no code execution needed.
- **Static analysis** can flag any module source that imports
  `Rhapsody\Core\Container`, raw PDO, or anything outside `ModuleContext`
  and its facades — that's a structural violation, not a judgment call.
- **Sandbox dynamic testing**: install + boot in an ephemeral container,
  assert no `ModulePermissionException` was thrown and no filesystem
  writes landed outside `storage/modules/{slug}/`.
- Anything touching **payment/webhook-adjacent territory** doesn't have a
  capability in v1 at all — deliberately not exposed yet, so those modules
  either don't exist or get hard-routed to manual review by category.

## Deliberately out of scope for v1 (flag for follow-up)
- **Database migrations for modules** (a module owning its own
  `mod_{slug}_*` tables) — real modules will need this; needs a scoped
  `Migrator` facade + Phinx integration, bigger piece of work.
- **`middleware.register`** capability — global middleware is too powerful
  to hand out yet; route-level middleware scoped to a module's own routes
  is the safer version to design later.
- **CLI lifecycle commands** (`module:install`, `module:uninstall`,
  `module:list`) to actually invoke `ModuleServiceProviderInterface::install()/uninstall()`
  — right now only `boot()` is wired into the request lifecycle.
- **Settings admin UI** generated from `settings_schema` — schema exists,
  renderer doesn't yet.
