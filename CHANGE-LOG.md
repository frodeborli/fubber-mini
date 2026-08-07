# Breaking Changes Log

Mini framework is in active internal development. We prioritize clean, simple code over backward compatibility. When we find a better approach, we remove the old implementation rather than maintain redundant code.

This log tracks breaking changes for reference when reviewing old code or conversations.

## VDB: Core SQL grammar, engine limits, and three silent-wrong-answer fixes (2026-08-07)

**BREAKING CHANGES**

Continues the Core SQL work. The engine now covers the standard scalar surface (minus the datetime/interval family, deliberately) and states its operating boundaries in code.

### Added

- **Typed datetime literals** `DATE '…'`, `TIME '…'`, `TIMESTAMP '…'` (F051-01/02/03). Datetimes are stored as TEXT, so a typed literal asserts the format and evaluates to the string — impossible values are rejected at parse time (`DATE '2020-02-30'` and `TIME '25:00:00'` are errors, not guesses).
- **`EXTRACT(field FROM source)`** for YEAR, MONTH, DAY, HOUR, MINUTE, SECOND. Evaluated by VirtualDatabase only and rendered verbatim — SQLite and friends reject the standard spelling, so it does not push down.
- **`NATURAL JOIN` and `JOIN … USING (cols)`**. A merged column appears exactly once and is referenced unqualified; referring to it through either operand's qualifier is an error, as in SQLite.
- **Row value constructors** (`WHERE (a, b) = (1, 2)`, `IN ((1,2), (3,4))`), **`IS [NOT] DISTINCT FROM`**, **`ORDER BY … NULLS FIRST/LAST`** (statement-level and in a window `ORDER BY`), and **`VALUES` as a table constructor**.
- **Scalar functions** `MOD`, `POWER`, `SQRT`, `LN`, `EXP`, `SIGN`, `REPEAT`, `REVERSE`, `LPAD`, `RPAD` — registered through the public `createFunction()` API like every other built-in.
- **`mini\Database\Limits`** and `VirtualDatabase::setLimits()`: `maxJoinedTables` (8), `maxSubqueryDepth` (8), `maxRecursionIterations` (10,000), `maxBufferedWrites` (1,000,000). These state in code what the engine is for — sensible SQL over heterogeneous sources, not an unbounded RDBMS. A query that exceeds one fails immediately naming the limit and how to raise it. `maxSubqueryDepth` is newly enforced; the other three replace hardcoded constants. `setMaxMaterializedRows()` still works and is now a shorthand for `maxBufferedWrites`.

### Fixed — each of these silently returned a wrong answer

- **`ORDER BY` combined with any join discarded the entire sort, direction included.** The in-memory sort read its key by bare property name from rows that carry qualified properties (`a.x`), so every comparison tied. Reachable without any `NULLS` clause, via a mixed `ORDER BY a.x DESC, a.id * 1`. It now reads through the expression evaluator.
- **`ORDER BY <unknown column> NULLS LAST` returned unsorted rows and no error**, while the same query without the clause threw. The in-memory sort bypassed `applyOrderBy()`'s guard; both paths now share `resolveOrderByColumn()`. The check runs up front, so it fires even on results too short for a comparison to happen.
- **A reference to a merged-away `USING`/`NATURAL` column slipped past the ban when an operand was aliased** — the ban list was keyed by the qualified spellings the FROM clause introduced, so `SELECT users.name FROM users u JOIN orders o USING (name)` resolved onto the merged column instead of erroring.
- **`CROSS JOIN b USING (x)` and `CROSS JOIN b ON …` parsed and then silently discarded the clause**, answering a cartesian product to a query that asked for a join. Both are parse errors now, per SQL:2003 7.7 (`<cross join>` takes no join specification). This is a deliberate divergence from SQLite, which treats `CROSS JOIN` as an inner join with a planner hint.
- **A window `ORDER BY` compared NULL with `<=>`**, casting NULL to `""` and ranking it equal to the empty string. It now uses the same comparator as the statement-level sort.
- **`CHAR_LENGTH`/`CHARACTER_LENGTH` counted bytes**, making them indistinguishable from `OCTET_LENGTH` and violating the very feature (E021-04) they implement. They count characters now. The legacy byte-based `LENGTH` keeps its existing contract.
- **`REVERSE`, `LPAD` and `RPAD` sliced multibyte sequences mid-character**, emitting invalid UTF-8 that the framework's own JSON encoder then refused to serialize. All three are character-based now (via PCRE `/u`, not ext-mbstring, which is not a declared dependency).

### Known gaps, reported not fixed

The aggregate/`GROUP BY` sort path has the same unknown-column hole (`GROUP BY n ORDER BY nosuchcol` returns unsorted rows silently). It sorts already-projected rows, so a guard there would wrongly reject `SELECT COUNT(*) FROM t GROUP BY c ORDER BY c`; fixing it means carrying group keys into the sort. Also pre-existing: `ORDER BY <out-of-range ordinal>` is ignored where SQLite errors, and `WHERE nosuchcol = 1` returns zero rows instead of erroring.

## VDB: pluggable scalar functions, CAST, and Core SQL scalar syntax (2026-08-06)

**BREAKING CHANGES**

An audit against the SQL standard's conformance taxonomy found the engine's coverage inverted: it implemented *optional* feature packages (CTEs, recursive CTEs, window functions, `INTERSECT`) while missing *Core* (mandatory) scalar features. This closes the scalar gap and makes the function library pluggable.

