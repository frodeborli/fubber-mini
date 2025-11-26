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
- **`OrderInfo`** - Optional metadata about backend ordering and collation (implements ResultInterface)
- **`Row`** - Data row with unique ID (implements ResultInterface, required)
- **`WhereEvaluator`** - Evaluates WHERE clause AST against row data using collation
- **`VirtualTableException`** - Thrown when virtual table violates implementation contract
- **`Collation`** - Helper functions for creating and comparing with PHP's `\Collator`
  - `binary()` - Case-sensitive, byte-by-byte (default)
  - `nocase()` - Case-insensitive ASCII
  - `locale(string)` - Full Unicode locale-aware (e.g., 'sv_SE', 'de_DE')
  - `compare()` - Compare two values with NULL and numeric handling
  - `fromName()` - Create collator from name ('BINARY', 'NOCASE', or locale)
  - `toName()` - Get collation name from collator instance

### Execution Model

1. **Parse SQL** → AST using `SqlParser`
2. **Call virtual table** → Get generator (optionally yields `OrderInfo` first)
3. **Smart execution:**
   - **Stream** if no ORDER BY or ORDER BY matches backend ordering
   - **Materialize** otherwise (load all, sort, output)
4. **Always re-apply WHERE** - Engine guarantees correctness
5. **Early stop** when LIMIT reached (streaming mode)

## Collation System

VirtualDatabase uses **collation** to determine how text is compared and sorted. This affects WHERE clauses, ORDER BY, and IN operations.

### Why Collation Matters

```php
// BINARY (default) - Case-sensitive
'Alice' != 'alice'

// NOCASE - Case-insensitive
'Alice' == 'alice'

// Swedish locale - ä, ö at end of alphabet
['Zebra', 'Ärlig', 'Östen'] → ordered as shown (Swedish alphabet)
```

See **[COLLATION.md](COLLATION.md)** for complete documentation.

### Setting Default Collation

```php
use mini\Database\VirtualDatabase;
use mini\Database\Virtual\Collation;

// All tables use case-insensitive comparison by default
$vdb = new VirtualDatabase(Collation::nocase());

// Now WHERE name = 'ALICE' matches 'alice', 'Alice', 'ALICE'
```

### Per-Table Collation

```php
use mini\Database\Virtual\Collation;

$vdb->registerTable('swedish_names', new VirtualTable(
    defaultCollator: Collation::locale('sv_SE'),
    selectFn: function($ast, $collator) {
        // This table uses Swedish sorting rules
        foreach ($this->getSwedishNames() as $id => $columns) {
            yield new Row($id, $columns);
        }
    }
));
```

### Available Collations

| Function | Use Case | Example |
|----------|----------|---------|
| `Collation::binary()` | Maximum performance, case matters | Identifiers, codes |
| `Collation::nocase()` | User-facing text | Names, emails |
| `Collation::locale('sv_SE')` | International apps | Proper alphabetical sorting |

### Collation in OrderInfo

Tell the engine which collation your backend used:

```php
selectFn: function($ast, $collator) use ($apiClient) {
    // API sorts using case-insensitive comparison
    $data = $apiClient->getUsers(sortBy: 'name');

    yield new OrderInfo(
        column: 'name',
        desc: false,
        collation: 'NOCASE'  // Backend used NOCASE
    );

    foreach ($data as $item) {
        yield new Row($item['id'], $item);
    }
}
```

This ensures the engine uses the correct collation for:
- **Matching ORDER BY** (to enable streaming) - **CRITICAL:** Engine checks collation compatibility!
- Re-applying WHERE filters
- Additional sorting if needed

**Collation Compatibility:**

The engine will **only stream results** if ALL of these match:
1. Column name matches
2. Direction matches (ASC/DESC)
3. **Collation matches** (OrderInfo collation name === required collation name)

If collations don't match, the engine **automatically materializes and re-sorts** with the correct collation. This guarantees correctness.

Example:
```php
// VirtualDatabase uses NOCASE (case-insensitive)
$vdb = new VirtualDatabase(Collation::nocase());

// Backend reports BINARY collation (case-sensitive)
yield new OrderInfo(
    column: 'name',
    desc: false,
    collation: 'BINARY'  // Different from VDB's NOCASE!
);

// Result: Engine detects collation mismatch
// → Materializes all rows
// → Re-sorts with NOCASE
// → Guarantees correct case-insensitive ordering
```

## Creating Virtual Tables

