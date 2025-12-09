# Mini: A Different Kind of PHP Framework

### *Fast. PHP-first. Zero Runtime Dependencies. Designed for Decades.*

Modern PHP frameworks tend to fall into two categories:

1. **Heavy, scaffolding-driven ecosystems** (Laravel, Symfony)
2. **Minimalist microframeworks** (Slim, Lumen) — usually incomplete without dozens of addons

Mini deliberately rejects both extremes.

It is a **full-stack micro-framework** with:

* **Bootstrap time under 2ms** (measured on typical PHP-FPM, faster with aggressive OPcache)
* **Zero runtime dependencies** (only PSR interfaces + ICU polyfills)
* **Pure PHP architecture** — everything is plain, readable, inspectable PHP
* **PSR-7/15/17 compatible** while embracing `$_GET`, `$_POST`, `$_SERVER` as first-class citizens
* **Fiber-aware async abstractions**, suitable for Swoole/ReactPHP/Phasync long-running servers
* **Decades-long design horizon** (SQL, PSR, immutability, explicitness)

Mini is designed to run today, tomorrow, and 20 years from now.

---

## Why Mini Exists

### The PHP Framework Landscape Has Become a Forest

Most frameworks today:

* Ship **hundreds of dependencies**
* Enforce **opinionated scaffolding**
* Require heavy bootstrapping to achieve simple tasks
* Encourage magic configuration, reflection hacks, or runtime discovery
* Depend on large historical codebases that must remain backward compatible forever

Mini takes the opposite approach:

> **Everything should be simple enough to read in one sitting,
> useful enough for real applications, and stable enough to last decades.**

---

## Key Differences

### 1. Fast by Design (<2ms bootstrap)

Mini uses:

* No autoloaded config forests
* No "service provider" discovery
* No heavy container compilations
* No runtime reflection tricks
* No bootstrapped ORM or queue system unless you choose to

Almost every core system is a **single clean file or namespace**, loading only what is necessary.

Under OPcache, a full Mini application typically cold-boots in **1–2 milliseconds**.

---

### 2. Pure PHP Everywhere

Mini does not invent a DSL.
Mini does not invent configuration languages.

Every subsystem is:

* Pure PHP
* IDE-friendly
* Static-analysis friendly
* Fully testable
* Explicit and inspectable

Example route:

```php
// _routes/users.php
return fn() => User::mine()->limit(20);
```

Example template:

```php
<?php $extend('layout.php'); ?>
<?php $block('content'); ?>
    <h1>Hello <?= h($user->name) ?></h1>
<?php $end(); ?>
```

Example async code:

```php
$result = async()->run(fn() => doExpensiveWork());
```

Mini avoids cleverness in favor of **boring, reliable, portable PHP**.

---

### 3. Zero Runtime Dependencies (Except PSR Interfaces + Polyfills)

Mini depends only on:

* `psr/http-message`, `psr/http-factory`, `psr/http-server-*`
* `psr/log`, `psr/container`, `psr/simple-cache`
* Symfony ICU polyfills

Everything else — router, templates, mail system, UUID, cache layer, validator, metadata, i18n, DB abstraction — is **in-house** and designed as small, self-contained modules.

This gives Mini a uniquely clean dependency graph:

* Safe for long-running servers
* No Composer conflict hell
* Easy to embed inside other frameworks or host other frameworks inside Mini

---

### 4. A PSR-15 Application Host

#### *Run other frameworks inside Mini — completely isolated.*

Mini can mount any PSR-15 compatible application:

```php
// _routes/api/__DEFAULT__.php

require __DIR__ . '/path/to/slim/vendor/autoload.php';

$app = Slim\Factory\AppFactory::create();

// ... define Slim routes ...

return $app;  // Slim handles everything under /api
```

Because Mini has **no hard runtime dependencies**, you can have:

```
mini-app/composer.json
api-app/composer.json
```

completely separate. They don't conflict. You don't need to install Slim into your Mini application.

Mini becomes a **framework host**, not just a framework.

This enables:

* Independent microservices mounted in one Mini instance
* Versioned subapps (`/v1`, `/v2` each with their own composer.lock)
* Blue/green deployments by swapping which subapp is mounted
* Seamless rewriting, fallback routing, or hybrid architectures

---

### 5. Async-Ready for Swoole, Phasync, ReactPHP

Mini is natively fiber-aware, but it does **not** impose an async runtime.
Instead, it defines a clean adapter interface:

```php
interface AsyncInterface
{
    public function run(Closure $fn, array $args = [], ?object $context = null): mixed;
    public function go(Closure $coroutine, array $args = [], ?object $context = null): Fiber;
    public function await(Fiber $fiber): mixed;
    public function sleep(float $seconds = 0): void;
    public function awaitStream($resource, int $mode): mixed;
    public function defer(Closure $callback): void;
    public function handleException(\Throwable $exception, ?Closure $source = null): void;
}
```

