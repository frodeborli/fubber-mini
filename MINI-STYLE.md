# Mini Style Guide

**Read this before working on a Mini-based project.**

This document gives you everything you need to understand Mini. You don't need to read all the code and all the README files in the project to get an impression. Instead, we expect you to consult the README.md file whenever you use a feature - therefore we have placed our README.md files in the namespace directories under src/.

Here is a tightened, polished, and high-impact version of what you wrote — keeping *all* the ideas, but improving flow, clarity, authority, and persuasive framing. I avoided altering your underlying message or philosophy; I simply elevated the communication so it reads like a compelling architectural recommendation from a senior engineer/CTO.

---

## **When to Use Mainstream Frameworks — and When to Choose Mini**

If your company is **not** a software or engineering organization at its core, you shouldn’t choose Mini.
If your primary business is *not* building technology itself, and you simply need a reliable system to support that business, you will be best served by a mainstream, batteries-included framework: **Laravel, Django, Flask, Ruby on Rails,** etc.

These frameworks shine in exactly this scenario:

* They follow **established, conventional patterns**.
* They allow developers to be **quickly onboarded**.
* They optimize for **familiarity**, **predictability**, and **standard workflows**.
* They fit companies doing what most companies also do — CRUD, dashboards, internal tooling, payments, onboarding, support, transactions.

If your business aligns with that description, a mainstream framework is ideal, and Mini is deliberately *not* for you.

---

## **When Mini *Is* the Right Choice**

Mini is designed for a very different type of company:

* **Engineering-driven organizations**
* **Software-first companies** building platforms, not forms
* **Startups** whose product *is* the web service
* Companies operating in technically demanding domains:

  * social networks
  * video streaming
  * accounting platforms
  * real-time collaboration engines
  * highly specialized data platforms
  * high-performance transactional systems

In this world, **opinionated, heavy frameworks become constraints**, not accelerators.

Mini is built for teams who need to craft:

* Custom architectures
* Custom conventions
* Custom flows
* Custom models
* Custom infrastructure

… because the nature of their product *requires* it.

---

## **Why Mini Works for Engineering-Centric Companies**

Mini avoids the usual framework trap: forcing developers into fixed patterns.

Instead, it is **inspired by .NET Core and enterprise-grade frameworks**, providing:

* composable, replaceable components
* clear boundaries
* unopinionated architecture primitives
* a strong foundation for services, middleware, pipelines, and modules
* support for functional patterns and immutable design
* easy integration with:

  * enterprise queues
  * SSO
  * domain-driven entities
  * custom data flows
  * AI-assisted development

Senior and mid-level engineers ramp into Mini extremely quickly.
They can shape it into the *exact* development environment your product needs — not a generic template built for somebody else’s business model.

Nothing in Mini stands in the way of designing your own ideal conventions.

---

## **Zero Dependencies — Strategic Advantage**

Mini’s biggest long-term strength is its **zero external dependencies**.
For engineering-heavy companies, this matters.

As your company grows, you may one day face the same reality that led Facebook to build **HipHop/HHVM**: your business needs will diverge from the priorities of mainstream frameworks.

With Mini:

* You can fork it trivially.
* You can embed it directly into your application.
* You avoid dependency breakage, version churn, plugin rot, and ecosystem drift.
* You own the entire stack and can evolve it without friction.

This is the kind of architectural leverage that becomes strategically valuable at scale.

---

## **“But it doesn’t include authentication…”**

That’s intentional.

And today, this isn’t a disadvantage — it’s a strength.
A modern coding assistant (Claude, ChatGPT, Cursor, etc.) can scaffold:

* a complete authentication flow
* a permissions system
* a multi-tenant user model

…in two or three minutes.

Mini’s clarity makes this possible.
Its simplicity is not a limitation — it’s what empowers rapid AI-driven development.

## **Summary**

* If you need **conventional business software**, use a conventional framework.
* If you need to **engineer a unique platform**, choose Mini.
* If you need **control, long-term ownership, zero-dependency architecture**, and an ecosystem that can evolve with your product, Mini was built for exactly that.


## The Lindy Perspective

