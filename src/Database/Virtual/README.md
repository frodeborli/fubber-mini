# Virtual Database System

SQL interface to non-SQL data sources via `TableInterface`.

## Quick Start

```php
use mini\Database\VirtualDatabase;
use mini\Table\FilteredTable;

$vdb = new VirtualDatabase();

// Register a PartialQuery as a virtual table (SQL-backed view)
$vdb->registerTable('active_users', db()->from('users')->eq('active', 1));

// Register in-memory data using FilteredTable
$vdb->registerTable('countries', new FilteredTable(
    source: new class implements TableInterface {
        public function getIterator(): Traversable {
            yield 'no' => (object)['code' => 'NO', 'name' => 'Norway', 'continent' => 'Europe'];
            yield 'se' => (object)['code' => 'SE', 'name' => 'Sweden', 'continent' => 'Europe'];
            yield 'us' => (object)['code' => 'US', 'name' => 'United States', 'continent' => 'North America'];
        }
        // ... implement other TableInterface methods
    }
));

// Query with SQL
foreach ($vdb->query("SELECT * FROM countries WHERE continent = ?", ['Europe']) as $row) {
    echo $row->name;  // Note: rows are stdClass objects
}
```

## Architecture

### Core Interfaces (in `mini\Table`)

- **`SetInterface`** - Membership testing for IN clauses (`has()`)
- **`TableInterface`** - Read-only table with filtering (`eq`, `lt`, `gt`, `in`, `like`, `union`, `except`), ordering, and pagination
- **`MutableTableInterface`** - Extends TableInterface with `insert()`, `update()`, `delete()`

### Classes

- **`VirtualDatabase`** - Implements `DatabaseInterface`, parses SQL, translates to TableInterface calls
- **`FilteredTable`** - Composable wrapper that delegates filters to source, handles ordering/limit/offset
- **`UnionTable`** - Union of two tables with filter pushdown to both sides
- **`Set`** - Simple in-memory set for IN clauses
- **`Collation`** - Helper for creating collators (binary, nocase, locale-specific)

## TableInterface

All table implementations must be immutable - each method returns a new instance:

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

### Methods

```php
interface TableInterface extends SetInterface, IteratorAggregate, Countable
{
    // Filtering
    public function eq(string $column, int|float|string|null $value): TableInterface;
    public function lt(string $column, int|float|string $value): TableInterface;
    public function lte(string $column, int|float|string $value): TableInterface;
    public function gt(string $column, int|float|string $value): TableInterface;
    public function gte(string $column, int|float|string $value): TableInterface;
    public function in(string $column, SetInterface $values): TableInterface;
    public function like(string $column, string $pattern): TableInterface;

    // Set operations (for OR and NOT)
    public function union(TableInterface $other): TableInterface;
    public function except(TableInterface $other): TableInterface;

    // Projection (for subqueries)
    public function columns(string ...$columns): TableInterface;
    public function has(string|int|float|stdClass $member): bool;

    // Ordering and pagination
    public function order(string $spec): TableInterface;
    public function limit(int $n): TableInterface;
    public function offset(int $n): TableInterface;
}
```

## FilteredTable

Composable wrapper that delegates filter methods to source:

```php
use mini\Table\FilteredTable;

class CsvTable implements TableInterface
{
    public function eq(string $column, $value): TableInterface
    {
        return new FilteredTable(
            source: $this,
            filter: fn($row) => ($row->$column ?? null) === $value,
            // orderFn: optional - return ordered TableInterface if source has index
        );
    }

    // Other filter methods delegate similarly...
}
```

### Order Optimization

FilteredTable supports efficient ordering via `orderFn`:

```php
new FilteredTable(
    source: $this,
    filter: fn($row) => $row->status === 'active',
    orderFn: fn($spec) => $this->hasIndexFor($spec) ? $this->order($spec) : null,
);
```

- If `orderFn` returns a `TableInterface`, FilteredTable streams from it (pre-sorted)
- If `orderFn` returns `null`, FilteredTable buffers and sorts in-memory

## UnionTable

Handles OR operations by pushing filters to both sides:

```php
// WHERE status = 'active' OR role = 'admin'
$result = $table->eq('status', 'active')->union($table->eq('role', 'admin'));

// Further filtering pushes to both sides:
$result->gt('age', 18);  // Becomes: (active AND age>18) UNION (admin AND age>18)
```

## Using PartialQuery as a Virtual Table

Since `PartialQuery` implements `TableInterface`, you can register SQL-backed views:

```php
// Create a filtered view of a real table
$vdb->registerTable('friends',
    db()->from('users')->eq('relationship', 'friend')
);

// Query it with additional filters
$vdb->query("SELECT * FROM friends WHERE age > ?", [25]);
// Translates to: SELECT * FROM users WHERE relationship = 'friend' AND age > 25
```

## MutableTableInterface

For tables that support write operations:

```php
interface MutableTableInterface extends TableInterface
{
    public function insert(array $row): int|string;
    public function update(array $changes): int;
    public function delete(): int;
}
```

UPDATE and DELETE operate on the current filtered state:

```php
$table->eq('status', 'inactive')->delete();  // DELETE WHERE status = 'inactive'
$table->gt('age', 65)->update(['retired' => true]);  // UPDATE WHERE age > 65
```

## SQL Translation

VirtualDatabase translates SQL WHERE clauses to TableInterface method calls:

| SQL | TableInterface |
|-----|----------------|
| `column = ?` | `eq('column', $value)` |
| `column < ?` | `lt('column', $value)` |
| `column <= ?` | `lte('column', $value)` |
| `column > ?` | `gt('column', $value)` |
| `column >= ?` | `gte('column', $value)` |
| `column IS NULL` | `eq('column', null)` |
| `column IS NOT NULL` | `except(eq('column', null))` |
| `column != ?` | `except(eq('column', $value))` |
| `column IN (...)` | `in('column', new Set([...]))` |
| `column LIKE ?` | `like('column', $pattern)` |
| `a AND b` | Chain: `->eq(...)->gt(...)` |
| `a OR b` | `union()` |
| `NOT a` | `except(a)` |
| `ORDER BY col DESC` | `order('col DESC')` |
| `LIMIT n` | `limit(n)` |

## Helper Function

Access VirtualDatabase via the `vdb()` helper:

```php
// Configure in _config/mini/Database/VirtualDatabase.php
$result = vdb()->query("SELECT * FROM countries WHERE continent = ?", ['Europe']);
$row = vdb()->queryOne("SELECT * FROM users WHERE id = ?", [123]);
$count = vdb()->queryField("SELECT COUNT(*) FROM products");
```
