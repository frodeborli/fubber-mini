# Design Rationale

What motivates the design choices behind Mini, and what each choice trades off.

This is not a comparison document. The aim is to make the design intent explicit so that someone evaluating Mini, contributing to Mini, or maintaining a fork of Mini understands which constraints are deliberate and which are accidental.

---

## Zero non-PSR dependencies

**The fact.** Mini's `composer.json` requires `php >=8.3`, the seven `psr/*` interface packages, and two conditional `symfony/polyfill-intl-*` shims that activate only when PHP's `intl` extension is missing. Mini *provides* implementations for five PSR contracts (`container`, `http-message`, `http-factory`, `http-client`, `simple-cache`).

**What this motivates.**

- The audit surface for the framework is Mini's own source. There is no transitive supply chain to track for advisories.
- Upgrading Mini reconciles one package, not a dependency tree. There is no "is this our bug or upstream's" investigation when something breaks.
- An aspect's `composer.json` requiring `fubber/mini` does not drag in eighty transitive packages. Aspect installation is a single package.
- The Packagist ecosystem is reachable from a Mini application without conflict. Eloquent, Doctrine, Symfony Mailer, Carbon, Guzzle, Stripe SDK, AWS SDK — any PSR-compatible package can be composed into a Mini app or an aspect. Mini does not compete with these packages; it provides the substrate they run on.

**The tradeoff.** Functionality that other frameworks import (mailer, ORM, validator) is implemented inside Mini. This is more code in Mini's source than in a wrapper-style framework, in exchange for the dependency-tree properties above. The trade is accepted because the implementations are small (the entire framework is ~64K LOC) and because PSR compatibility means a developer who prefers Doctrine or Eloquent over Mini's data layer can still use them.

---

## Engine-native over userland reimplementations

**The fact.** Mini uses PHP's C-level engine features where they exist: the `intl` extension for ICU MessageFormatter and number/date/currency formatting, PDO for database access, `\Locale::setDefault()` for per-request locale switching, the filesystem itself as the routing table, plain PHP files as templates.

**What this motivates.**

- These engine features have stable APIs measured in decades. `intl` has been stable since PHP 5.3 (2009). PDO since 5.1 (2005). `\Locale` since 5.3. Mini's API surface inherits this stability — code written against Mini's `t()` or `fmt()` does not break when the framework version changes, because the framework is a thin wrapper over an engine API that does not change.
- Performance bottoms out at the engine, not at userland. ICU translation through `MessageFormatter` is C code; userland-parsed translation arrays are PHP.
- A developer's existing PHP knowledge transfers. There is no framework-specific equivalent of well-known engine features to learn.

**The tradeoff.** Mini requires the `intl` extension for full functionality (the polyfills cover partial functionality when intl is missing). Requires PDO for the default database driver. These are universally available in production PHP environments but represent a stricter runtime requirement than frameworks that ship pure-PHP fallbacks.

---

## Filesystem as the routing table

**The fact.** URL paths map directly to `_routes/` files. `_routes/users/_.php` matches `/users/<anything>` and captures the segment. Composite handlers can be mounted via `_routes/users/__DEFAULT__.php` returning a controller or PSR-15 handler.

**What this motivates.**

- The URL structure of an application is visible at `ls _routes/`. There is no separate routing table to consult and no compilation step to rebuild after edits.
- The OS kernel's file metadata cache handles the lookup, which is faster than any userland regex-based router can be.
- Renaming a file renames the route. Removing a file removes the route. No state to maintain elsewhere.

**The tradeoff.** Applications whose URLs do not map cleanly to a hierarchical filesystem layout (URL-as-token services, hash-based dispatch) are awkward in this paradigm. For those, attribute-based routing on controllers, or returning a PSR-15 handler from a route file, provides escape hatches. The filesystem paradigm is the default because it serves the majority case; the alternatives exist for cases where it does not fit.

---

## Lazy loading throughout

**The fact.** A "Hello World" Mini application uses approximately 300KB of memory. ORM, mailer, validator, i18n, sessions, auth — none of these load until the application touches them. Service definitions are closures that resolve on first access. Route files are read only when the URL matches them.

**What this motivates.**