- **`VirtualDatabase::createFunction(string $name, callable $fn, int $argCount = -1): bool` added**, mirroring the existing `createAggregate()` and `SQLite3::createFunction`. Names are case-insensitive, and **registering an existing name replaces it, built-ins included** — deliberate, so you can swap in banker's rounding or a multibyte `LENGTH`.
- **The built-in scalar library moved out of the evaluator's hardcoded `match` into `src/Database/StandardFunctions.php`**, registered through that same public API. Behavior is unchanged for every existing function; the library now doubles as the worked example for adding your own.
- **`CAST(expr AS type)` implemented** with SQLite coercion semantics for INTEGER/INT, REAL/FLOAT/DOUBLE, TEXT/VARCHAR/CHAR, NUMERIC/DECIMAL, BOOLEAN, BLOB. **Previously `CAST(x)` parsed as a bare function call and returned its argument unchanged; that stub is gone, and `CAST(x)` without `AS type` is now a syntax error.**
- **`LIKE … ESCAPE '<char>'` implemented** (and `NOT LIKE … ESCAPE`). A multi-character or empty escape raises "ESCAPE expression must be a single character". `ESCAPE` is a soft keyword, so columns named `escape` still parse.
- **Standard scalar spellings added**: `POSITION(x IN y)`, `SUBSTRING(x FROM y [FOR z])`, `TRIM([LEADING|TRAILING|BOTH] [chars] FROM x)`, `CHAR_LENGTH`, `CHARACTER_LENGTH`, `OCTET_LENGTH`. Every pre-existing comma spelling (`SUBSTR`, `TRIM(x)`, `INSTR`, `LENGTH`) is unchanged. Note `CHARACTER_LENGTH` is byte-based like the existing `LENGTH` — making it multibyte-aware would have silently changed `LENGTH`'s contract.
- **Fail-fast arity checking**: fixed-arity built-ins now reject a wrong argument count (`UPPER('a','b')` → "UPPER() expects 1 argument, 2 given") instead of ignoring extra arguments. Genuinely optional-argument functions (TRIM, ROUND, SUBSTR, CONCAT, COALESCE) remain variadic.
- **LIKE without ESCAPE treats backslash as a literal character.** Previously `\%` was mangled into a wildcard by the old regex construction. Use `ESCAPE '\'` for escaping behavior.
- **API:** new AST node `mini\Parsing\SQL\AST\CastNode` (extends `FunctionCallNode`, so generic AST walks keep working). `LikeOperation::__construct()` gained a 4th optional parameter `?ASTNode $escape` and a public `$escape` property — **code that reconstructs a `LikeOperation` must forward it or the ESCAPE clause is silently lost.**
- `LIKE … ESCAPE` is evaluated row-by-row rather than pushed down to `TableInterface::like()`, and is rejected inside OR-predicate pushdown with an explicit message.

## VDB: writes are deferred until a statement finishes reading (2026-08-06)

**BREAKING CHANGE** (behavioral; also a new limit that can reject previously-accepted statements)

Mutations are now logged during the read and applied afterwards, via `mini\Database\PendingWrites`. Previously `INSERT ... SELECT` inserted while iterating its lazy source, so a statement reading the table it writes fed its own output back into the scan: **`INSERT INTO t SELECT ... FROM t` never terminated**, growing the table until memory ran out. It now terminates with the standard result (the source as it was when the statement began), matching SQLite, PostgreSQL and MySQL.

- **`VirtualDatabase::setMaxMaterializedRows(?int)` added**, default **1,000,000**. Because writes must be buffered until the read completes, a statement whose source yields more rows than the cap now throws a `RuntimeException` naming the cap and how to raise it, instead of exhausting memory. Statements writing more than a million rows in one go must raise the cap (or pass `null` to disable it).
- **A failed statement now writes nothing.** The cap trips during the read phase, before any row is applied — previously a partially-applied mutation was possible.
- **`UPDATE` with row-context expressions** (`SET n = n + 1`) already collected changes before applying them; it now goes through the same mechanism, and the "requires a primary key, InMemoryTable, or ArrayTable" error is raised before any work rather than part-way through applying.
- **`setQueryTimeout()` is documented as best effort**, which is what it has always been. It is checked while a SELECT's rows are pulled (every 100 rows), so it bounds long scans and runaway result sets. It does not bound time inside a single backing-table call, and it does not apply to INSERT/UPDATE/DELETE. It is a guard rail, not a security boundary — see `src/Database/Virtual/README.md`.

## Release polish: strict alias columns, PSR-17 claim dropped, table projection (2026-08-06)

**BREAKING CHANGES**

Release-readiness pass across tests, dependencies, CLI and documentation. Behavior changes worth knowing:

- **`AliasTable` rejects unqualified column names.** Filter/order/column methods on an aliased table now require the aliased name (`u.id`); passing `id` throws `InvalidArgumentException` naming the expected column instead of silently suffix-matching. Fail-fast per the documented design.
- **`psr/http-factory` removed from `require`, and `psr/http-factory-implementation` removed from `provide`.** Mini ships no PSR-17 factories, so the old `provide` entry could satisfy another package's PSR-17 requirement and then fail at runtime. Packages that relied on Mini to pull `psr/http-factory` transitively, or to satisfy `psr/http-factory-implementation`, must now require it themselves.
- **`psr/log-implementation: 3.0` added to `provide`** — Mini's `Logger` is a genuine PSR-3 implementation.
- **Table iteration always projects to `getColumns()`.** Hidden columns requested internally for filtering/sorting no longer leak into output rows of `AbstractTable`-based tables.
- **`GeneratorTable` caches small result sets as documented.** Fully-consumed generators of ≤1000 rows are cached, so the generator closure is no longer re-invoked on every iteration — observable if your closure has side effects. Partially-consumed generators are never cached.
- **`BTreeIndex::close()` now auto-commits an open transaction.** It referenced properties removed in an earlier overlay refactor, so the auto-commit branch was dead and pending changes were silently discarded.
- **`mini docs compatible <target>` requires an interface** — a class or unknown symbol now prints one error line to STDERR and exits 1 (previously an uncaught `ReflectionException`, exit 255).
- **`mini db` REPL accepts piped/redirected stdin**, executing lines until EOF; previously it hung indefinitely on non-TTY stdin.
- `Auth::requireLogin()` throws `AuthenticationRequiredException` (401), not `AccessDeniedException` (403). This was already the runtime behavior; stale tests were corrected. `requireRole()`/`requirePermission()` still throw `AccessDeniedException`.

Removed as superseded: `src/Router/Router.php-old`, `WRITING-DOCUMENTATION.md`, `MINI-STYLE-DRAFT.md`, `VALIDATOR_REFACTOR_STATUS.md`, `CONVERTER-CONCEPT.md`, `HOWTO-UPDATE-AUTOLOADER.md`, stray root `test-*.php` scripts, and the `tests/_old/` tree.

## Router: route files must return a RequestHandler or Response (2026-08-06)

**BREAKING CHANGE**

The `_routes/` contract is now strict: a route file must return a PSR-15 `RequestHandlerInterface` (typically a controller extending `mini\Controller\AbstractController`) or a PSR-7 `ResponseInterface`. A `Closure` is still accepted and wrapped in `ConverterHandler` as an inline handler, and `ResponseAggregate` still resolves via `getResponse()`. The "classical PHP" routing paradigm (echo/header, return nothing) is removed — it tied applications to one-process-per-request SAPIs and cannot survive the move to Fiber-based coroutine runtimes. It also invited poor architecture: coding agents in particular abused it to dump output inline instead of composing handlers.

### What changed

- **Direct output from a route file is now a `RuntimeException`.** Any echo/print during route file inclusion is an error; the router no longer forwards buffered output.
- **Returning nothing (or `null`) is now a `RuntimeException`.** Previously this signalled "response already sent".
- **`mini\Http\ResponseAlreadySentException` is deleted**, along with `HttpDispatcher`'s catch-and-ignore of it.
- **Scalar/array returns from route files are no longer auto-converted to responses.** Conversion via the converter registry still applies to Closure and controller-method return values — that is where "return data" belongs.

### What you may need to do

- Routes using echo/header: return `new \mini\Http\Message\Response($body, $headers)` instead.
- Routes returning scalars/arrays directly: wrap in a Closure (`return fn() => $data;`) or move into a controller method.
- Remove any `throw new ResponseAlreadySentException()` and any `catch` of it.

## CLI: composer-driven subcommand discovery, `vdb` removed, signal forwarding (2026-05-25)

**BREAKING CHANGE**

The `mini` CLI no longer hardcodes its subcommands. The dispatcher (`bin/mini.php`) now discovers subcommands by reading `extra.mini.commands` from each installed package's `composer.json` (plus the host project's own). The framework's own subcommands are declared in `fubber/mini`'s `composer.json` `extra` section on equal footing with any aspect or third-party package.

### What changed

- **`vdb` subcommand removed.** Use `mini db -v` or `mini db --virtual` instead — the modern `ArgManager` in `bin/mini-db.php` handles the flag natively.
- **Subcommand contribution is open.** Any package can declare `extra.mini.commands.<name> = { script: "...", description: "..." }` and the script becomes a `mini <name>` subcommand.
- **Dispatcher forwards termination signals.** `SIGINT`, `SIGTERM`, `SIGHUP` sent to the dispatcher process are forwarded to the spawned subcommand instead of leaving it orphaned. Implemented via `proc_open` + `pcntl_signal_dispatch` poll loop (the only reliable pattern, since `proc_close`'s blocking waitpid doesn't yield to PHP-level signal handlers).
- **`mini-*.php` scripts no longer published as `vendor/bin/` binaries.** They're dispatched via `vendor/bin/mini <name>` only.

### What you may need to do

- Replace any `vendor/bin/mini vdb` with `vendor/bin/mini db -v`.
- Replace any references to `vendor/bin/mini-translations`, `vendor/bin/mini-migrations`, etc., with the dispatched form (`vendor/bin/mini translations`, etc.).
- If you maintained a fork that added subcommands to `bin/mini.php`'s `$availableCommands` array, migrate to declaring them in `extra.mini.commands` in your package's `composer.json`.

## CLI: `mini serve` rewritten for modern ArgManager + signal-safe fallback (2026-05-25)

**BREAKING CHANGE** (only if you were importing `bin/mini-serve.php` directly)

`bin/mini-serve.php` was using a removed `ArgManager::withSupportedArgs()` API and direct `$args->opts[]` array access, so any invocation with arguments was crashing. Rewritten against the current `ArgManager` API (`withFlag`, `withRequiredValue`, accessed via `getFlag`/`getOption`). The banner now writes to STDERR so it survives `pcntl_exec`. The non-`pcntl_exec` fallback now uses `proc_open` + a `pcntl_signal_dispatch` poll loop to forward SIGINT/SIGTERM/SIGHUP to the child cleanly.

