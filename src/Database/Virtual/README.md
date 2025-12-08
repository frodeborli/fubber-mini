# Virtual Database System

SQL interface to non-SQL data sources with smart execution optimizations.

## Quick Start

```php
use mini\Database\VirtualDatabase;
use mini\Database\Virtual\{VirtualTable, OrderInfo, CsvTable};

// Access via helper function (recommended)
// Configure in _config/virtual-database.php
foreach (vdb()->query("SELECT * FROM users WHERE age > ?", [25]) as $row) {
    echo $row['name'];
}

// Or create directly
$vdb = new VirtualDatabase();

// Simple: CSV file
$vdb->registerTable('countries', CsvTable::fromFile('data/countries.csv'));

// Simple: In-memory data
$vdb->registerTable('users', CsvTable::fromArray([
    ['id' => 1, 'name' => 'Alice', 'age' => 30],
    ['id' => 2, 'name' => 'Bob', 'age' => 25],
]));

// Query like normal SQL
foreach ($vdb->query("SELECT * FROM users WHERE age > ?", [25]) as $row) {
    echo $row['name'];
}
```

## Architecture

### Core Classes

- **`VirtualDatabase`** - Implements `DatabaseInterface`, parses SQL, coordinates execution
- **`VirtualTable`** - Closure-based table definition (SELECT/INSERT/UPDATE/DELETE)
- **`ResultInterface`** - Marker interface for SELECT results (OrderInfo and Row)
- **`OrderInfo`** - Optional metadata about backend ordering (implements ResultInterface)
- **`Row`** - Data row with unique ID (implements ResultInterface, required)
- **`WhereEvaluator`** - Evaluates WHERE clause AST against row data
- **`VirtualTableException`** - Thrown when virtual table violates implementation contract

### Execution Model

1. **Parse SQL** → AST using `SqlParser`
2. **Call virtual table** → Get generator (optionally yields `OrderInfo` first)
3. **Smart execution:**
   - **Stream** if no ORDER BY or ORDER BY matches backend ordering
   - **Materialize** otherwise (load all, sort, output)
4. **Always re-apply WHERE** - Engine guarantees correctness
5. **Early stop** when LIMIT reached (streaming mode)

## Creating Virtual Tables

### Row Instances Required

**All virtual tables MUST yield `Row` instances.** This is enforced at runtime.

```php
use mini\Database\Virtual\{Row, OrderInfo};

// SELECT functions must return: iterable<ResultInterface>
// This means: yield OrderInfo (optional) then Row instances (required)

// CORRECT - yields Row instances (implements ResultInterface)
yield new Row($id, ['name' => 'Alice', 'age' => 30]);

// CORRECT - with optional OrderInfo first
yield new OrderInfo(column: 'id', desc: false);
yield new Row($id, ['name' => 'Alice', 'age' => 30]);

// WRONG - will throw VirtualTableException
yield ['name' => 'Alice', 'age' => 30];
yield $id => ['name' => 'Alice', 'age' => 30];
```

**Why Row instances?**
- **Enforces unique row IDs** - Required for UPDATE/DELETE operations
- **Type-safe contract** - `ResultInterface` provides OOP type safety
- **Runtime validation** - Helpful error messages if you forget
- **Clear semantics** - OrderInfo and Row both implement same interface

### Lazy Developer (No Optimization)

**IMPORTANT:** Must yield Row instances - this is enforced at runtime!

```php
use mini\Database\Virtual\Row;

$vdb->registerTable('products', new VirtualTable(
    selectFn: function(SelectStatement $ast): iterable {
        // Engine will handle WHERE, ORDER BY, LIMIT
        // Must yield Row instances with unique IDs
        foreach ($this->getAllProducts() as $id => $columns) {
            yield new Row($id, $columns);
        }
    }
));
```

### Smart Developer (Optimize Ordering)

Tell engine about backend ordering:

```php
$vdb->registerTable('products', new VirtualTable(
    selectFn: function(SelectStatement $ast): iterable {
        // Data is pre-sorted by 'id' ascending
        yield new OrderInfo(column: 'id', desc: false);

        // Engine can now stream and early-stop
        // Still must yield Row instances!
        foreach ($this->getProductsSortedById() as $id => $columns) {
            yield new Row($id, $columns);
        }
    }
));
```

### Expert Developer (Full Optimization)

Inspect AST and optimize:

```php
$vdb->registerTable('api_data', new VirtualTable(
    selectFn: function(SelectStatement $ast): iterable {
        // Inspect AST to optimize API calls
        $filters = $this->extractFilters($ast->where);
        $orderBy = $ast->orderBy[0] ?? null;
        $limit = $ast->limit;

        // Call API with filters
        $url = 'https://api.example.com/data?' . http_build_query([
            'filter' => $filters,
            'sort' => $orderBy ? $orderBy['column']->name : null,
            'limit' => $limit,
        ]);

        $data = json_decode(file_get_contents($url), true);

        // Tell engine we applied ordering
        if ($orderBy) {
            yield new OrderInfo(
                column: $orderBy['column']->name,
                desc: $orderBy['direction'] === 'DESC'
            );
        }

        // Must yield Row instances!
        foreach ($data as $item) {
            yield new Row($item['id'], $item);
        }
    }
));
```

## OrderInfo Details

```php
new OrderInfo(
    column: 'birthday',         // Primary sort column
    desc: false,                // true = DESC, false = ASC
    skipped: 0,                 // Backend handled WHERE and OFFSET (see below)
);
```

**The `skipped` parameter controls WHERE and OFFSET handling:**

| `skipped` value | WHERE clause | OFFSET |
|-----------------|--------------|--------|
| `null` (default) | VirtualDatabase applies | VirtualDatabase handles all |
| `0` | Backend handled (trusted) | VirtualDatabase handles all |
| `N` (positive int) | Backend handled (trusted) | Backend skipped N rows, VirtualDatabase skips remainder |

**Why this matters for collation:**

When `skipped` is an integer, VirtualDatabase trusts that the backend correctly evaluated WHERE clauses. This allows table implementations to use custom collation for comparisons:

```php
// Table implementation with custom collation
$vdb->registerTable('users', new VirtualTable(
    selectFn: function(SelectStatement $ast): iterable {
        $collator = new \Collator('sv_SE');  // Swedish collation

        // Filter rows using custom collation
        $filtered = [];
        foreach ($this->getAllRows() as $id => $row) {
            if ($this->matchesWhere($row, $ast->where, $collator)) {
                $filtered[$id] = $row;
            }
        }

        // Sort using custom collation
        uasort($filtered, fn($a, $b) => $collator->compare($a['name'], $b['name']));

        // Tell engine: we handled WHERE, skipped 0 rows
        yield new OrderInfo(column: 'name', desc: false, skipped: 0);

        foreach ($filtered as $id => $row) {
            yield new Row($id, $row);
        }
    }
));
```

**When to yield `OrderInfo`:**

- Backend data is sorted and you want streaming execution
- Backend applied OFFSET and you want to avoid double-application
- Backend evaluated WHERE with custom collation (`skipped: 0`)

**When NOT to yield `OrderInfo`:**

- Data is unsorted and unfiltered
- You want engine to handle all filtering and ordering
- Simpler is better for your use case

## Streaming vs Materialization

### Streaming (Efficient)

**When:** No ORDER BY, or ORDER BY matches `OrderInfo`

**Benefits:**
- Memory efficient (rows not buffered)
- Early stop when LIMIT reached
- Ideal for large datasets

**Example:**
```sql
-- Data sorted by id, engine can stream
SELECT * FROM users ORDER BY id LIMIT 10
```

### Materialization (Necessary)

**When:** ORDER BY doesn't match `OrderInfo`

**Process:**
1. Load all matching rows into memory
2. Sort entire result set
3. Apply LIMIT/OFFSET
4. Output rows

**Example:**
```sql
-- Data sorted by id, but query wants age
-- Must load all, sort by age
SELECT * FROM users ORDER BY age LIMIT 10
```

## INSERT/UPDATE/DELETE

The DML API is beautifully simple - **VirtualDatabase handles WHERE evaluation**, so you just operate on row IDs!

```php
new VirtualTable(
    selectFn: function($ast): iterable {
        // IMPORTANT: Must yield Row instances for UPDATE/DELETE to work
        foreach ($this->getData() as $id => $columns) {
            yield new Row($id, $columns);
        }
    },

    insertFn: function(array $row): string|int {
        // Insert a single row, return generated ID
        $id = $this->insertRow($row);
        return $id;
    },

    updateFn: function(array $rowIds, array $changes): int {
        // Update specific rows by ID - WHERE already evaluated!
        // Just apply the changes to these row IDs
        $affected = 0;
        foreach ($rowIds as $id) {
            if ($this->updateRow($id, $changes)) {
                $affected++;
            }
        }
        return $affected;
    },

    deleteFn: function(array $rowIds): int {
        // Delete specific rows by ID - WHERE already evaluated!
        // Just delete these row IDs
        $affected = 0;
        foreach ($rowIds as $id) {
            if ($this->deleteRow($id)) {
                $affected++;
            }
        }
        return $affected;
    }
);
```

