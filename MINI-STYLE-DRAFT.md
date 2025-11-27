# Mini Framework - LLM Quick Reference

**Read this before working on Mini-based projects.**

## When to Use Mini vs Laravel/Symfony

| Use Laravel/Django/Rails if... | Use Mini if... |
|-------------------------------|----------------|
| Business *uses* software | Business *is* software |
| Standard CRUD, dashboards, forms | Custom platforms, real-time, high-scale |
| Need quick onboarding of junior devs | Have mid/senior engineers who own the stack |
| Want batteries-included conventions | Need to design your own conventions |

**Mini's niche:** Engineering-driven startups building platforms (social networks, streaming, accounting systems, collaboration tools) where mainstream framework opinions become constraints.

## Core Philosophy: The Lindy Principle

Mini is designed for decades, not release cycles. If a pattern worked for 40 years, it stays.

- **Zero dependencies** - Only PSR interfaces. Fork it safely. No forced upgrade cycles.
- **<1ms bootstrap** - Services and routes lazy-load from files only when needed.
- **Native PHP** - `$_GET`, `$_POST`, `echo`, `header()` all work. No magic.
- **Explicit over magic** - No autowiring. Locate dependencies via `db()`, `cache()`, `auth()`.

## Feature Quick Reference

When implementing features, **read the README.md in each `src/` subdirectory**.

| Feature | Location | Key Functions |
|---------|----------|---------------|
| **Routing** | `src/Router/` | File-based: `_routes/users/_.php` catches `/users/{id}` |
| **Database** | `src/Database/` | `db()->query()`, `User::find($id)`, `PartialQuery` |
| **Validation** | `src/Validator/` | `validator()->email()->minLength(5)`, JSON Schema compatible |
| **I18n** | `src/I18n/` | `t("Hello {name}")`, `fmt()->currency()`, ICU MessageFormat |
| **Caching** | `src/Cache/` | `cache('ns')->get()`, `apcu_store()` with polyfill |
| **Auth** | `src/Auth/` | `auth()->login()`, `require_login()`, `require_role()` |
| **Templates** | `src/Template/` | Pure PHP with `$extend()`, `$block()`, `$show()` |
| **SQL Parser** | `src/Parsing/SQL/` | Virtual tables for CSV/JSON with SQL interface |
| **Email** | `src/Mime/` | `mail()->to()->subject()->send()` |
| **UUID** | `src/UUID/` | `uuid()` (v7), `uuid4()` |
| **Controllers** | `src/Controller/` | `#[GET]`, `#[POST]` attributes, PSR-15 |
| **Metadata** | `src/Metadata/` | JSON Schema annotations for entities |

## Key Patterns

### Routing - Multiple Styles

Route files can return different types. Mini's `converter` system handles the response:

```php
// _routes/api/ping.php → /api/ping
<?php return ['time' => gmdate('c')];  // Array → JSON response

// _routes/api/users.php → /api/users
<?php return iterator_to_array(db()->table('users')->limit(100));  // Array → JSON response

// _routes/api/users/_.php → /api/users/123 (wildcard)
<?php return User::find($_GET[0]);  // Entity → JSON via converter (if JsonSerializable)

// _routes/dashboard.php → /dashboard
<?php return new Response(200, [], render('dashboard.php', ['user' => auth()->user()]));
```

All scalars (`string|int|float|bool`), arrays, `stdClass`, and `JsonSerializable` objects convert to JSON responses via the same converter.

**Old-school PHP works too** - useful for quick prototypes or streaming responses:
```php
// _routes/users.php
<?php
header('Content-Type: application/json');
echo json_encode(db()->table('users')->all());
```
*Note: `echo`/`header()` style works great for traditional PHP-FPM. If you later need 10k req/sec on async runtimes (Swoole, ReactPHP), refactor to return PSR-7 responses.*

**Mount controllers** for pattern-based routing within a path:
```php
// _routes/api/users/__DEFAULT__.php
<?php return new UserController();  // Handles /api/users/* via #[GET], #[POST] attributes

// _routes/admin/__DEFAULT__.php
<?php return new SlimApp();  // Any PSR-15 RequestHandler works - mount Slim, Mezzio, etc.
```

### Queries - Composable & Immutable

`PartialQuery` objects are immutable. Chain and pass them around safely:

```php
// Define reusable scopes in your model
class User {
    use ActiveRecordTrait;

    public static function active(): PartialQuery {
        return self::query()->eq('active', 1);
    }

    public static function admins(): PartialQuery {
        return self::active()->eq('role', 'admin');
    }
}

// Compose at call site
$recentAdmins = User::admins()->order('created_at DESC')->limit(10);
foreach ($recentAdmins as $admin) { /* lazy iteration */ }
$asArray = iterator_to_array($recentAdmins);  // materialize if needed
```

**JOINs** - create composable queries from any base SQL:
```php
class User {
    use ActiveRecordTrait;

    /** @return PartialQuery<User> */
    public function friends(): PartialQuery {
        return (new PartialQuery(db(), '
            SELECT u.* FROM users u
            INNER JOIN friendships f ON f.friend_id = u.id AND f.user_id = ?
        ', [$this->id]))->withEntityClass(User::class, false);
    }
}

// Fully composable - WHERE/ORDER/LIMIT are appended to base SQL
$onlineFriends = $user->friends()->eq('u.online', 1)->order('u.name')->limit(10);
foreach ($onlineFriends as $friend) { /* User objects */ }
```

For one-off complex queries, use raw SQL directly:
```php
$results = db()->query("SELECT u.*, COUNT(p.id) as post_count ...");
```

### Services - File-Based Configuration

Override any service by creating a config file matching its namespace:
```
_config/PDO.php                          → configures PDO
_config/Psr/Log/LoggerInterface.php      → configures logger
_config/mini/Auth/AuthInterface.php      → configures auth
```

### Concurrency

Fiber-safe by design. Works with Swoole, ReactPHP, phasync. Use `Lifetime::Scoped` for per-request service instances in long-running processes.

## What Mini Doesn't Include (By Design)

Build these yourself (an LLM can scaffold them in minutes):
- OAuth/JWT authentication flows
- Job queues / background workers
- WebSockets / SSE
- Rate limiting middleware
- Admin panels / CRUD generators

This is intentional. Mini's simplicity enables rapid AI-assisted development.

## The Talent Pool Argument

Laravel's "talent pool" advantage is overstated. Most cities have .NET, Spring, Express, FastAPI developers. Mini's lack of magic means developers from *any* ecosystem ramp up quickly - they write PHP, not "Laravel PHP".