## Database: model() function, provide\* convention, Authorization integration (2026-02-19)

**BREAKING CHANGE**

Extracted attribute parsing from Model into `ModelInfo` + `model()` function. Adopted `provide*` naming convention for framework declaration points. Wired `save()`/`delete()` into the Authorization system via `can()`.

### What Changed

**New: `model()` function + `ModelInfo` class**

Follows the same pattern as `validator()` and `metadata()` — a global function that returns cached, parsed metadata from attributes.

```php
model(User::class)->tableName;   // 'users'
model(User::class)->primaryKey;  // 'id'
```

**Removed from Model**: `tableName()`, `primaryKey()` methods and their `$_tableNames`/`$_primaryKeys` caches. Attribute parsing now lives in `ModelInfo::fromClass()`.

**Renamed**: `database()` → `provideDatabase()` — the `provide*` prefix signals "framework declaration point, not an API to call directly."

**New authorization declaration points on Model**:

```php
// Static — class-level abilities (return true/false/null)
public static function provideCanList(): ?bool { return null; }
public static function provideCanCreate(): ?bool { return null; }

// Instance — entity-level abilities
public function provideCanRead(): ?bool { return null; }
public function provideCanUpdate(): ?bool { return null; }
public function provideCanDelete(): ?bool { return null; }
```

All return `null` by default → no opinion → Authorization default = allowed.

**`save()` and `delete()` now use two-layer authorization**:

1. **`can()` system** — action authorization via `provideCanUpdate()`/`provideCanDelete()`/`provideCanCreate()`
2. **`updatable()`/`deletable()`** — row-level write scoping (entity must be reachable via query)

```php
// save() on update: can() check, then updatable() row check
// save() on create: can() check only
// delete(): can() check, then deletable() row check
```

**New: `updatable()` and `deletable()` methods** — row-level write scoping, default to `query()`. Override to diverge read vs write scoping:

```php
public static function updatable(): Query {
    return static::queryUnsafe()->eq('owner_id', auth()->getUserId());
}
public static function deletable(): Query {
    return static::queryUnsafe()->eq('owner_id', auth()->getUserId());
}
```

**Authorization handler registered for Model::class** — dispatches to the `providecan*()` methods via the existing Authorization system (guards → handlers → fallback → default allow).

### Migration

**`database()` → `provideDatabase()`**: If you overrode `database()` in a subclass, rename to `provideDatabase()`:

```php
// Before
protected static function database(): DatabaseInterface { return vdb(); }

// After
protected static function provideDatabase(): DatabaseInterface { return vdb(); }
```

**`tableName()` / `primaryKey()`**: These methods no longer exist. Use `model(MyEntity::class)->tableName` or `model(MyEntity::class)->primaryKey` instead. Entity classes don't need changes — the `#[Table]` and `#[PrimaryKey]` attributes still work.

**Custom save()/delete() overrides**: If you overrode `save()`/`delete()` for authorization, consider using `provideCanUpdate()`/`provideCanDelete()` instead. The default `save()`/`delete()` now call `can()` automatically.

### What does NOT change

- **`query()` method** — stays as row-level scoping mechanism
- **`queryUnsafe()`/`findUnsafe()`/`saveUnsafe()`/`deleteUnsafe()`** — unchanged, still final
- **Authorization guards** — still work via existing guard system

## Database: Simplified Model — Final Unsafe, Overridable Safe (2026-02-18)

**BREAKING CHANGE**

Merged `ModelTrait` into the abstract `Model` class with a simple two-tier design: final unsafe methods for guaranteed persistence, overridable safe methods for custom auth/logic.

### What Changed

- **ModelTrait deleted** — all code moved into `Model` abstract class
- **saveUnsafe(), deleteUnsafe()** are `final` — the guaranteed persistence layer (timestamps, dehydration, validation, DB writes)
- **save(), delete()** are **overridable** — default verifies entity is visible via `query()` before persisting, then calls `saveUnsafe()`/`deleteUnsafe()`. Throws `AccessDeniedException` if entity exists but is not accessible.
- **Removed**: `performSave()`, `performDelete()`, `beforeSave()`, `afterSave()`, `beforeDelete()`, `afterDelete()` — no hooks, no extra layers

### Design

```
saveUnsafe() [final]  → dehydrate (incl. timestamps) → validate → INSERT/UPDATE
deleteUnsafe() [final] → DELETE → clear identity

save() [overridable]   → query()-based auth check → saveUnsafe()
delete() [overridable] → query()-based auth check → deleteUnsafe()
```

Override `query()` to filter by user permissions — `save()` and `delete()` automatically use it to verify access.

### Timestamp Attributes

Timestamps are now handled by the Dehydrator via `#[CreatedAt]` and `#[UpdatedAt]` attributes:

```php
#[CreatedAt]
public ?string $created_at = null;  // Set to current datetime on insert (when null)

#[UpdatedAt]
public string $updated_at;          // Set to current datetime on every save
```

- Works with any property name — not hardcoded to `created_at`/`updated_at`
- Output is always `'Y-m-d H:i:s'` string format (SQL-compatible)
- `#[CreatedAt]` only sets value when null; `#[UpdatedAt]` always overwrites
- Only applies to reflection-based dehydration (entities implementing `Dehydratable` handle their own)

For custom logic around persistence, override `save()`/`delete()` and wrap the unsafe call:

```php
public function save(?array $only = null): int {
    // custom auth, logging, etc.
    return $this->saveUnsafe($only);
}
```