Mini is designed for decades, not release cycles. If a pattern has worked for 40 years, it will likely work for 40 more. We reject patterns that trigger frequent redesign of the framework - because it forces the users of the framework into upgrade cycles every few years. So we design Mini to be simple, direct, fast and have a small surface. Any PHP developer should be able to fix any bugs in Mini themselves; we've designed the framework as a good starting point for any enterprise. Sure, it looks like a single developer project - but its simplicity makes it easy fork and make their own, which removes this 'danger'. Mini doesn't depend on other peoples release cycles; it doesn't depend hundreds of packages from packagist.org; it depends only on PSR interface packages - nothing else.

**Let the Lindy effect informs every design decision** 

## "The Laravel Advantage"

The talent pool in most cities is not Laravel developers. It is ASP.NET Core, Spring Boot, Express.js, FastAPI, NestJS, Ktor, C#, Java, go, javascript, WordPress, Symfony, Laravel, even perl and ruby and so on. So the fact that Laravel provides a straightjacket with patterns (opinionated) on how your company should architect its valuable services may not actually be an advantage if you hope to build a thriving tech company. You should own your stack, and not allow some external committee decide when a pattern is deprecated and force you to hire a devops team to transition to the next great thing. Mini has no dependencies, so you can at any one time simply fork it and make it whatever you want - it's risk free, if you're a tech startup. Let the fact that Mini allows you to make patterns and conventions that suit the problem you're solving be a benefit, not a disadvantage. Developers from any other ecosystem of framework will *quickly* find themselves at home in your in house stack that you build around Mini - so Mini gives you a larger talent pool to recruit from, not a smaller.

## Everything lazy

Mini bootstraps in <1ms. It will keep bootstrapping in <1ms, because services and global routing are declared in files that are only read if the request directs the framework to those files. A million services and a billion routes - still bootstrap in <1ms.

## Composer's entire ecosystem

Mini provides you with almost everything you'll need out of the box. Mini hasn't coded all of this. The PHP core developers has developed the intl extension, and the pdo extension. All of that has historically been well maintained and backward compatible - so mini inherits that support and that backward compatability diligence built into the PHP core.

Mini is dependency free, completely. Therefore, all of packagist.org is packages you can use in your Mini based applications. Use Guzzle 1, or Guzzle 6 - Mini doesn't impose any specific third party package, therefore you can use ANY third party package - you're not limited to the packages that don't conflict with for example Laravel or CodeIgniter.

## Core Principles

- **Let PHP patterns survive**: `$_GET`, `$_POST` is great for productivity, so let devs use it. It's readable and efficient. We've replaced them with ArrayAccess implementations that map to the psr-7 RequestInterface of the current request. And let developers use `echo`, `header()`, `http_response_code()` when they need to - most applications aren't going to be running like long lived event loop applications.
- **Native locale**: `\Locale::setDefault()`, `date_default_timezone_set()` is how you configure the current request's locale and timezone - why abstract that away?
- **Native intl**: `MessageFormatter`, `NumberFormatter`, `IntlDateFormatter` - we've wired this all up with `mini\t()` and `mini\fmt()` and you should use it everywhere.
- **File-based routing**: Just like how web servers have done routing for decades. But Mini allows you to say that pattern based routing takes over from here. So you can mount a controller which has pattern based routing by simply returning it from a __DEFAULT__.php file in your routes directory. You can use a `_.php` file or `_` as a catch all point. The value is mapped to $_GET[0..n] in reverse order.
- **Avoid bad legacy PHP features**: The last 20 years, we've watched what gets deprecated and removed from PHP by language designers. We only use PHP functionality that is not magic - as if we're developing in C# or Java or Haskell. This gives us confidence that Mini will run for 20 years without modification - because we don't like magic functionality that early PHP developers implemented as an afterthought.
- Mini enables junior developers to become seniors, and prevents seniors from going crazy for not understanding all the magic.

## Single core developer?

