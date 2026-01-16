# VirtualDatabase Performance Ideas

Ideas for optimizing VirtualDatabase query execution.

## 1. Copy-on-Write Context Arrays

**Problem:** Context lookup in nested queries currently requires checking multiple key formats (qualified `table.col` vs unqualified `col`) and handling outer references.

**Solution:** Leverage PHP's copy-on-write (COW) semantics for arrays by storing values under both qualified and unqualified keys:

```php
// Outer query builds context with both key formats
$context = [
    'users.id' => 123,
    'id' => 123,
    'users.name' => 'Frode',
    'name' => 'Frode',
];

// Pass to subquery - no copy yet (COW)
$innerContext = $context;

// Inner query overwrites local columns - triggers COW only now
$innerContext['orders.id'] = 456;
$innerContext['id'] = 456;  // Shadows outer 'id'
$innerContext['orders.total'] = 99.95;
$innerContext['total'] = 99.95;
```

**Benefits:**
- Lookup is O(1): just `$context['id']` returns the innermost scope's value
- Outer qualified names still accessible: `$context['users.id']` returns 123
- PHP COW means no memory copy until inner query writes
- No iteration or fallback logic needed during evaluation

**Current code pattern to replace:**
```php
// In evaluateWhereWithContext and similar:
if (isset($outerContext[$key]) || array_key_exists($key, $outerContext)) {
    return $outerContext[$key];
}
// Then try qualified name...
// Then try without qualifier...
```

**New pattern:**
```php
// Just direct lookup - shadowing handled by array structure
return $context[$key] ?? $context["$tableAlias.$key"] ?? null;
```

**Implementation notes:**
- Context is **only created when a subquery needs it** - most queries have no context overhead
- When a correlated subquery is detected, parent builds context for the current row
- Inner query receives context, only builds its own if it has a nested subquery
- The pattern works naturally at any nesting depth

**Flow example:**
```php
// Top-level query - no context needed
SELECT * FROM users WHERE id = 1

// Query with correlated subquery - parent builds context per row
SELECT * FROM users u WHERE EXISTS (
    SELECT 1 FROM orders o WHERE o.user_id = u.id  -- needs u.id from outer
)
// For each user row, build: ['users.id' => X, 'id' => X, ...]
// Pass to subquery evaluation

// Deeply nested - context accumulates only when needed
SELECT * FROM a WHERE EXISTS (
    SELECT 1 FROM b WHERE b.x = a.x AND EXISTS (
        SELECT 1 FROM c WHERE c.y = b.y AND c.z = a.z  -- needs both a.z and b.y
    )
)
// Level 1 (a): no context
// Level 2 (b): receives context with a.* columns
// Level 3 (c): receives context with a.* and b.* columns (COW from level 2)
```

**Detection:** When parsing/analyzing subquery, check if it references outer tables. If not, no context needed - it's an uncorrelated subquery that can be executed once and cached.

---

## Avoid str_contains for detecting qualified vs unqualified colums

The AST already knows and could provide both the unqualified name and possibly the qualified name directly. So when we need a qualified name, we could just use $node->qualifiedName or $node->unqualifiedName and avoid one billion str_contains calls.

## 2. (Future ideas go here)