### Migration

**Classes extending Model** — no changes needed (all entity classes already extend Model).

**Classes using `use ModelTrait` directly** — must extend `Model` instead:

```php
// Before
class User {
    use ModelTrait;
    protected static function tableName(): string { return 'users'; }
}

// After
class User extends Model {
    protected static function tableName(): string { return 'users'; }
}
```

**Classes overriding performSave()/hooks** — override `save()` instead:

```php
// Before
protected function performSave(): int { ... }
protected function beforeSave(): void { ... }

// After
public function save(?array $only = null): int {
    // your custom logic here
    return $this->saveUnsafe($only);
}
```

### Why This Change?

1. **Simpler**: Two layers instead of five (`save → saveUnsafe → beforeSave → performSave → afterSave`)
2. **No temporary state**: Removed `$_oldDataForSave` and `$_saveOnlyProperties` — parameters flow directly
3. **Natural override point**: Override `save()`/`delete()` is more intuitive than `performSave()`/hooks
4. **Final persistence**: `saveUnsafe()`/`deleteUnsafe()` guarantee timestamps, validation, and DB writes always happen

## Database: PartialQuery API - Separated withEntityClass() from withHydrator() (2025-01-24)

**BREAKING CHANGE**

Split `withHydrator()` into two separate methods for better API clarity:
- `withEntityClass(string $class, array|false $constructorArgs = false)` - Framework-managed hydration
- `withHydrator(\Closure $hydrator)` - Custom closure hydration only

### What Changed

**Before:**
```php
// Class string with constructor args
$users = db()->table('users')->withHydrator(User::class, [db()->getPdo()]);

// Class string without constructor
$users = db()->table('users')->withHydrator(User::class, false);

// Closure
$users = db()->table('users')->withHydrator(
    fn($id, $name, $email) => new User($id, $name, $email)
);
```

**After:**
```php
// Use withEntityClass() for class-based hydration
$users = db()->table('users')->withEntityClass(User::class, [db()->getPdo()]);

// Skip constructor with false
$users = db()->table('users')->withEntityClass(User::class, false);

// Use withHydrator() for closures ONLY
$users = db()->table('users')->withHydrator(
    fn($id, $name, $email) => new User($id, $name, $email)
);
```

### Migration

**Search and replace:**
1. Find: `->withHydrator(SomeClass::class, false)` → Replace: `->withEntityClass(SomeClass::class, false)`
2. Find: `->withHydrator(SomeClass::class, [` → Replace: `->withEntityClass(SomeClass::class, [`
3. Find: `->withHydrator(SomeClass::class)` → Replace: `->withEntityClass(SomeClass::class)`
4. Closures still use `->withHydrator(fn(...) => ...)`

**Why this change:**
- Cleaner API - entity class handling vs custom hydration are fundamentally different
- Better type safety - `withHydrator()` now only accepts `\Closure`
- No more reserved values (`true` is no longer reserved)
- Paves the way for future attribute-based hydration on entity classes

## Database: Added insert() and upsert() + ModelTrait (2025-01-21)

**NEW FEATURES**

Added convenient methods for inserting and upserting rows, plus an Eloquent-style ModelTrait for Active Record pattern support.

### What's New
- **DatabaseInterface::insert()**: Simple INSERT operation returning last insert ID
  - `db()->insert('users', ['name' => 'John', 'email' => 'john@example.com'])`
  - Returns the new row's ID (string)
  - Throws exception on failure (unique constraint violation, etc.)
- **DatabaseInterface::upsert()**: INSERT or UPDATE on conflict
  - `db()->upsert('users', ['email' => 'john@example.com', 'name' => 'John'], 'email')`
  - Supports composite unique keys: `db()->upsert('prefs', $data, 'user_id', 'key')`
  - Dialect-specific SQL generation (MySQL, Postgres, SQLite, SQL Server, Oracle)
  - Returns affected rows (1 for insert/update, 0 for no change)
- **ModelTrait**: Eloquent-style Active Record pattern with generic template support
  - **Entity pattern**: `$user->save()`, `$user->delete()` - instance methods
  - **Repository pattern**: `Users::save($user)`, `Users::delete($user)` - static methods on POPO
  - `User::find($id)` - Find by primary key with typed return (`User|null`)
  - `User::query()` - Returns typed `PartialQuery<User>` for composable scopes
  - `@template T of object` - Full PHPDoc generic support for type safety
  - Automatic hydration via reflection (no constructor calls needed)
  - Requires: `getTableName()`, `getPrimaryKey()`, `getEntityClass()`, `dehydrate(object $entity)` methods

### Migration

No breaking changes - these are pure additions.

**Using insert():**
```php
// Before
db()->exec(
    "INSERT INTO users (name, email) VALUES (?, ?)",
    ['John', 'john@example.com']
);
$id = db()->lastInsertId();

// After
$id = db()->insert('users', ['name' => 'John', 'email' => 'john@example.com']);
```

**Using upsert():**
```php
// Insert or update based on email uniqueness
db()->upsert('users', [
    'email' => 'john@example.com',
    'name' => 'John Doe'
], 'email');
```

**Using ModelTrait (Entity pattern):**
```php
class User {
    use ModelTrait;

    public ?int $id = null;
    public string $name;
    public string $email;

    protected static function getTableName(): string { return 'users'; }
    protected static function getPrimaryKey(): string { return 'id'; }
    protected static function getEntityClass(): string { return self::class; }
    protected static function dehydrate(object $entity): array {
        return ['id' => $entity->id, 'name' => $entity->name, 'email' => $entity->email];
    }
}

$user = new User();
$user->name = 'John';
$user->save(); // INSERT

$user->name = 'Updated';
$user->save(); // UPDATE

$user->delete();
```

