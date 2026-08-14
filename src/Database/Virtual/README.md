# Virtual Database System

A federated SQL engine over any `TableInterface` data source — part of a forkable core so the same SQL runs against PDO tables, in-memory data, CSV files, or remote APIs without extra dependencies.

`VirtualDatabase` implements `DatabaseInterface`: it parses SQL (SQL:2003-level coverage — all join types, subqueries, CTEs including `WITH RECURSIVE`, window functions, `UNION`/`INTERSECT`/`EXCEPT`, aggregates) and executes it against registered tables. See `src/Database/VDB-STATUS.md` for the current coverage list.

## Quick Start

```php
use mini\Table\ArrayTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;

// Register in-memory data on the shared engine
$countries = new ArrayTable(
    new ColumnDef('code', ColumnType::Text, IndexType::Primary),
    new ColumnDef('name', ColumnType::Text),
    new ColumnDef('continent', ColumnType::Text),
);
$countries->insert(['code' => 'NO', 'name' => 'Norway', 'continent' => 'Europe']);
$countries->insert(['code' => 'SE', 'name' => 'Sweden', 'continent' => 'Europe']);
$countries->insert(['code' => 'US', 'name' => 'United States', 'continent' => 'North America']);

vdb()->getEngine()->registerTable('countries', $countries);

// Query with SQL
foreach (vdb()->query("SELECT * FROM countries WHERE continent = ?", ['Europe']) as $row) {
    echo $row->name;  // Note: rows are stdClass objects
}
```

## Architecture

### Core Interfaces (in `mini\Table\Contracts`)

- **`SetInterface`** - Membership testing for IN clauses (`has()`, `getColumns()`)
- **`TableInterface`** - Immutable, filterable table: `eq`, `lt`, `lte`, `gt`, `gte`, `in`, `like`, `or`, `union`, `except`, `columns`, `order`, `limit`, `offset`, `distinct`, `withAlias`, `load`, `exists`, ...
- **`MutableTableInterface`** - Extends TableInterface with `insert(array $row)`, `update(TableInterface $query, array $changes)`, `delete(TableInterface $query)`

### Table Implementations (in `mini\Table`)

- **`ArrayTable`** - Pure-PHP in-memory table (mutable, indexed)
- **`InMemoryTable`** - SQLite-backed in-memory table (mutable)
- **`CSVTable`** - `CSVTable::fromFile(...)` / `CSVTable::fromString(...)`
- **`JSONTable`** - Table over JSON data
- **`GeneratorTable`** - Table over a generator closure (streaming sources, remote APIs)
- **`PartialQuery`** - SQL-backed view of a real database table (implements `TableInterface`)

Composition wrappers (joins, sorting, unions, aliasing, ...) live in `mini\Table\Wrappers` — the engine assembles them from parsed SQL; you rarely construct them by hand.

### Engine Classes (in `mini\Database`)

- **`VirtualDatabase`** - The engine: implements `DatabaseInterface`, parses SQL, plans and executes against registered tables
- **`Session`** - Per-request/fiber wrapper around the engine with isolated temporary tables (`CREATE TEMPORARY TABLE ...`); this is what `vdb()` returns
- **`mini\Database\Virtual\Collation`** - Helper for creating collators (binary, nocase, locale-specific)

## TableInterface

All table implementations must be immutable - each filter method returns a new instance:

```php
$all = $table;
$active = $table->eq('status', 'active');  // $all unchanged
$sorted = $active->order('name');           // $active unchanged
```

Iteration yields row ID as key and row data as stdClass:

```php
foreach ($table as $rowId => $row) {
    // $rowId: int|string unique identifier
    // $row: stdClass with column properties
    echo $row->name;
}
```

## Registering Tables

```php
$engine = vdb()->getEngine();  // The singleton VirtualDatabase

// Any TableInterface implementation
$engine->registerTable('data', $arrayTable);
$engine->registerTable('rates', CSVTable::fromFile('rates.csv'));

// SQL on any table, no engine setup needed:
$users = PartialQuery::fromTable($generatorTable)
    ->eq('status', 'active')
    ->order('name')
    ->limit(10);
```

### Shadowing Real Tables (testing)

`db()->withTables()` creates a VirtualDatabase where named tables are replaced with mock data while all other real tables remain queryable — JOINs between mock and real data work:

```php
$testDb = db()->withTables(['users' => $mockUsers]);
$testDb->query('SELECT u.name, o.amount FROM users u JOIN orders o ON u.id = o.user_id');
```

### Model-Scoped Tables (authorization)

`registerModel()` wraps an already-registered mutable table with a `Model` class's row-level scopes (`query()`, `updatable()`, `deletable()`) and insert gate, so SQL executed through the engine respects the model's authorization:

```php
$engine->registerTable('posts', $postsTable);
$engine->registerModel('posts', Post::class);
```

## Accepting User-Provided SQL

The engine offers two guard rails. Both are **best effort** — they make runaway
queries fail loudly, they are not a security boundary:

```php
$engine->setQueryTimeout(2.0);           // seconds; QueryTimeoutException on excess
$engine->setMaxMaterializedRows(50_000); // cap rows buffered for one mutation
```

`setQueryTimeout()` is cooperative: the deadline is checked while a SELECT's rows
are pulled (every 100 rows), so it bounds long scans and runaway result sets. It
does **not** bound time spent inside a single backing-table call (a slow remote
`TableInterface` can block indefinitely — give it its own timeout), and it does
not apply to INSERT/UPDATE/DELETE, which run to completion.

