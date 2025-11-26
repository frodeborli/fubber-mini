# Collation System

Complete collation support for Virtual Database, inspired by SQLite's collation system.

## Why Collation Matters

Different applications need different comparison rules:

```php
// Case-sensitive (BINARY)
'Alice' != 'alice'  // Different

// Case-insensitive (NOCASE)
'Alice' == 'alice'  // Same

// Locale-aware (sv_SE - Swedish)
// Sorting: a < b < ... < z < å < ä < ö
['ångström', 'zebra', 'älskar'] → ['zebra', 'ångström', 'älskar']

// Locale-aware (de_DE - German)
// Sorting: a < ä < b < ... < z
['ångström', 'zebra', 'älskar'] → ['älskar', 'ångström', 'zebra']
```

## Built-in Collators

### BinaryCollator (Default)

SQLite BINARY compatible - byte-by-byte comparison:

```php
use mini\Database\Virtual\Collators\BinaryCollator;

$collator = new BinaryCollator();

// Comparison rules:
// NULL < numbers < strings

$collator->compare(5, 10);        // -1 (numeric)
$collator->compare('10', '2');    // -1 (numeric strings)
$collator->compare('a', 'b');     // -1 (byte comparison)
$collator->compare('A', 'a');     // -1 (case-sensitive)
$collator->equals('Alice', 'alice'); // false
```

### NoCaseCollator

SQLite NOCASE compatible - case-insensitive ASCII:

```php
use mini\Database\Virtual\Collators\NoCaseCollator;

$collator = new NoCaseCollator();

$collator->equals('Alice', 'alice');  // true
$collator->equals('HELLO', 'hello');  // true
$collator->compare('Apple', 'banana'); // -1

// Numbers still compared numerically
$collator->compare('10', '2');  // 1 (10 > 2)
```

### RtrimCollator

SQLite RTRIM compatible - ignores trailing spaces:

```php
use mini\Database\Virtual\Collators\RtrimCollator;

$collator = new RtrimCollator();

$collator->equals('hello', 'hello   ');  // true
$collator->equals('  hello', 'hello');   // false (leading matters)

// Useful for fixed-width fields
$collator->equals('NAME      ', 'NAME');  // true
```

### LocaleCollator

Full Unicode support using PHP's Collator (requires intl extension):

```php
use mini\Database\Virtual\Collators\LocaleCollator;

// Swedish
$sv = new LocaleCollator('sv_SE');
$names = ['Ängel', 'Zebra', 'Älskar'];
usort($names, fn($a, $b) => $sv->compare($a, $b));
// Result: ['Zebra', 'Älskar', 'Ängel']

// German
$de = new LocaleCollator('de_DE');
usort($names, fn($a, $b) => $de->compare($a, $b));
// Result: ['Älskar', 'Ängel', 'Zebra']

// English
$en = new LocaleCollator('en_US');
usort($names, fn($a, $b) => $en->compare($a, $b));
// Result: ['Älskar', 'Ängel', 'Zebra']

// Configure sensitivity
$en->setStrength(\Collator::PRIMARY);  // Ignore case and accents
$en->equals('café', 'CAFE');  // true
```

## Usage in Virtual Database

### Set Default Collation

```php
use mini\Database\VirtualDatabase;
use mini\Database\Virtual\Collators\NoCaseCollator;

// All comparisons use NOCASE by default
$vdb = new VirtualDatabase(new NoCaseCollator());

$vdb->registerTable('users', CsvTable::fromFile('users.csv'));

// Case-insensitive WHERE
$vdb->query("SELECT * FROM users WHERE name = 'ALICE'");  // Matches 'alice', 'Alice', etc.
```

### Per-Table Collation

```php
use mini\Database\Virtual\{VirtualTable, OrderInfo};
use mini\Database\Virtual\Collators\LocaleCollator;

$vdb->registerTable('swedish_names', new VirtualTable(
    selectFn: function($ast, $collator) {
        // Override with Swedish collation
        $sv = new LocaleCollator('sv_SE');

        yield new OrderInfo(
            column: 'name',
            desc: false,
            collator: $sv  // Tell engine: data sorted using Swedish rules
        );

        // Data pre-sorted by Swedish rules
        foreach ($this->getSwedishNames() as $row) {
            yield $row;
        }
    }
));
```

### Backend-Specific Collation

Tell engine what collation backend used for sorting:

```php
$vdb->registerTable('api_data', new VirtualTable(
    selectFn: function($ast, $collator) use ($apiClient) {
        // API sorts using case-insensitive comparison
        $data = $apiClient->getUsers(sortBy: 'name');

        yield new OrderInfo(
            column: 'name',
            desc: false,
            collator: new NoCaseCollator()  // API uses case-insensitive
        );

        yield from $data;
    }
));

// Engine knows to use NOCASE for comparisons
$vdb->query("SELECT * FROM api_data WHERE name = 'ALICE' ORDER BY name");
// - WHERE uses NOCASE (matches 'alice', 'Alice')
// - ORDER BY streams results (backend already sorted with NOCASE)
```

## How Collation Affects Execution

### WHERE Clause Comparisons

Collator used for all value comparisons:

```php
// BINARY (default)
WHERE name = 'Alice'     // Only matches 'Alice'

// NOCASE
WHERE name = 'Alice'     // Matches 'alice', 'ALICE', 'Alice'

// Swedish locale
WHERE name > 'Ängel'     // Swedish alphabetical comparison
```

### ORDER BY Sorting

Collator used for sorting:

```php
// BINARY
ORDER BY name            // A < B < ... < Z < a < b < ... < z

// NOCASE
ORDER BY name            // A,a < B,b < ... < Z,z

// Swedish (sv_SE)
ORDER BY name            // a < b < ... < z < å < ä < ö

// German (de_DE)
ORDER BY name            // a < ä < b < ... < z
```

### IN Operations

Collator used for membership testing:

```php
// NOCASE
WHERE name IN ('alice', 'bob')  // Matches 'ALICE', 'Alice', 'BOB', 'Bob'
```

## Type Coercion Rules

All collators follow SQLite-inspired type ordering:

```
NULL < numbers < strings
```

### Numeric Comparison

Both values numeric → numeric comparison:

```php
$collator->compare(5, 10);      // -1 (5 < 10)
$collator->compare('5', '10');  // -1 (numeric strings)
$collator->compare(5.5, 10);    // -1 (mixed int/float)
```

### String Comparison

At least one non-numeric → string comparison (collation applies):

```php
// BINARY
$binary->compare('10', '2');      // -1 ('10' < '2' numerically)
$binary->compare('10', 'hello');  // Uses type ordering

// NOCASE
$nocase->compare('Apple', 'banana');  // -1 (case-insensitive)
```

## Advanced: Custom Collators

Implement `CollatorInterface` for custom rules:

```php
use mini\Database\Virtual\CollatorInterface;

class CustomCollator implements CollatorInterface
{
    public function compare(mixed $a, mixed $b): int
    {
        // Custom comparison logic
        // Return: <0 if a<b, 0 if equal, >0 if a>b
    }

    public function equals(mixed $a, mixed $b): bool
    {
        // Custom equality logic
        // Often faster than compare() === 0
    }

    public function getName(): string
    {
        return 'CUSTOM';
    }
}
```

### Example: Natural Sort Collator

Sort strings with numbers naturally (file1, file2, file10 vs file1, file10, file2):

```php
class NaturalSortCollator implements CollatorInterface
{
    public function compare(mixed $a, mixed $b): int
    {
        return strnatcasecmp((string)$a, (string)$b);
    }

    public function equals(mixed $a, mixed $b): bool
    {
        return strcasecmp((string)$a, (string)$b) === 0;
    }

    public function getName(): string
    {
        return 'NATURAL';
    }
}

// Usage
$vdb = new VirtualDatabase(new NaturalSortCollator());
$vdb->query("SELECT * FROM files ORDER BY filename");
// Result: file1.txt, file2.txt, file10.txt (not file1.txt, file10.txt, file2.txt)
```

## Performance Considerations

### Collation Speed

Fastest → Slowest:
1. **BinaryCollator** - Simple byte/numeric comparison
2. **NoCaseCollator** - ASCII case folding
3. **RtrimCollator** - String trimming overhead
4. **LocaleCollator** - Full Unicode rules (slowest but most correct)

### Choosing the Right Collation

- **BINARY**: Maximum performance, case matters
- **NOCASE**: User-facing text (names, emails)
- **RTRIM**: Fixed-width legacy data
- **Locale**: International applications, proper alphabetical sorting

### Optimization Tips

```php
// BAD: LocaleCollator for every table
$vdb = new VirtualDatabase(new LocaleCollator('en_US'));

// GOOD: BINARY default, LocaleCollator only where needed
$vdb = new VirtualDatabase(new BinaryCollator());

$vdb->registerTable('user_names', new VirtualTable(
    defaultCollator: new LocaleCollator('en_US'),  // Only this table
    selectFn: ...
));
```

## Compatibility

### SQLite Equivalents

| Mini Collator | SQLite Collation |
|---------------|------------------|
| BinaryCollator | BINARY |
| NoCaseCollator | NOCASE |
| RtrimCollator | RTRIM |
| LocaleCollator | Custom (via C extension) |

### MySQL Equivalents

| Mini Collator | MySQL Collation |
|---------------|------------------|
| BinaryCollator | utf8mb4_bin |
| NoCaseCollator | utf8mb4_general_ci |
| LocaleCollator('sv_SE') | utf8mb4_swedish_ci |
| LocaleCollator('de_DE') | utf8mb4_german2_ci |

## Testing Collations

```php
// Test case-insensitive search
$vdb = new VirtualDatabase(new NoCaseCollator());
$vdb->registerTable('users', CsvTable::fromArray([
    ['id' => 1, 'name' => 'Alice'],
    ['id' => 2, 'name' => 'BOB'],
]));

$result = $vdb->query("SELECT * FROM users WHERE name = 'alice'");
// Returns: Alice (case-insensitive match)

// Test locale sorting
$vdb = new VirtualDatabase(new LocaleCollator('sv_SE'));
$vdb->registerTable('names', CsvTable::fromArray([
    ['name' => 'Ängel'],
    ['name' => 'Älskar'],
    ['name' => 'Zebra'],
]));

$result = $vdb->query("SELECT * FROM names ORDER BY name");
// Returns: Zebra, Älskar, Ängel (Swedish alphabetical order)
```

## Summary

- **4 built-in collators**: BINARY, NOCASE, RTRIM, Locale-aware
- **Affects all comparisons**: WHERE, ORDER BY, IN, =, <, >, etc.
- **SQLite-compatible**: Same type coercion rules
- **Extensible**: Implement `CollatorInterface` for custom rules
- **Production-ready**: Full Unicode support via PHP intl
- **Performance-conscious**: Choose collator based on needs

The collation system makes VirtualDatabase suitable for international applications with proper text handling!