**Using ModelTrait (Repository pattern with POPO):**
```php
class User {
    public ?int $id = null;
    public string $name;
}

/**
 * @use ModelTrait<User>
 */
class Users {
    use ModelTrait;

    protected static function getTableName(): string { return 'users'; }
    protected static function getPrimaryKey(): string { return 'id'; }
    protected static function getEntityClass(): string { return User::class; }
    protected static function dehydrate(object $entity): array {
        return ['id' => $entity->id, 'name' => $entity->name];
    }
}

$user = new User();
$user->name = 'John';
Users::save($user); // INSERT

$found = Users::find(1);
$found->name = 'Updated';
Users::save($found); // UPDATE

Users::delete($found);
```

See `examples/upsert.php`, `examples/model-trait.php`, and `examples/model-trait-repository.php` for complete examples.

## Database: Simplified query() + Object Hydration + SQL Dialects (2025-01-21)

**BREAKING CHANGE + NEW FEATURES**

Simplified database interface by making `query()` return `iterable` and removing `queryStream()`. Added object hydration with full PHPDoc generic support. Added SQL dialect system for database-specific SQL generation.

### Breaking Changes
- **query()** now returns `iterable` (yields rows) instead of `array`
  - Use `iterator_to_array($db->query(...))` if you need an actual array
  - More memory efficient - streams by default instead of buffering
- **queryStream()** removed - no longer needed since `query()` streams
  - Replace `$db->queryStream(...)` with `$db->query(...)`

### What's New
- **PartialQuery::withHydrator()**: Convert rows to typed objects
  - Class name: `->withHydrator(User::class, $constructorArgs)`
  - Skip constructor: `->withHydrator(User::class, false)` - uses `newInstanceWithoutConstructor()`
  - Uses `ReflectionClass::newInstanceArgs()` for efficiency
  - Uses reflection to set private/protected/public properties
  - Reflection properties cached per iteration (thread-safe, no static state)
  - Catches `ReflectionException` and throws `RuntimeException` with context
  - Closure: `->withHydrator(fn(...$row) => new User(...$row))`
  - Reserved: `->withHydrator(User::class, true)` throws `InvalidArgumentException` (future use)
- **Generic template support**: `@template T` for type-safe IDE support
  - `PartialQuery<User>` - IDE knows iteration yields User objects
  - `one()` returns `T|null` - type-safe single row fetch
  - `getIterator()` returns `\Generator<int, T, mixed, void>` - proper generator typing
- **Composable with scopes**: `User::all()` can return `PartialQuery<User>`
- **Works with mutations**: Hydration doesn't prevent `delete()` or `update()`
- **Cleared by select()**: Selecting specific columns clears hydrator and returns `PartialQuery<array>`

### Migration
```php
// Before
$users = $db->query("SELECT * FROM users");
foreach ($users as $user) { ... }

// After - same usage! Iteration works identically
$users = $db->query("SELECT * FROM users");
foreach ($users as $user) { ... }

// If you actually need an array
$users = iterator_to_array($db->query("SELECT * FROM users"));

// queryStream() removed
$stream = $db->queryStream("SELECT * FROM users");  // Remove this
$stream = $db->query("SELECT * FROM users");        // Use this
```

See `examples/partial-query-hydrator.php` for hydration examples.

## Database: Added PartialQuery + Major Improvements (2025-01-20)

**NEW FEATURES + BREAKING CHANGES**

Added immutable query builder for **expert-level composition architecture**, plus composable DELETE/UPDATE operations. Also includes several critical improvements based on expert review.

### What's New
- **PartialQuery class**: Immutable query builder (marked `final`)
- **PartialQueryableTrait**: Adds `table()` method to DatabaseInterface implementations
- **New DatabaseInterface methods**:
  - `quote(mixed $value): string` - Quote values for SQL (auto-detects type)
  - `table(string $table): PartialQuery` - Create query builder
  - `delete(PartialQuery $query): int` - Delete rows matching query (requires WHERE)
  - `update(PartialQuery $query, string|array $set): int` - Update rows matching query

### Breaking Changes
- **exec()** now returns `int` (affected rows) instead of `bool`
- **transaction()** closure now receives `DatabaseInterface` as parameter
- **delete()** requires WHERE clause - throws exception if missing
- **PartialQuery** iterator now streams instead of buffering (removed `fetchAll()`)
- **PartialQuery** marked as `final` - cannot be extended
- **count()** now respects SELECT columns (uses subquery for DISTINCT etc)
- **LIMIT/OFFSET** syntax changed to MySQL-compatible `LIMIT offset, count`

### Primary Value: Architectural Composition
- **Reusable fragments**: Define base queries once, reuse without side effects
- **Safe branching**: Branch query logic without mutation or defensive copying
- **Encapsulated security**: Parameter binding at architectural level
- **Expert tool**: Not a "beginner ORM" but a composition primitive

### Secondary Value: Beginner Safety
- **Safe-by-default**: SQL injection protection built-in
- **IDE autocomplete**: Discoverable API via IDE suggestions