`setMaxMaterializedRows()` bounds the other unbounded case. A mutation whose
source reads the table it writes (`INSERT INTO t SELECT ... FROM t`) must buffer
its source before writing, or the new rows feed back into the scan and the
statement never terminates. Buffering makes it terminate correctly; the cap
(default 1,000,000 rows) makes an enormous source fail with an actionable error
instead of exhausting memory.

Exposing SQL to untrusted callers needs more than these: combine them with a PHP
`memory_limit`, a request-level timeout, and `registerModel()` so row-level
authorization applies to SQL as well.

## Values the caller may not read: use a custom backend

"An agent may change the password but must not read it" is a job for a
**custom backend**, not for masking a column of a storage-backed table.

The reason is the consistency contract in `TableInterface`: predicates are
pushed down, so `WHERE value LIKE 's%'` becomes `$table->like('value', 's%')`
and the backend decides which rows match. Keep the real value in a storage
engine - `ArrayTable`, `InMemoryTable`, a PDO table - and *that engine* answers
predicates from the stored value, whatever your row output shows. Masking a row
inside SQLite or MySQL is not something this engine can offer, and does not try
to.

### The easy way: a GeneratorTable

A `GeneratorTable` has no storage behind it, so its filters can only run over
what the closure yields. The filter surface and the row surface are the same
data *by construction* - there is nothing to disagree with:

```php
$settings = new GeneratorTable(
    function () use ($store) {
        foreach ($store->all() as $i => [$key, $value]) {
            yield $i => (object) [
                'key'   => $key,
                'value' => $key === 'password' ? '***' : $value,
            ];
        }
    },
    new ColumnDef('key', ColumnType::Text, IndexType::Primary),
    new ColumnDef('value', ColumnType::Text),
);
```

```sql
SELECT key, value FROM settings                          -- password shows as ***
SELECT key FROM settings WHERE value LIKE 's%'           -- no rows: nothing to probe
SELECT key FROM settings WHERE value = '***'             -- matches, consistently
```

The secret is never yielded, so no predicate can reach it. For writes, add
`insert()`/`update()` that apply changes to your own store while the generator
keeps yielding the public view.

### Or keep it out of rows entirely: a write-only sink

When a value should never appear in any row, a table that accepts writes and
yields nothing is the simplest thing that works:

```php
public function insert(array $row): int|string
{
    $v = $row['new_password'] ?? null;
    if (!is_string($v) || strlen($v) < 8) {
        throw new \RuntimeException('new_password must be at least 8 characters.');
    }
    ($this->onSet)($v);          // applied somewhere the engine never reads
    return 1;
}

protected function materialize(string ...$additional): \Traversable
{
    yield from [];               // write-only: never yields a row
}
```

Validation errors thrown from `insert()` reach the caller verbatim (see
below), so an agent that supplies a bad value is told exactly why.

## Business rules on write

`VirtualDatabase` does not wrap exceptions from the table layer, so a domain
exception thrown by a custom `insert()`, `update()` or `delete()` reaches the
caller with its class and message intact. That is what makes a virtual table
a place to enforce business rules:

```php
final class SettingsTable extends ArrayTable
{
    public function update(TableInterface $query, array $changes): int
    {
        foreach ($query as $row) {
            $this->validate(array_merge((array) $row, $changes));  // resulting state
        }
        return parent::update($query, $changes);
    }
}
```

```
UPDATE settings SET value = 'neon' WHERE key = 'theme'
-> SettingValidationError: Setting 'theme' must be one of: light, dark; got 'neon'.
```

Because writes are deferred until the statement finishes reading (see
`PendingWrites`), a validation failure part-way through leaves the table
untouched rather than half-applied. Write the message the way the engine
writes its own — name the valid values, not just "invalid" — and an LLM
client can correct itself from it.

## Limits

`mini\Database\Limits` states in code what the engine is for: *sensible* SQL over
heterogeneous sources, not an unbounded RDBMS. A query that exceeds one of these
fails immediately with an error naming the limit, the value exceeded, and how to
raise it — a bug report rather than an outage, which matters under a Fiber-based
runtime where a runaway query takes every coroutine in the worker with it.

```php
$engine->setLimits(new mini\Database\Limits(
    maxJoinedTables: 8,             // tables in one query
    maxSubqueryDepth: 8,            // nesting of subqueries, derived tables, CTE bodies
    maxRecursionIterations: 10_000, // fixpoint iterations for a recursive CTE
    maxBufferedWrites: 1_000_000,   // rows one statement may buffer; null disables
));
```

`setMaxMaterializedRows()` is a shorthand for `maxBufferedWrites` — the two are
one setting, and either is readable through `getLimits()`. If you find yourself
raising several limits at once, that is a signal the work belongs in a real
database registered as a source.

## Temporary Tables

Each request/fiber gets its own `Session` with isolated temp tables:

```php
vdb()->exec("CREATE TEMPORARY TABLE tmp AS SELECT * FROM users WHERE active = 1");
foreach (vdb()->query("SELECT * FROM tmp") as $row) { ... }
```

## Helper Function

Access the engine via the `vdb()` helper (override the engine via `_config/mini/Database/VirtualDatabase.php`):

```php
$result = vdb()->query("SELECT * FROM countries WHERE continent = ?", ['Europe']);
$row = vdb()->queryOne("SELECT * FROM users WHERE id = ?", [123]);
$count = vdb()->queryField("SELECT COUNT(*) FROM products");
```
