# Mini Framework — Claude Code Quick Reference

A full-featured and powerful zero dependencies PHP framework.

## Positioning: a forkable core

Mini is a **core framework**, designed to sit underneath a more complete, opinionated "Maxi"-style framework — or to be forked outright by a business that wants a foundation it can own and maintain for a decade without third-party abandonment risk. Consequences:

- **Design questions are answered by asking: "does this belong in Mini core, or in a Maxi layer on top?"** Generic building blocks (routing, HTTP, SQL, auth contracts, events, validation) belong in Mini. Opinionated conveniences (e.g. a `GenericCRUDAPI` class, scaffolding, admin UIs) belong in a layer on top — do not add them here.
- **Lindy APIs.** Prefer designs that have survived a long time (PSR, MIME, SQL, LINQ-inspired immutable composition) over trendy framework idioms (no Eloquent/Doctrine-style magic).
- **The async future is PHP Fibers** (phasync-style runtimes): no shared mutable state except immutable config, nothing SAPI-bound. This is why direct output from routes throws.

Breaking changes are recorded in `CHANGE-LOG.md` — check it when behavior differs from what you remember, and expect the orchestrating developer to maintain it.

## Core facts

1. Templating. Mini has pure-PHP templates with multi-level inheritance modelled after .NET Core. ($this->extend('layout'), $this->block('content')). 
   Use `mini\render('view', [...])` and the inheritance methods.
2. Service access via typed helpers. `db()`, `cache()`, `auth()`, `mailer()`, `t()`, `fmt()`, `request()`, `render()`, `log()` are plain functions in the mini
   namespace. They are convenience methods for fetching services from mini's service container (`Mini::$mini->get($id)`). There is no `session()` helper —
   `$_SESSION` (a fiber-safe proxy) auto-starts on access.
3. Make applications translatable by wrapping text in `t("Hello {name}", ['name' => $n])` which uses ICU MessageFormat from intl extension, with 
   pluralization  etc. 
4. Escape output with `mini\h($str)` — an `htmlspecialchars(ENT_QUOTES, 'UTF-8')` shortcut defined in the autoloaded `functions.php`. It is namespaced, so in global-namespace templates either `use function mini\h;` or call `\mini\h()`; bare `h()` is not framework-provided there. `htmlspecialchars()` always works.
5. Mini uses chain-of-responsibility handlers, not policy classes (Laravel) or voters (Symfony). Registration:
   $auth->for(Post::class)->listen(fn($q) => ...). Field-level authorization (can(Ability::Update, $post, 'fieldName')) is built in.
6. The Unsafe suffix. Model::save() is auth-checked + row-scoped; Model::saveUnsafe() is final and bypasses both guards. Default to using the safe version.
7. Configuration of services is per-FQCN files, example: _config/<vendor>/<Module>/<Interface>.php files that return the implementation. 
8. Globals are proxies. $_GET, $_POST, $_COOKIE, $_SESSION are ArrayAccess over the PSR-7 request. They look like the native superglobals and read like them,
   but write semantics flow through PSR-7 immutability. Direct mutation ($_SESSION['x'] = 'y') appears to work but the proxy contract is subtle.
9. Typed parameter injection. In closures and controller methods, parameters named `$_0`, `$_1`, ... receive positional URL wildcards (type-coerced for builtins).
   Class-typed parameters resolve from the container; `ServerRequestInterface` receives the current request. Other parameter names match against request attributes.
10. `mini\Http\ResponseAggregate`. A class implementing `getResponse(): ResponseInterface` can be returned from a route file in place of a `ResponseInterface`.
    Avoids forcing inheritance from `Response`/`HtmlResponse` or implementing the full PSR-7 contract on a wrapper.

## Verify before assuming

Mini is more complete than the documentation lets on. Before claiming a feature is missing, reinventing one, or reaching for `composer require`: **read the relevant `src/<Module>/README.md` and its source.** Things that commonly surprise:

- `src/Hooks/` provides full WordPress-action and WordPress-filter equivalents (`Event`, `Trigger`, `Handler`, `Filter`, `PerItemTriggers`, `StateMachine`) — typed and generic.
- `src/Database/Virtual/` is a federated SQL engine with SQL:2003 coverage (CTEs, recursive CTEs, joins, subqueries, window functions, set algebra) — not just a testing tool.
- `src/Validator/` + `src/Metadata/` together cover JSON Schema validation *and* annotation export.
- `src/Mail/` is a from-scratch RFC 5322 implementation with streaming MIME — not a wrapper around Symfony Mailer.

## Dependencies

Mini has zero non-PSR dependencies. It *provides* implementations for five PSR contracts (`container`, `http-message`, `http-client`, `simple-cache`, `log`). Before adding a dependency, check whether Mini already covers it.

## Fail Fast, Be Strict

Prefer strictness everywhere. Fail fast and expose potential errors early. Don't be "smart" by guessing what the user meant — require explicit, correct input.

**Example:** `AliasTable` only accepts aliased column names (`users.id`), not original names (`id`). If someone passes `id` to an aliased table, it throws immediately rather than guessing they meant `users.id`.

When encountering a fail-fast exception, the correct response is usually to fix the calling code, not to relax the validation.

## Application structure

1. The host application has its own `composer.json` with PSR-4 autoload and an optional `bootstrap.php` declared in `autoload.files`.
2. Group features as **aspect bundles** under `aspects/<name>/`. Each aspect is a real Composer package — own `composer.json`, PSR-4 namespace, auto-generated `_bootstrap.php` — and can be extracted to a standalone package later with no code changes. Aspects contribute routes, views, static, config, translations, and migrations via path-registry overlays.
3. Use `vendor/bin/mini aspects` to scaffold and sync aspects. Don't hand-write `composer.json` or `_bootstrap.php` for an aspect — let the tool generate them. If an aspect needs custom bootstrap code, create a sibling `bootstrap.php` (no underscore) — it's auto-included.

## Routing

Filesystem-based: `_routes/<path>.php` maps to URL `/<path>`. Wildcards: `_.php` matches a single file segment, `_/` matches a directory segment. Captured values land in `$_GET[0]`, `$_GET[1]`, ... (nearest wildcard at index 0).

Route files **must** return one of:

- A `Psr\Http\Message\ResponseInterface` (e.g. `mini\Http\Message\JsonResponse`, `HtmlResponse`, `FileResponse`, `Response`).
- A `mini\Http\ResponseAggregate` — a class with `getResponse(): ResponseInterface`, resolved lazily before dispatch continues.
- A `Closure` — invoked with **typed parameter injection** (see below); its return value is converted via the converter registry (e.g. a plain array → `JsonResponse`).
- A `Psr\Http\Server\RequestHandlerInterface` — sub-router for a subtree, typically from `__DEFAULT__.php`.

Anything else throws a `RuntimeException`: no `header()`/`echo` (direct output during route file inclusion throws), no returning nothing, no returning scalars/arrays from the file itself — "return data" belongs in Closure and controller-method return values. Direct output ties an application to SAPI runtimes and is not portable to coroutine runtimes. Route files should handle dependency lookup and parameter mapping; business logic belongs in the handlers they delegate to.

### Typed parameter injection (Closure / controller methods)