### Key Features
- **Immutable**: Each method returns NEW instance (no side effects)
- **Composable**: Build reusable, non-mutating query fragments
- **Safe defaults**: 1000 row limit prevents accidental full table scans
- **SQL-transparent**: Raw SQL always available via `where()`
- **Iterable**: Use directly in `foreach`
- **Not an ORM**: For complex queries, use `db()->query()` directly

### Usage

**SELECT queries:**
```php
// Basic usage
$users = db()->table('users')
    ->eq('active', 1)
    ->order('created_at DESC')
    ->limit(50);

foreach ($users as $user) {
    echo $user['name'];
}

// Composable scopes
class User {
    public static function spam(): PartialQuery {
        return db()->table('users')->eq('status', 'spam');
    }
}

$recentSpam = User::spam()
    ->where('created_at > ?', [date('Y-m-d', strtotime('-7 days'))]);
```

**DELETE/UPDATE with composable queries:**
```php
// Delete using scopes
$deleted = db()->delete(User::spam());

// Update with array
db()->update(
    db()->table('users')->eq('status', 'inactive'),
    ['status' => 'archived', 'archived_at' => date('Y-m-d H:i:s')]
);

// Update with SQL expression
db()->update(
    db()->table('users')->eq('status', 'active'),
    'login_count = login_count + 1'
);
```

See `src/Database/README.md` for complete documentation.

## PSR-7 Improvements: HTTP Protocol Alignment + Simplifications (2025-01-12)

Multiple PSR-7 improvements: Request/ServerRequest now use request targets (HTTP protocol alignment), PSR-17 factories removed (unnecessary abstraction), and Stream simplified (no serialization).

### What Changed
- **Request constructor**: `new Request($method, $uri, ...)` → `new Request($method, $requestTarget, ...)`
- **ServerRequest constructor**: `new ServerRequest($method, $uri, ..., $queryParams, ...)` → `new ServerRequest($method, $requestTarget, ..., $queryParams=null, ...)`
- **URI construction**: `getUri()` now constructs URI dynamically from request target + headers (unless overridden via `withUri()`)
- **Query params**: `getQueryParams()` now derives from request target by default (unless overridden via `withQueryParams()`)
- **New method**: `getQuery()` returns query string portion of request target
- **HTTPS detection**: ServerRequest detects scheme from `serverParams['HTTPS']` when constructing URI
- **Removed PSR-17**: Deleted `Psr17Factory` and `ServerRequestCreator` - unnecessary abstractions
- **HttpDispatcher**: Now creates ServerRequest directly (SAPI-specific logic belongs in dispatcher)
- **New factory**: `Request::create($method, $uri)` - convenience factory for creating outgoing requests from URIs
- **Stream::cast() simplified**: Removed `$contentType` parameter and all serialization logic - Stream is purely about wrapping stream resources
- **Removed helpers**: Deleted `create_response()`, `create_json_response()`, `emit_response()` - just use `new Response()` directly

### Core Principle
HTTP requests have **method**, **request-target**, **protocol-version**, and **headers** - not URIs. URIs are constructed on-demand from these components.

### Behavior Changes

**Request target is source of truth**:
```php
// Request target stored directly
$request = new ServerRequest('GET', '/path?foo=bar', '', [], null, []);
$request->getRequestTarget();  // '/path?foo=bar'
$request->getQuery();           // 'foo=bar'
$request->getQueryParams();     // ['foo' => 'bar'] (derived)
$request->getUri()->getQuery(); // 'foo=bar' (constructed)
```

**withQueryParams() does NOT change URI** (per PSR-7 spec):
```php
$r2 = $request->withQueryParams(['baz' => 'qux']);
$r2->getRequestTarget();        // '/path?foo=bar' (unchanged!)
$r2->getQueryParams();          // ['baz' => 'qux'] (override)
$r2->getUri()->getQuery();      // 'foo=bar' (unchanged!)
```

**withUri() and withRequestTarget() are independent**:
```php
$r3 = $request->withUri(new Uri('http://example.com/other?x=y'));
$r3->getRequestTarget();        // '/path?foo=bar' (unchanged!)
$r3->getUri()->getQuery();      // 'x=y' (URI override)
$r3->getQueryParams();          // ['foo' => 'bar'] (from request target!)
```

**Relative URI when no Host header**:
```php
$request = new Request('GET', '/path?query', '', []);
$request->getUri();  // Returns relative URI: '/path?query'
```

**HTTPS detection from server params**:
```php
$request = new ServerRequest(
    'GET', '/secure', '',
    ['Host' => 'example.com'],
    null,
    ['HTTPS' => 'on']
);
$request->getUri();  // 'https://example.com/secure'
```

### Migration

**Most applications**: No changes needed - HttpDispatcher handles request creation internally.

**Creating outgoing HTTP requests** (HTTP clients, testing):
```php
// Before
$request = new Request('GET', 'http://example.com/path?foo=bar', '');

// After - Use convenience factory
$request = Request::create('GET', 'http://example.com/path?foo=bar');

// Or direct constructor with request target
$request = new Request('GET', '/path?foo=bar', '', ['Host' => 'example.com']);
```

**Creating responses** (simple and direct):
```php
// Before
\mini\Http\create_response(200, 'Hello');
\mini\Http\create_json_response(['data' => 'value']);

// After
new Response('Hello', [], 200);
new Response(json_encode(['data' => 'value']), ['Content-Type' => 'application/json'], 200);
```

### Why These Changes?