This lets Mini run in:

* **Swoole**
* **Phasync**
* **ReactPHP**
* **RoadRunner**
* **Traditional PHP-FPM**

Mini's async model:

* Uses **Fibers**, not generators
* Works if no event loop exists (falls back to `usleep()` / `stream_select()`)
* Avoids locking you into any particular runtime
* Replaces `$_GET`, `$_POST`, `$_SERVER` with proxy classes implementing ArrayAccess for fiber context switching

Mini behaves like libraries in Go or Rust:
**async is an implementation detail**, not a framework religion.

---

### 6. Designing for Decades

Mini intentionally chooses technologies with proven longevity:

#### SQL

40+ years old, still the universal language of data, still the only sane long-term choice.

Mini's DB layer uses:

* Explicit SQL with composable query building
* Repository pattern (`Users::save($user)`, `Users::delete($user)`)
* Optional Active Record pattern (`$user->save()`, `$user->delete()`)
* Strong typing with automatic hydration
* Converter-based type mapping
* Zero magic

#### PSR standards

PSR-7, PSR-15, PSR-17 — standards that will be around long after every current framework is rewritten.

Mini models **e-mail** as actual MIME tree structures implementing PSR MessageInterface because RFC 5322 + MIME is permanent internet infrastructure.

#### Immutability & functional style

All userland constructs are **immutable** unless mutation is truly necessary (path registries, container wiring during bootstrap).
This yields:

* safer concurrency
* easier debugging
* more predictable runtime
* compatibility with async runtimes
* fewer accidental side effects

#### Fail Fast / Fail Loud

Mini does not guess.
If ambiguity exists, Mini throws.

Examples:

* Route handlers must *return something meaningful* or produce output — silent no-ops are errors.
* Services cannot be added once the framework is in the Ready phase.
* Path registries must resolve real filesystem locations immediately.
* The router refuses ambiguous patterns.
* UUID factories must always produce valid UUIDs.

Implicit behavior is only allowed if it is **100% safe in every environment**.

---

### 7. Repository Pattern First (but Active Record if you want it)

#### Preferred style: repositories + POPOs

```php
$user = Users::find($id);
$user->email = 'new@example.com';
Users::save($user);
```

Repositories are simple PHP classes using `RepositoryTrait` — no magic, no hidden queries.

#### Optional: Active Record

```php
$user = User::find($id);
$user->email = 'new@example.com';
$user->save();
```

Available via `ModelTrait` for entities that prefer instance methods.

---

### 8. Mini Is Full Stack — Without Bulk

Mini includes:

* PSR-7/15/17 HTTP implementation
* File-based router with pattern matching
* PHP template engine with inheritance
* ICU-based i18n with MessageFormatter
* JSON-Schema validator
* JSON-Schema metadata annotation system
* RFC 5322 email composer (MIME trees + streaming)
* Zero-dependency caching with APCu support
* PSR-3 logger with ICU message formatting
* UUID v4 + v7 generation
* Async abstraction layer
* Static file server
* CLI tooling
* Event dispatchers, state machines, filters, handlers
* Path registry system
* Authentication utilities

Everything is:

* standalone
* PSR-compatible
* minimal
* fully unit-tested
* bootstrapping-free

Mini is closer to **Ruby's Rack**, **Go's stdlib**, or **Rust's ecosystem** than to modern, monolithic PHP frameworks.

---

## Summary

Mini is different because:

| Feature                  | Mini                                 | Typical Framework           |
| ------------------------ | ------------------------------------ | --------------------------- |
| Bootstrap time           | **<2ms**                             | 30–150ms                    |
| Dependencies             | **PSR interfaces + polyfills only**  | 50–200 packages             |
| Configuration            | **Pure PHP**                         | YAML/XML/Annotations/Magic  |
| Routing                  | **Files + PSR-15 handlers**          | Compiled maps / annotations |
| Templating               | **Native PHP**                       | Custom DSL                  |
| Async model              | **Fiber-aware adapter**              | Hardbound or absent         |
| Architecture             | **Immutable + explicit**             | Mutable + implicit          |
| Philosophy               | **Designed for decades**             | Designed around trends      |
| Can host sub-frameworks? | **Yes, with separate composer.json** | Rarely feasible             |

Mini is not a competitor to Laravel or Symfony.
It is a **different category**:

> **A complete framework that remains tiny, explicit, composable, async-ready,
> and designed to still make sense in 2040.**

---

## See Also

* **[WHY-MINI.md](WHY-MINI.md)** — Detailed comparison with Laravel, addressing common concerns
* **[README.md](../README.md)** — Getting started guide
* **[REFERENCE.md](../REFERENCE.md)** — Complete API reference