When a route returns a `Closure` (commonly via PHP's first-class callable syntax `Method(...)`), parameters resolve automatically:

- `$_0`, `$_1`, ... → positional URL wildcards (type-coerced for `int`/`float`/`bool`/`string`).
- Class-typed (e.g. `ServerRequestInterface`, container-resolvable services) → injected by type.
- Other parameter names → matched against request attributes.

The same mechanism drives attribute-based controllers in `src/Controller/`.

### Route examples

```php
// _routes/api/ping.php — trivial, no business logic
<?php return new mini\Http\Message\JsonResponse('pong');
```

```php
// _routes/wiki/_.php — business logic delegated; route just wires args
<?php return new MarkdownPage($_GET[0]);
// MarkdownPage implements ResponseInterface or mini\Http\ResponseAggregate.
```

```php
// _routes/api/orders/_.php — typed injection, container resolves the service
<?php return Mini::$mini->get(OrderController::class)->show(...);
// OrderController::show(int $_0, OrderRepository $repo): ResponseInterface
```

```php
// _routes/api/users/__DEFAULT__.php — sub-router for the /api/users/* subtree
<?php return new UserCRUDApiController(Mini::$mini->get(DatabaseInterface::class));
// Either a class extending mini\Controller\AbstractController, or any class
// implementing Psr\Http\Server\RequestHandlerInterface (e.g. a mounted Slim app).
```

## Database

Mini is **SQL-first**. The immutable `mini\Database\PartialQuery` is the primary query builder. Because each method returns a new instance, composition is safe and it doubles as an access-control primitive: pass a `PartialQuery` into a template instead of materialising rows, and downstream code cannot widen the result set — it can only narrow.

`mini\Database\Model` is the Active Record base. It splits methods into two layers:

- `query()` / `find()` / `save()` / `delete()` — auth-checked via `can()`, row-scoped via `updatable()` / `deletable()`.
- `queryUnsafe()` / `findUnsafe()` / `saveUnsafe()` / `deleteUnsafe()` — `final`, raw, no guards.

Override `query()` to scope rows by user/tenant; the write guards inherit that scope automatically.

`mini\Database\VirtualDatabase` is a full federated SQL engine. It can register any `TableInterface` (CSV, JSON, remote API, another `PartialQuery`, etc.) as a virtual table and execute SQL across heterogeneous sources. Supports `queryTimeout` for safely accepting user-provided SQL via an API.

## Validation and metadata

`mini\Validator` is JSON Schema-compatible (`#[Type]`, `#[MinLength]`, `#[Pattern]`, `#[Format]`, `#[Required]`, `#[Enum]`, ...). Validators are immutable, composable, and exportable as JSON Schema for client-side reuse.

`mini\Metadata` is the JSON Schema annotation vocabulary (`#[Title]`, `#[Description]`, `#[Examples]`, `#[IsReadOnly]`, `#[IsWriteOnly]`, `#[Deprecated]`). Together they describe entity contracts once and drive validation, OpenAPI-style generation, and form/admin UI rendering.

## Hooks and events

`mini\Hooks` provides typed event dispatchers for aspect-to-aspect extension: `Event<T>` (multi-fire), `Trigger<T>` (one-time with memory — late subscribers receive the original payload), `Handler<I,O>` (chain of responsibility, first non-null wins), `Filter<V>` (data pipeline), `PerItemTriggers<S,T>`, and `StateMachine` (validated state transitions). Use these instead of inventing extension points by hand.

## Authentication and authorization

`mini\Auth` is a facade — the application implements `AuthInterface` (sessions, JWT, API keys, whatever) and Mini provides `auth()` for checks. Mini does not prescribe a user model.

`mini\Authorizer` is capability-based authorization: `can(Ability::Delete, $post)` at collection, instance, or field level. Handlers register per type via `$auth->for(Post::class)->listen(...)`. Resources can be classes, instances, or string identifiers (e.g. `'reports.financial'`, `'virtualdatabase.<table>'`).

## E-mail

`mini\Mail` is full RFC 5322 with streaming MIME — large attachments don't materialise in memory. Declarative `Email` API, pluggable transports. Send via `mailer()`.

## Working on Mini itself

- Run targeted tests: `php bin/mini test tests/<Module>/` from the repo root.
- Zero-dependency guarantee: never add a non-PSR runtime dependency. Mini provides the implementations.
- Fail fast, be strict, never guess what a caller meant. Don't overengineer; don't underengineer.