1. **HTTP protocol correctness**: Requests ARE request targets, not URIs
2. **PSR-7 compliance**: `withQueryParams()` must not affect URI (was incorrectly coupled before)
3. **Cleaner separation**: URI, query params, and request target have distinct lifecycles
4. **Performance**: No need to construct/store URI object during request creation
5. **No PSR-17 needed**: Mini doesn't need factory abstractions - dispatchers create requests directly
6. **Environment-specific**: HttpDispatcher owns SAPI logic; future FiberHttpDispatcher will own its own creation logic
7. **Stream responsibility**: Stream wraps stream resources - serialization belongs in converters/helpers

## Native PSR-7 Implementation (Replaced Nyholm)

Mini now includes its own PSR-7 HTTP message implementation, removing the dependency on `nyholm/psr7` and `nyholm/psr7-server`.

### What Changed
- **Removed dependencies**: `nyholm/psr7` and `nyholm/psr7-server` no longer required
- **New classes**: All PSR-7 classes now in `mini\Http\Message\` namespace
- **API compatible**: Drop-in replacement, no code changes needed for standard PSR-7 usage
- **Response constructor signature**: Mini's `Response` uses `($body, $headers, $statusCode, $reasonPhrase, $protocolVersion)` instead of Nyholm's `($statusCode, $headers, $body)`

### New Classes
All classes implement their respective PSR-7 interfaces:
- `mini\Http\Message\Request`
- `mini\Http\Message\Response`
- `mini\Http\Message\ServerRequest`
- `mini\Http\Message\Stream`
- `mini\Http\Message\Uri`
- `mini\Http\Message\UploadedFile`
- `mini\Http\Message\Psr17Factory` (PSR-17 factory)
- `mini\Http\Message\ServerRequestCreator` (creates ServerRequest from globals)

### Migration

**Most applications**: No changes needed - Mini's default converters and HttpDispatcher already updated.

**If you used Nyholm classes directly**:
```php
// Before
use Nyholm\Psr7\Response;
$response = new Response(200, ['Content-Type' => 'text/html'], $body);

// After
use mini\Http\Message\Response;
$response = new Response($body, ['Content-Type' => 'text/html'], 200);
```

**Factory usage** (rare - most apps use helper functions):
```php
// Before
use Nyholm\Psr7\Factory\Psr17Factory;

// After
use mini\Http\Message\Psr17Factory;
```

### Why This Change?

1. **Zero dependencies**: Aligns with Mini's zero-dependency architecture
2. **Extendable**: Nyholm's implementation prohibited extending classes
3. **Control**: Full control over PSR-7 behavior and fixes
4. **Correctness**: Nyholm had implementation issues we needed to work around

## PSR-7 url() Function with CDN Support

The `url()` function now returns `UriInterface` instead of string and includes proper relative path resolution and CDN support.

### Changed Signature
```php
// Before
function url($path = '', array $query = []): string

// After
function url(string|UriInterface $path = '', array $query = [], bool $cdn = false): UriInterface
```

### New Behavior
- Returns `UriInterface` (PSR-7) instead of string
- Properly resolves relative paths (`.`, `..`)
- Strips scheme/host from input URLs - always resolves against base URL
- Supports CDN via `$cdn` parameter
- UriInterface is stringable - templates still work: `<?= url('/path') ?>`

### New Environment Variable
- `MINI_CDN_URL` - CDN base URL for static assets (optional, defaults to `baseUrl`)

### Migration

**Templates** - No changes needed (UriInterface is stringable):
```php
<a href="<?= url('/users') ?>">Users</a>
```

**Type hints** - Update if you type-hinted the return value:
```php
// Before
$url = url('/path');  // string

// After
$url = url('/path');  // UriInterface (but still works as string)
```

**CDN usage**:
```php
// Static assets via CDN
<link href="<?= url('/css/app.css', cdn: true) ?>" rel="stylesheet">
<img src="<?= url('/images/logo.png', cdn: true) ?>" alt="Logo">
```

## Phase System Introduction

The phase system replaces individual lifecycle hooks with a comprehensive state machine.

### Removed Methods
- `Mini::enterBootstrapPhase()` - use `Mini::$mini->phase->trigger(Phase::Bootstrap)`
- `Mini::enterReadyPhase()` - use `Mini::$mini->phase->trigger(Phase::Ready)`
- `Mini::enterFailedPhase()` - use `Mini::$mini->phase->trigger(Phase::Failed)`
- `Mini::enterShutdownPhase()` - use `Mini::$mini->phase->trigger(Phase::Shutdown)`
- `Mini::getCurrentPhase()` - use `Mini::$mini->phase->getCurrentState()`
- `Mini::enterRequestContext()` - framework now uses phase transitions
- `Mini::exitRequestContext()` - framework now uses phase transitions

### Removed Hooks
- `Mini::$onRequestReceived` - use `Mini::$mini->phase->onEnteringState(Phase::Ready, fn() => ...)`
- `Mini::$onAfterBootstrap` - use `Mini::$mini->phase->onEnteredState(Phase::Ready, fn() => ...)`

### Migration Examples

**Before:**
```php
Mini::$mini->onRequestReceived->listen(function() {
    // Authentication logic
});

Mini::$mini->onAfterBootstrap->listen(function() {
    // Output buffering setup
});
```

**After:**
```php
// Fires when entering Ready phase (before phase change completes)
Mini::$mini->phase->onEnteringState(Phase::Ready, function() {
    // Authentication logic
});

// Fires after Ready phase entered (after phase change completes)
Mini::$mini->phase->onEnteredState(Phase::Ready, function() {
    // Output buffering setup
});
```