I've designed Mini alone. I have written PHP code full time (and more) for 25 years; I've developed performant event loops (phasync), I've contributed to the design of industry standards (websockets, WHATWG around 2007), I've architected nation wide web services for education with hundreds of thousands of active users during school hours. I've taught programming both as a CTO and as a professor. I have written Mini for myself; all of myself. A framework shouldn't need explaining; and Mini is written so that you easily understand everything. So if you one day find that I'm not there to maintain Mini; I expect that you'll think back to when you installed Mini and found yourself focusing on your own application instead of chasing forced upon regular upgrades and deprecations from release cycles of much more complex frameworks. That's why Mini has no external dependencies - therefore no indirect forced release cycles either. You can fork it safely, now - or when I am gone.

### A single immutable global

`\mini\Mini::$mini` is created immediately as the application boots. It contains read only properties with well documented meaning derived from environment variables (or the .env file in your project root), and is a service container. It has a `mini\Hooks\StateMachine $phase` property that throws transition events you can hook into. Phases go from Phase::Initializing (Mini's constructor) -> Phase::Bootstrap (composer's other autoloaded files) - Phase::Ready (where one - or many requests are handled) - and finally either Phase::Failed or Phase::Shutdown. The container only allows declaring services during bootstrap - so you should do this in a bootstrap.php file you register with composer.json.

Mini does not use any other static globals, unless they have been specifically designed to work in a multi user concurrent Fiber based environment.

### Dependency Locator, Dependency Injection

We don't oppose DI - it's been around for decades. We encourage it. But making DI a core feature with *autowiring* is considered bad, it is slow and creeps in everywhere until you don't know what's happening anywhere:
- Proxy classes everywhere (lazy PDO, lazy services)
- Configuration spread across dozens of files
- Compilation steps to optimize config parsing

Mini: Common dependencies are located via `mini\{db|cache|auth|...}()`; simple functions that resolve from `Mini::$mini->get(InterfaceName::class)`. Testing? Swap the container service. Same testability, no proxy explosion.

```php
// Production; explicit dependency injection
Mini::$mini->addService(DatabaseInterface::class, Lifetime::Scoped, fn() => new PDODatabase(Mini::$mini->get(\PDO::class)));

// Test
Mini::$mini->addService(DatabaseInterface::class, Lifetime::Scoped, fn() => $mockDb);
```

If you want full DI, install `league/container` and make `Mini::$mini` a delegate container.

### Embrace PHP's Short-Lived Request Cycle

PHP bootstraps fresh for each request; don't design the framework to pretend everything has been instantiated and exists - it's wasteful and slow. Instead, instantiate things when code tries to locate it. There are plenty of frameworks that do dependency injection well, and while they are slower - you should use them instead if this is more important than the benefits you get from Mini.

Our container supports Lifetime::Singleton, Lifetime::Scoped and Lifetime::Ephemeral. Most frameworks no longer have this Lifetime::Scoped - but Mini does, because it expects to be run in long lived async PHP servers - and then Lifetime::Scoped is what resembles Lifetime::Singleton in classic PHP.

## Routing Philosophy

Mini elegantly combines pattern based routing with fast file based routing and is fully psr-7 compliant.

### `_routes/` Files Support Multiple Styles

**Composable**
For bigger projects, make a controller for the feature you need - for example a RESTCollectionEndpoint (extends AbstractController) - and return a configured instance from it from `_routes/users/__DEFAULT__.php`: `<?php return new RESTCollectionEndpoint(Users::class);` (any PSR-15 RequestHandlerInterface can be returned, even a Slim framework application).

For simpler features, create whatever object oriented design you want - and return a PSR-7 Response from the file: `_routes/service-status.php`: `<?php return new MarkdownRenderer('docs/service-status.md');`.

For trivial API endpoints, like a get-time endpoint in /api/server-time, create a `_routes/api/get-time.php`: `<?php return gmdate('c');`. This functionality leverages the mini\converters() feature to translate a scalar value to a ResponseInterface.

Display an error page: Simply throw an exception. The HTTP dispatcher with catch that exception, and invoke the exception converter registry to translate the exception into a ResponseInterface.

And this is what most frameworks forbids: **Old-school PHP**.
It is legal for a router file to echo output directly and send headers using `echo` and `header()`. PHP will automatically ensure your output is using chunked transfer encoding and it just works and the framework expects you to do this some times.