- The framework scales from a single endpoint to a full application platform without architectural changes. A small service does not pay for features it does not use.
- Composer's autoloader and the OS filesystem cache are the only caches that matter. There is no framework-level compilation step that needs to be invalidated.
- Cold-start time is roughly proportional to what the request actually needs, not to the framework's installed feature set.

**The tradeoff.** Some indirection between code paths — for instance, `db()` resolves through the container on first call. This indirection is the cost of lazy loading. The cost is small enough that it has not measurably affected benchmarks.

---

## PSR compatibility — consumer and provider

**The fact.** Mini's `require` section lists seven PSR interface packages, and its `provide` section declares implementations for five of them. The framework consumes PSR contracts internally and provides PSR contracts externally.

**What this motivates.**

- Any PSR-15 middleware or `RequestHandlerInterface` can mount inside a Mini route file. Slim, Mezzio, or any other PSR-15 app can be embedded.
- Any PSR-compatible package on Packagist works in a Mini application. Mini does not compete with the ecosystem; it sits beneath it.
- Application code that talks to PSR interfaces (`ServerRequestInterface`, `ResponseInterface`, `ContainerInterface`) is portable. An application built on Mini that one day needs to leave Mini can swap in any other PSR-compatible implementation without rewriting handlers.

**The tradeoff.** PSR's design choices (immutable Request/Response, header normalization, stream semantics) are baked into Mini. A developer who finds PSR-7's immutability inconvenient is still in PSR-7 land.

---

## Aspects as Composer packages

**The fact.** Modular contribution in Mini happens through "aspects" — Composer packages (typically under `aspects/` via Composer path repositories) that contribute routes, views, static assets, config, translations, migrations, and PHP code via PSR-4 + `autoload.files`. The `mini aspects` CLI scaffolds the boilerplate.

**What this motivates.**

- An aspect is structurally a real Composer package from day one. Extracting an aspect into a standalone published package later is a configuration change (move directory, change version constraint), not a refactor.
- Aspects integrate via standard PHP mechanisms — Composer's autoloader, PSR-4 namespacing, `autoload.files` for bootstrap — not via a Mini-specific module registration API.
- Inter-aspect dependencies are declared in each aspect's `composer.json` and resolved by Composer. The host application does not need to know that aspect A depends on aspect B.
- The `paths` registries (`paths->routes`, `paths->views`, `paths->static`, etc.) allow each aspect to contribute resources at first-class registry paths. Host-app resources take precedence; aspect resources fill gaps.

**The tradeoff.** Each aspect carries Composer ceremony: a `composer.json`, an `_bootstrap.php` (auto-generated by `mini aspects`), and a require entry in the host. The ceremony is small and partially automated, but it exists. For one-off endpoints the host's `_routes/` directory is fine; aspects are the right unit when a feature has more than a single file.

---

## Vertically integrated scaling stack

**The fact.** Mini is one layer of a three-layer stack designed by the same author:

- **Mini** (framework) — what you write your application against.
- **phasync** (coroutine library) — fiber-based concurrency primitives.
- **Swerve** (application server) — coroutine-aware SAPI designed to run Mini applications with many concurrent long-lived requests per process.

The fiber-safe `$_GET`/`$_POST`/`$_COOKIE`/`$_SESSION` proxies, the streaming `HttpDispatcher` with its read-driven chunk loop, the immutable query builder, and the path registries are all designed to function correctly under coroutine scheduling.

**What this motivates.**

- The path from a small PHP-FPM application today to a high-concurrency long-lived-process application tomorrow does not require rewriting handlers. Code that returns a `ResponseInterface` and uses the fiber-safe globals moves to Swerve without changes. Code that `echo`s and `header()`s does not move — this is why the documentation distinguishes the two paradigms.
- The scaling story is owned end-to-end. Mini does not depend on the PHP ecosystem to deliver a coroutine runtime; the runtime is being built alongside.

**The tradeoff.** Swerve is not yet released. The fiber-safe-globals story is currently a promise more than a demonstrated production behavior. Mini today, on FPM, behaves like any well-built PHP framework. Mini under Swerve is the design target, not the current state.

---

## Small enough to fork