### ⚠️ Row Instances Required

**All virtual tables MUST yield `Row` instances.** This is enforced at runtime.

```php
use mini\Database\Virtual\{Row, OrderInfo};

// SELECT functions must return: iterable<ResultInterface>
// This means: yield OrderInfo (optional) then Row instances (required)

// ✅ CORRECT - yields Row instances (implements ResultInterface)
yield new Row($id, ['name' => 'Alice', 'age' => 30]);

// ✅ CORRECT - with optional OrderInfo first
yield new OrderInfo(column: 'id', desc: false, collator: $collator);
yield new Row($id, ['name' => 'Alice', 'age' => 30]);

// ❌ WRONG - will throw VirtualTableException
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
    selectFn: function(SelectStatement $ast, $collator): iterable {
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
    selectFn: function(SelectStatement $ast, $collator): iterable {
        // Data is pre-sorted by 'id' ascending
        yield new OrderInfo(column: 'id', desc: false, collation: 'BINARY');

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
    selectFn: function(SelectStatement $ast, $collator): iterable {
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
                desc: $orderBy['direction'] === 'DESC',
                collation: 'BINARY'  // API uses case-sensitive sorting
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
    skipped: 1000,              // Rows already skipped by backend (offset)
    collation: 'BINARY'         // Collation used by backend (important!)
);
```

**When to yield `OrderInfo`:**

- Backend data is sorted and you want streaming execution
- Backend applied OFFSET and you want to avoid double-application
- Backend called remote API with ordering parameters
- You want to inform engine about backend's collation

**When NOT to yield `OrderInfo`:**

- Data is unsorted
- You want engine to handle all ordering
- Simpler is better for your use case

**About the collation parameter:**

The collation tells the engine which text comparison rules the backend used for sorting. This is critical for:
- **Matching detection** - Engine can only stream if collations match
- **WHERE re-application** - Engine uses same rules as backend
- **Additional sorting** - If needed, uses compatible collation

Supported collation names:
- `'BINARY'` - Case-sensitive, byte-order comparison (default)
- `'NOCASE'` - Case-insensitive ASCII comparison
- Locale codes - e.g., `'sv_SE'`, `'de_DE'`, `'en_US'` for locale-specific sorting

If you don't specify collation, it defaults to 'BINARY'.

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
    selectFn: function($ast, $collator): iterable {
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

1. **No WHERE parsing needed** - VirtualDatabase does it for you with collation support
2. **Consistent behavior** - Same WHERE evaluation as SELECT
3. **Simple implementation** - Just handle the row IDs provided
4. **Automatic collation** - WHERE uses proper text comparison rules

## Performance Tips

1. **Yield `OrderInfo` when data is sorted** - Enables streaming
2. **Don't buffer unnecessarily** - Use generators
3. **Let engine handle WHERE** - Focus on data access optimization
4. **Optimize backend calls** - Inspect AST for filters/limits
5. **Use offset wisely** - Report `skipped` to avoid double-offset

## Complete Example

```php
use mini\Database\VirtualDatabase;
use mini\Database\Virtual\{VirtualTable, OrderInfo, DmlResult};
use mini\Parsing\SQL\AST\{SelectStatement, InsertStatement};

$vdb = new VirtualDatabase();

// Remote API table with full optimization
$vdb->registerTable('github_repos', new VirtualTable(
    selectFn: function(SelectStatement $ast, $collator): iterable {
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

        // Tell engine we applied ordering (GitHub API uses case-sensitive sorting)
        if ($sort) {
            yield new OrderInfo(
                column: $sort,
                desc: $desc,
                collation: 'BINARY'  // GitHub API uses case-sensitive sorting
            );
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

## Benefits Over src/Tables

1. **Simpler** - Closures instead of class hierarchies
2. **More powerful** - Full SQL parsing with AST
3. **Smart execution** - Streaming vs materialization
4. **Flexible** - Easy to optimize or stay simple
5. **SQL interface** - Works with PartialQuery automatically
6. **Collation-aware** - Proper text comparison for international apps

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

- **[COLLATION.md](COLLATION.md)** - Complete collation system guide
- **SQL Parser** - See `src/Parsing/SQL/` for AST structure
- **Examples** - See `examples/virtual-database.example.php`

## Future Enhancements

- Support for JOIN operations
- Aggregate functions (COUNT, SUM, AVG, MIN, MAX)
- GROUP BY and HAVING clauses
- Subquery support in WHERE clauses