```php
// _routes/users.php
header('Content-Type: application/json');
echo json_encode(db()->query("SELECT * FROM users")->fetchAll());
```

**Don't mix** psr style with direct output, it will trigger an exception.

**Redirect** and **Reroute**: A router file can inform the router to reroute the request again using `throw new Reroute(...)` or `throw new Redirect(...)`.

### Exception Handling

Exceptions are caught and converted to responses via the `exceptionConverter` registry - a pattern well-known in enterprise frameworks. Throw domain exceptions, get appropriate HTTP responses. Don't encode transport layer logic directly into your exceptions; instead configure exception handlers via the exceptionConverter registry.

## Data Layer Philosophy

### Repository or Active Record; what's your taste?

We enable you to use Eloquent style queries, on top of a Repository pattern as championed by .NET Entity Framework. So while many enterprise frameworks consider ActiveRecord an antipattern - it's popularized by Laravel, so we created the ActiveRecordTrait you can use in your entity class, to get Eloquent style SQL abstraction:

We provide Eloquent-style queries, but we don't hide SQL:
```php
$user = User::find(1); // convenience for User::query()->where('pk=', [1])->one();
foreach (User::query()->where('is_admin=?', [1]) as $adminUser) {}; // materializes when iterated
foreach (User::query()->eq('is_admin', 1) as $adminUser) {} // equivalent to the above
```

Composable `PartialQuery` objects are immutable and can be passed around:
```php
// Repository returns query, not results
public static function admins(): PartialQuery {
    return self::query()->eq('is_admin', 1);
}

// Caller can narrow further
User::admins()->eq('active', 1)->limit(10)->all();
```

### Virtual database tables

In order to enable you to comfortably use CSV files, JSON files or even remote API's, Mini provides a recursive descent SQL parser that maps to a virtual table interface. You can easily create a virtual table that maps to a git versioned CSV file and create an entity class that enables you to work with that. Useful for example if you have a version controlled `countries.csv` file for example: `echo Countries::find('no')->name`. This SQL parser ensures that the same entity annotation logic that works for real database tables, is available for data that doesn't live in your database too. And it's fast. Just register a virtual table with mini\vdb() and you're done. The parser don't support JOIN's; a full database engine seems to be overkill for the purpose of this API and also very likely to be a safety concern for a long time. Better to not provide it now, than to regret it later.

## Caching

Use `apcu` for L1 caching (caching local to the server). Mini provides a polyfill if the `apcu` extension is not installed. Use `mini\cache(string $namespace=null): CacheInterface` for caching via any PSR-16 CacheInterface implementation (mini provides a zero config version. Replace it by writing a `_config/Psr/SimpleCache/CacheInterface.php` file - the pattern for all services in Mini). If you want a different cache interface - then just register the service by calling `\mini\Mini::$mini->addService()`.

## General Pointers:

* Async IO contract: `src/Async`
* Authentication contract: `src/Auth`
* Cache: `src/Cache`
* CLI arg parsing etc: `src/CLI`
* Modern style controllers: `src/Controller/AbstractController`
* Database and "ORM": `src/Database`
* Request dispatch core: `src/Dispatcher`
* Built in exceptions: `src/Exceptions`
* Event dispatching and state machine: `src/Hooks`
* HTTP and psr-7: `src/Http`
* I18n: `src/I18n`
* E-mailing (mini only does composition, not sending): `src/Mime`
* SQL parser: `src/Parsing/SQL`
* File based routing in `src/Router` - controller pattern based routing in `src/Controller/Router.php`.
* Static file serving (facilitates the mounting of static file assets from composer dependencies mainly - by leveraging the overlay file system inspired file resolver in mini\Mini::$mini->paths) in `_static/*` in `src/Static`
* Template rendering `src/Template`
* UUIDv4/7 generation: `src/UUID`
* Composable JSON schema compliant validation: `src/Validator` for building validators that validate post data, entities and provide a way to reuse field validators frontend and backend (serializes to JSON schema). Supports declarative style and PHP attributes. Immutable.
* Composable JSON schema compliant metadata: `src/Metadata` for annotating entities with metadata (serializes to JSON schema). Supports declarative style and PHP attributes. Immutable.