**Key Benefits:**

1. **No WHERE parsing needed** - VirtualDatabase evaluates WHERE for you
2. **Consistent behavior** - Same WHERE evaluation as SELECT
3. **Simple implementation** - Just handle the row IDs provided

## Performance Tips

1. **Yield `OrderInfo` when data is sorted** - Enables streaming
2. **Don't buffer unnecessarily** - Use generators
3. **Let engine handle WHERE** - Focus on data access optimization
4. **Optimize backend calls** - Inspect AST for filters/limits
5. **Use offset wisely** - Report `skipped` to avoid double-offset

## Complete Example

```php
use mini\Database\VirtualDatabase;
use mini\Database\Virtual\{VirtualTable, OrderInfo, Row};
use mini\Parsing\SQL\AST\SelectStatement;

$vdb = new VirtualDatabase();

// Remote API table with full optimization
$vdb->registerTable('github_repos', new VirtualTable(
    selectFn: function(SelectStatement $ast): iterable {
        // Extract filters from WHERE
        $org = null;
        if ($ast->where) {
            // ... parse WHERE to find org filter
        }

        // Extract ordering
        $sort = null;
        $desc = false;
        if ($ast->orderBy) {
            $sort = $ast->orderBy[0]['column']->name;
            $desc = $ast->orderBy[0]['direction'] === 'DESC';
        }

        // Call GitHub API
        $url = "https://api.github.com/orgs/$org/repos?" . http_build_query([
            'sort' => $sort ?? 'updated',
            'direction' => $desc ? 'desc' : 'asc',
            'per_page' => $ast->limit ?? 30,
        ]);

        $repos = json_decode(file_get_contents($url), true);

        // Tell engine we applied ordering
        if ($sort) {
            yield new OrderInfo(column: $sort, desc: $desc);
        }

        foreach ($repos as $repo) {
            yield new Row(
                $repo['id'],
                [
                    'id' => $repo['id'],
                    'name' => $repo['name'],
                    'stars' => $repo['stargazers_count'],
                    'updated' => $repo['updated_at'],
                ]
            );
        }
    }
));

// Query with full optimization
$repos = $vdb->query(
    "SELECT name, stars FROM github_repos WHERE org = ? ORDER BY stars DESC LIMIT 10",
    ['anthropics']
);

foreach ($repos as $repo) {
    echo "{$repo['name']}: {$repo['stars']} stars\n";
}
```

## Helper Function

Access VirtualDatabase via the `vdb()` helper:

```php
// Configure in _config/virtual-database.php
$result = vdb()->query("SELECT * FROM countries WHERE continent = ?", ['Europe']);
$row = vdb()->queryOne("SELECT * FROM users WHERE id = ?", [123]);
$count = vdb()->queryField("SELECT COUNT(*) FROM products");
```

See `examples/virtual-database.example.php` for configuration examples.

## Additional Documentation

- **SQL Parser** - See `src/Parsing/SQL/` for AST structure
- **Examples** - See `examples/virtual-database.example.php`

## Current Limitations

### Correlated Subqueries Not Supported

Subqueries that reference columns from the outer query are not yet supported:

```sql
-- Works (non-correlated)
SELECT * FROM users WHERE id IN (SELECT user_id FROM orders WHERE status = 'active')

-- Doesn't work (correlated - references outer table)
SELECT * FROM users WHERE id IN (SELECT user_id FROM orders WHERE orders.org_id = users.org_id)
```

## Future Enhancements

- **Correlated subqueries** - WhereEvaluator needs row context from outer queries:
  ```php
  // Proposed API
  $evaluator->withRow('users', $usersRow)->withRow('orders', $ordersRow)
  ```
  This would allow resolving `table.column` references against provided row contexts.
- Support for JOIN operations
- Aggregate functions (COUNT, SUM, AVG, MIN, MAX)
- GROUP BY and HAVING clauses
- Collation support for unindexed table implementations (CsvTable, in-memory arrays)