**The fact.** Mini's `src/` directory is approximately 64,000 lines across 327 PHP files. The framework has zero non-PSR dependencies. The PSR-7 interface boundary is the application's actual contact with the framework. The Lindy stance ensures the framework's bets are on patterns that survive without maintainer attention (filesystem routing, intl extension, plain PHP templates).

**What this motivates.**

- Continued existence of a Mini-based application does not depend on continued maintenance of Mini by its original author. A motivated single developer can read and maintain the entire framework.
- A fork does not drag in transitive dependencies whose own maintenance status would need to be tracked.
- The exit cost from Mini is bounded. Application code talks to PSR interfaces; if a fork becomes untenable, swapping in another PSR-compatible framework is feasible without rewriting the application logic.

**The tradeoff.** 64K LOC is small for a framework with this much functionality (full RFC 5322 mail with streaming MIME, federated SQL engine, JSON-Schema validator + metadata, AST-based query parser, attribute-based and filesystem routing, sessions, hooks, paths, services, auth/authorizer, cache, i18n, migrations, CLI). Small does not mean unfinished — it means dense. Reading the source is part of the supported experience.

---

## What Mini is positioned for

The design choices above compose into a framework with a specific intended use:

**Applications that you expect to run for a long time.** The longevity argument — engine-native APIs, zero non-PSR dependencies, PSR exit paths, fork-survivable — pays off at scale of years, not weeks.

**Applications where the architecture should be driven by the domain.** Mini does not provide a User model, an authentication scheme, a content type system, or any other domain-specific opinion. It provides infrastructure primitives. This is a strength when the application has its own architectural shape (wiki, CMS, CRM, social network, ticketing system, multi-tenant SaaS) and a weakness when the application would benefit from inheriting an opinionated architecture.

**Applications built by developers who know what they want.** A team that has answered "how should our auth work, how should our data layer look, how should our routes be organized" benefits from a framework that does not impose other answers. A team without those answers benefits from a framework that provides them — Laravel, Symfony, and Rails are excellent at this.

**Applications that may grow beyond PHP-FPM.** The vertical stack with phasync and Swerve targets workloads that PHP-FPM cannot handle efficiently — SSE, WebSockets, long-poll feeds, real-time updates, high-fan-out reads. Today, Mini runs on FPM. Tomorrow, the same code runs on Swerve.

---

## What Mini is not positioned for

**Tutorial-driven onboarding.** Mini does not ship with a "build a blog in 15 minutes" track because the framework does not assume what a blog should look like. A junior developer benefits more from Laravel's opinionated architecture education than from Mini's primitives.

**Pre-built admin UIs.** Mini provides validator + metadata that can drive form generation, but there is no ready-made admin scaffolding. An application that wants an admin UI builds one (or uses an aspect for it).

**Ecosystem-of-a-thousand-tutorials energy.** Mini's documentation, while thorough per module, does not have the depth of community content that frameworks with a decade of head start have. This will change with adoption; it is not where the framework is today.

---

## Summary

| Choice | Motivation | Cost |
|---|---|---|
| Zero non-PSR dependencies | Audit surface = framework source; no supply chain | Implements more in-source than wrapper-style frameworks |
| Engine-native APIs | Stability horizon = PHP's stability horizon | Requires intl/PDO extensions |
| Filesystem routing | Visible URL structure, kernel-cached lookup, no compilation | Awkward for non-hierarchical URLs (attribute routing exists as escape hatch) |
| Lazy loading | Scales from prototype to platform without rewrite | Some container-resolution indirection |
| PSR-everywhere | Composable with the entire Packagist ecosystem | PSR design choices (immutability, etc.) baked in |
| Aspects as Composer packages | Modules are standard PHP packages; extraction is config-only | Per-aspect ceremony (mitigated by `mini aspects`) |
| Vertical stack with phasync/Swerve | Scaling path owned end-to-end | Swerve not yet released |
| ~64K LOC, no transitive deps | Forkable by one developer; survives author absence | Less feature surface than batteries-included frameworks |

Each row is a deliberate choice with a known tradeoff. The framework is consistent in its choices — every choice in column 2 reinforces every other choice in column 2. That consistency is what makes the design coherent rather than a collection of features.
