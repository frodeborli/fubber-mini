# VirtualDatabase Known Limitations

## Federated SQL Engine

VirtualDatabase (VDB) is designed as a **federated SQL engine** - it executes SQL queries across arbitrary `TableInterface` implementations. Tables can be backed by SQLite, CSV files, JSON, APIs, or custom data sources.

This architecture provides flexibility but limits optimization opportunities:

- **No global statistics**: VDB doesn't know row counts or value distributions across federated tables
- **No unified indexes**: Each table has its own indexing; VDB can't build cross-table indexes
- **No query planning**: Traditional databases analyze queries and choose optimal execution plans; VDB uses heuristics

## Comma-Join Table Limit (4 tables max)

```sql
-- Supported: up to 4 tables
SELECT * FROM t1, t2, t3, t4 WHERE ...

-- Not supported: 5+ tables
SELECT * FROM t1, t2, t3, t4, t5 WHERE ...
-- Error: "VDB limitation: comma-joins support up to 4 tables"
```

### Why this limit exists

Comma-joins (implicit CROSS JOINs) create Cartesian products. For N tables with ~100 rows each:
- 2 tables: 10,000 combinations
- 4 tables: 100,000,000 combinations
- 7 tables: 100,000,000,000,000 combinations

VDB optimizes by:
1. **Predicate pushdown**: Filters like `d6 IN (...)` reduce table t6 before joining
2. **Hash joins**: Equi-joins like `a3 = b9` use O(n+m) hash lookup instead of O(n×m) nested loops

However, VDB processes tables in **FROM clause order**. This matters when:

```sql
SELECT * FROM t1, t2, t3, t4, t5
WHERE a1 = b5    -- equi-join between t1 and t5
  AND c3 = 100   -- filters t3
```

Optimal order: t1 → t5 (apply equi-join) → t3 (filtered) → t2 → t4

VDB's order: t1 → t2 → t3 → t4 → t5 (equi-join only applies at the end)

Without join reordering, tables without single-table predicates cause intermediate explosions. The 4-table limit keeps worst-case performance bounded.

### Workarounds

1. **Reorder tables manually**: Put equi-joined tables adjacent in FROM clause
2. **Use explicit JOINs**: `t1 JOIN t5 ON a1 = b5` gives VDB more optimization hints
3. **Break into subqueries**: Materialize intermediate results

## Other Limitations

### No query caching
Each query is parsed and executed fresh. For repeated queries, consider caching results at the application level.

### Expression evaluation overhead
Complex WHERE expressions that can't be pushed to underlying tables are evaluated row-by-row in PHP, which is slower than native database execution.

### No parallel execution
Joins and subqueries execute sequentially. Large federated queries across slow data sources will be slow.
