<?php

namespace mini\Database;

use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\Utility\TablePropertiesTrait;
use mini\Table\Wrappers\AliasTable;
use mini\Table\Wrappers\DistinctTable;
use mini\Parsing\GenericParser;
use mini\Parsing\TextNode;
use mini\Parsing\SQL\SqlParser;
use mini\Parsing\SQL\AST\SelectStatement;
use mini\Parsing\SQL\AST\UnionNode;
use mini\Parsing\SQL\AST\IdentifierNode;
use stdClass;

/**
 * Immutable query builder for composable SQL queries
 *
 * @template T of array|object
 * @implements ResultSetInterface<T>
 */
final class PartialQuery implements ResultSetInterface, TableInterface
{
    use TablePropertiesTrait;

    private DatabaseInterface $db;

    /** @var \Closure(string, array): \Traversable Raw query executor */
    private \Closure $executor;

    /**
     * Base SQL for this query (always set, no simple table mode)
     */
    private string $baseSql;

    /** @var array<int, mixed> Parameters for placeholders in the base SQL (constructor) */
    private array $baseParams = [];

    // Lazy analysis results
    private bool $analyzed = false;

    /** Underlying source table for UPDATE/DELETE (null if multi-table or complex) */
    private ?string $sourceTable = null;

    /** Alias for subquery wrapping */
    private string $alias = '_q';

    /**
     * Each WHERE clause is stored with its own parameters
     * @var array<int, array{sql: string, params: array<int, mixed>}>
     */
    private array $whereParts = [];

    /**
     * Verbatim CTE definitions
     * Each entry: ['name' => string, 'sql' => string, 'params' => array]
     *
     * Example: ['name' => '_cte0', 'sql' => 'SELECT ...', 'params' => [1, 2]]
     *
     * Params for CTEs are kept separate so we can prepend them
     * in the correct order when executing.
     *
     * @var array<int, array{name: string, sql: string, params: array<int, mixed>}>
     */
    private array $cteList = [];

    private ?string $select = null;
    private ?string $orderBy = null;
    private ?int $limit = null;
    private int $offset = 0;

    private ?\Closure $hydrator = null;
    private ?string $entityClass = null;
    private array|false $entityConstructorArgs = false;

    /**
     * @internal Use PartialQuery::fromSql() factory instead
     */
    private function __construct(
        DatabaseInterface $db,
        \Closure $executor,
        string $sql,
        array $params = []
    ) {
        $this->db         = $db;
        $this->executor   = $executor;
        $this->baseSql    = $sql;
        $this->baseParams = $params;
    }

    /**
     * Create a PartialQuery from SQL
     *
     * The executor closure handles raw query execution for iteration.
     *
     * @param DatabaseInterface $db       Database connection
     * @param \Closure          $executor Raw query executor: fn(string $sql, array $params): Traversable
     * @param string            $sql      Base SELECT query
     * @param array<int,mixed>  $params   Parameters for placeholders in $sql
     */
    public static function fromSql(
        DatabaseInterface $db,
        \Closure $executor,
        string $sql,
        array $params = []
    ): self {
        return new self($db, $executor, $sql, $params);
    }

    /**
     * Analyze the SQL to extract table info (lazy, cached)
     *
     * Extracts:
     * - $sourceTable: The underlying table name (null if multi-table/complex)
     * - $alias: The alias for subquery wrapping
     */
    private function analyze(): void
    {
        if ($this->analyzed) {
            return;
        }
        $this->analyzed = true;

        try {
            $parser = new SqlParser();
            $ast = $parser->parse($this->baseSql);
        } catch (\Throwable $e) {
            // Parse failed - can't determine table info
            return;
        }

        // UNION/INTERSECT/EXCEPT = multi-table
        if ($ast instanceof UnionNode) {
            return;
        }

        if (!$ast instanceof SelectStatement) {
            return;
        }

        // Has JOINs = multi-table
        if (!empty($ast->joins)) {
            return;
        }

        // FROM must be a simple identifier (not a subquery)
        if (!$ast->from instanceof IdentifierNode) {
            return;
        }

        $this->sourceTable = $ast->from->getFullName();
        $this->alias = $ast->fromAlias ?? $ast->from->getFullName();
    }

    /**
     * Check if this is a single-table query (supports UPDATE/DELETE)
     */
    public function isSingleTable(): bool
    {
        $this->analyze();
        return $this->sourceTable !== null;
    }

    /**
     * Get the underlying source table for UPDATE/DELETE operations
     *
     * @throws \RuntimeException If query has JOINs, UNIONs, or complex FROM clause
     */
    public function getSourceTable(): string
    {
        $this->analyze();
        if ($this->sourceTable === null) {
            throw new \RuntimeException(
                "Cannot determine source table: query has JOINs, UNIONs, or complex FROM clause"
            );
        }
        return $this->sourceTable;
    }

    /**
     * Get alias for this query when used as a subquery
     */
    private function getAlias(): string
    {
        $this->analyze();
        return $this->alias;
    }

    /**
     * Get the base query string
     */
    public function getBaseQuery(): string
    {
        return $this->baseSql;
    }

    /**
     * Use an entity class for hydration
     *
     * @template TObject of object
     * @param class-string<TObject> $class
     * @param array|false           $constructorArgs
     * @return PartialQuery<TObject>
     */
    public function withEntityClass(string $class, array|false $constructorArgs = false): self
    {
        $new                         = clone $this;
        $new->entityClass            = $class;
        $new->entityConstructorArgs  = $constructorArgs;
        $new->hydrator               = null;
        return $new;
    }

    /**
     * Use a custom hydrator closure
     *
     * @template TObject of object
     * @param \Closure(...mixed):TObject $hydrator
     * @return PartialQuery<TObject>
     */
    public function withHydrator(\Closure $hydrator): self
    {
        $new             = clone $this;
        $new->hydrator   = $hydrator;
        $new->entityClass = null;
        return $new;
    }

    /**
     * Add a WHERE clause with raw SQL
     */
    public function where(string $sql, array $params = []): self
    {
        $new = clone $this;
        $new->whereParts[] = [
            'sql'    => '(' . $sql . ')',
            'params' => array_values($params),
        ];
        return $new;
    }

    /**
     * Add WHERE column = value clause (NULL -> IS NULL)
     */
    public function eq(string $column, mixed $value): self
    {
        $col = $this->db->quoteIdentifier($column);
        if ($value === null) {
            return $this->where("$col IS NULL");
        }
        return $this->where("$col = ?", [$value]);
    }

    public function lt(string $column, mixed $value): self
    {
        $col = $this->db->quoteIdentifier($column);
        return $this->where("$col < ?", [$value]);
    }

    public function lte(string $column, mixed $value): self
    {
        $col = $this->db->quoteIdentifier($column);
        return $this->where("$col <= ?", [$value]);
    }

    public function gt(string $column, mixed $value): self
    {
        $col = $this->db->quoteIdentifier($column);
        return $this->where("$col > ?", [$value]);
    }

    public function gte(string $column, mixed $value): self
    {
        $col = $this->db->quoteIdentifier($column);
        return $this->where("$col >= ?", [$value]);
    }

    /**
     * Add WHERE column IN (...) clause
     *
     * Accepts:
     * - array: Simple value list
     * - PartialQuery: SQL subquery (same database)
     * - TableInterface/SetInterface: Materialized to value list
     */
    public function in(string $column, array|SetInterface $values): self
    {
        $col = $this->db->quoteIdentifier($column);

        // Plain array - most common case
        if (is_array($values)) {
            if ($values === []) {
                return $this->where('1 = 0');
            }
            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            return $this->where("$col IN ($placeholders)", array_values($values));
        }

        // PartialQuery subquery case - use real SQL subquery
        if ($values instanceof self) {
            // Cross-database: must materialize (can't use subquery across connections)
            if ($this->db !== $values->db) {
                $list = [];
                foreach ($values as $row) {
                    $vars = get_object_vars($row);
                    $list[] = reset($vars);
                }
                if ($list === []) {
                    return $this->where('1 = 0');
                }
                $placeholders = implode(', ', array_fill(0, count($list), '?'));
                return $this->where("$col IN ($placeholders)", $list);
            }

            // Same database: use real SQL subquery (no materialization)
            $new = $this->withCTEsFrom($values);

            // Build subquery SQL (no default limit for subqueries)
            $subSql    = $values->buildSql(null);
            $subParams = $values->getAllParams();

            return $new->where("$col IN ($subSql)", $subParams);
        }

        // Other SetInterface - materialize by iterating
        $list = [];
        foreach ($values as $value) {
            if (is_object($value)) {
                $vars = get_object_vars($value);
                $list[] = reset($vars);
            } elseif (is_array($value)) {
                $list[] = reset($value);
            } else {
                $list[] = $value;
            }
        }

        if ($list === []) {
            return $this->where('1 = 0');
        }

        $placeholders = implode(', ', array_fill(0, count($list), '?'));
        return $this->where("$col IN ($placeholders)", $list);
    }

    /**
     * Add WHERE column LIKE pattern clause
     */
    public function like(string $column, string $pattern): self
    {
        $col = $this->db->quoteIdentifier($column);
        return $this->where("$col LIKE ?", [$pattern]);
    }

    /**
     * Union with another query (OR semantics, deduplicated)
     *
     * For SQL databases, uses UNION. Results are deduplicated by full row.
     */
    public function union(TableInterface $other): TableInterface
    {
        if (!($other instanceof self)) {
            throw new \InvalidArgumentException("PartialQuery::union() requires another PartialQuery");
        }

        if ($this->db !== $other->db) {
            throw new \InvalidArgumentException("Cannot union PartialQueries from different databases");
        }

        // Build UNION query as new base SQL
        $leftSql = $this->buildCoreSql();
        $rightSql = $other->buildCoreSql();
        $unionSql = "($leftSql) UNION ($rightSql)";

        // Merge params: left params, then right params
        $params = array_merge($this->getAllParams(), $other->getAllParams());

        // Create new PartialQuery with union as base
        $new = new self($this->db, $this->executor, $unionSql, $params);

        // Merge CTEs from both sides
        foreach ($this->cteList as $cte) {
            $new->cteList[] = $cte;
        }
        $new = $new->withCTEsFrom($other);

        return $new;
    }

    /**
     * Difference from another query (NOT IN semantics)
     *
     * For SQL databases, uses NOT IN subquery or EXCEPT where supported.
     */
    public function except(SetInterface $other): TableInterface
    {
        if (!($other instanceof self)) {
            throw new \InvalidArgumentException("PartialQuery::except() requires another PartialQuery");
        }

        if ($this->db !== $other->db) {
            throw new \InvalidArgumentException("Cannot except PartialQueries from different databases");
        }

        $dialect = $this->db->getDialect();

        // Most databases support EXCEPT
        if ($dialect !== SqlDialect::MySQL) {
            $leftSql = $this->buildCoreSql();
            $rightSql = $other->buildCoreSql();
            $exceptSql = "($leftSql) EXCEPT ($rightSql)";

            $params = array_merge($this->getAllParams(), $other->getAllParams());
            $new = new self($this->db, $this->executor, $exceptSql, $params);

            foreach ($this->cteList as $cte) {
                $new->cteList[] = $cte;
            }
            return $new->withCTEsFrom($other);
        }

        // MySQL doesn't support EXCEPT - use NOT IN with primary key
        // This requires knowing the primary key, fall back to NOT EXISTS
        $new = $this->withCTEsFrom($other);
        $subSql = $other->buildCoreSql(PHP_INT_MAX);
        $subParams = $other->getMainParams();

        // Use NOT EXISTS with correlated subquery matching all columns
        // This is the safest fallback but may be slow
        $alias = $this->getAlias();
        return $new->where("NOT EXISTS (SELECT 1 FROM ($subSql) AS _exc WHERE _exc.* = {$alias}.*)", $subParams);
    }

    /**
     * Return new instance with CTEs from another PartialQuery merged in.
     *
     * CTEs are merged by name:
     * - If a CTE with the same name, SQL and params already exists, it is reused.
     * - If a CTE with the same name but different SQL or params exists, a LogicException is thrown.
     *
     * @throws \InvalidArgumentException If queries use different database connections
     * @throws \LogicException           If conflicting CTE definitions are detected
     */
    private function withCTEsFrom(self $query): self
    {
        if ($this->db !== $query->db) {
            throw new \InvalidArgumentException(
                'Cannot combine PartialQueries from different database connections. ' .
                'Use ->column() to materialize the subquery first.'
            );
        }

        $new = clone $this;

        foreach ($query->cteList as $foreignCte) {
            $name = $foreignCte['name'];

            // Check if CTE with the same name already exists
            $existingIndex = null;
            foreach ($new->cteList as $idx => $existing) {
                if ($existing['name'] === $name) {
                    $existingIndex = $idx;
                    break;
                }
            }

            if ($existingIndex !== null) {
                $existing = $new->cteList[$existingIndex];

                // Same definition -> reuse silently
                if ($existing['sql'] === $foreignCte['sql']
                    && $existing['params'] === $foreignCte['params']
                ) {
                    continue;
                }

                // Same name, different definition -> ambiguous
                throw new \LogicException(
                    "Conflicting CTE definition for '{$name}' between combined PartialQueries."
                );
            }

            // New CTE -> append
            $new->cteList[] = $foreignCte;
        }

        return $new;
    }

    /**
     * Set SELECT clause (overwrites previous, default is *)
     */
    public function select(string $selectPart): self
    {
        $new         = clone $this;
        $new->select = $selectPart;
        return $new;
    }

    /**
     * Project to specific columns (TableInterface method)
     *
     * Alias for select() with column quoting, enables use as SetInterface
     */
    public function columns(string ...$columns): self
    {
        $quoted = array_map(fn($c) => $this->db->quoteIdentifier($c), $columns);
        return $this->select(implode(', ', $quoted));
    }

    /**
     * Check if value(s) exist in the table (SetInterface method)
     *
     * Requires columns() to be called first to specify which columns to check.
     */
    public function has(object $member): bool
    {
        if ($this->select === null) {
            throw new \RuntimeException("has() requires columns() or select() to be called first");
        }

        $query = $this;
        foreach (get_object_vars($member) as $col => $value) {
            $query = $query->eq($col, $value);
        }
        return $query->limit(1)->one() !== null;
    }

    /**
     * Get the SELECT clause (null means *)
     */
    public function getSelect(): ?string
    {
        return $this->select;
    }

    /**
     * Set ORDER BY clause (overwrites previous)
     */
    public function order(?string $orderSpec): TableInterface
    {
        $new          = clone $this;
        $new->orderBy = $orderSpec;
        return $new;
    }

    /**
     * Set LIMIT (overwrites previous)
     */
    public function limit(int $limit): self
    {
        $new        = clone $this;
        $new->limit = $limit;
        return $new;
    }

    /**
     * Set OFFSET (overwrites previous)
     */
    public function offset(int $offset): self
    {
        $new         = clone $this;
        $new->offset = $offset;
        return $new;
    }

    /**
     * Return distinct rows only
     */
    public function distinct(): TableInterface
    {
        return new DistinctTable($this);
    }

    /**
     * Alias this table/query
     */
    public function withAlias(?string $tableAlias = null, array $columnAliases = []): TableInterface
    {
        return new AliasTable($this, $tableAlias, $columnAliases);
    }

    /**
     * Build SQL query string with optional WITH prefix
     *
     * @param int|null $defaultLimit Default limit for outer queries (null = no limit)
     */
    private function buildSql(?int $defaultLimit = null): string
    {
        $core = $this->buildCoreSql($defaultLimit);

        if (empty($this->cteList)) {
            return $core;
        }

        $withParts = [];
        foreach ($this->cteList as $cte) {
            $withParts[] = $cte['name'] . ' AS (' . $cte['sql'] . ')';
        }

        return 'WITH ' . implode(', ', $withParts) . ' ' . $core;
    }

    /**
     * Build core SQL (without WITH prefix)
     *
     * @param int|null $defaultLimit Default limit to apply if none set (null = no limit)
     */
    private function buildCoreSql(?int $defaultLimit = null): string
    {
        $dialect = $this->db->getDialect();
        $select  = $this->select ?? '*';

        // If no composition (no WHERE, ORDER, LIMIT, SELECT changes), use base SQL directly
        if (empty($this->whereParts) && $this->orderBy === null
            && $this->limit === null && $defaultLimit === null
            && $this->offset === 0 && $this->select === null) {
            return $this->baseSql;
        }

        // Wrap base SQL in a subquery to apply composition safely
        $alias = $this->getAlias();
        $sql   = "SELECT {$select} FROM ({$this->baseSql}) AS {$alias}";

        if (!empty($this->whereParts)) {
            $sql .= ' WHERE ' . $this->buildWhereSql();
        }

        if ($this->orderBy !== null) {
            $sql .= " ORDER BY {$this->orderBy}";
        } elseif ($dialect === SqlDialect::SqlServer || $dialect === SqlDialect::Oracle) {
            // SQL Server and Oracle require ORDER BY when using OFFSET/FETCH
            // But only if we have a limit or offset
            if ($this->limit !== null || $defaultLimit !== null || $this->offset > 0) {
                $sql .= " ORDER BY (SELECT 0)";
            }
        }

        $sql .= $this->buildLimitClause($dialect, $defaultLimit);

        return $sql;
    }

    /**
     * Build WHERE clause string (without "WHERE")
     */
    private function buildWhereSql(): string
    {
        if (empty($this->whereParts)) {
            return '';
        }

        $parts = [];
        foreach ($this->whereParts as $part) {
            $parts[] = $part['sql'];
        }

        return implode(' AND ', $parts);
    }

    /**
     * Build LIMIT/OFFSET clause according to database dialect
     *
     * @param SqlDialect $dialect Database dialect
     * @param int|null $defaultLimit Default limit to use if none set (null = no limit)
     */
    private function buildLimitClause(SqlDialect $dialect, ?int $defaultLimit = null): string
    {
        $limit  = $this->limit ?? $defaultLimit;
        $offset = $this->offset;

        // No limit and no offset -> no clause needed
        if ($limit === null && $offset === 0) {
            return '';
        }

        // If we have offset but no limit, we need a very large limit for some dialects
        if ($limit === null) {
            $limit = PHP_INT_MAX;
        }

        return match ($dialect) {
            SqlDialect::MySQL =>
                $offset > 0
                    ? " LIMIT {$offset}, {$limit}"
                    : " LIMIT {$limit}",

            SqlDialect::SqlServer,
            SqlDialect::Oracle =>
                // SQL Server (2012+) & modern Oracle: OFFSET .. FETCH
                // For SQL Server we ensured an ORDER BY earlier.
                " OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY",

            SqlDialect::Postgres,
            SqlDialect::Sqlite,
            SqlDialect::Generic,
            SqlDialect::Virtual =>
                $offset > 0
                    ? " LIMIT {$limit} OFFSET {$offset}"
                    : " LIMIT {$limit}",
        };
    }

    /**
     * Params for the "main" query (base SQL + WHERE params), excluding CTE params
     *
     * @return array<int, mixed>
     */
    private function getMainParams(): array
    {
        $params = $this->baseParams;

        foreach ($this->whereParts as $part) {
            if (!empty($part['params'])) {
                foreach ($part['params'] as $p) {
                    $params[] = $p;
                }
            }
        }

        return $params;
    }

    /**
     * Flattened params for all CTEs (in order), then main query params
     *
     * @return array<int, mixed>
     */
    private function getAllParams(): array
    {
        $params = [];

        // CTE params first
        foreach ($this->cteList as $cte) {
            foreach ($cte['params'] as $p) {
                $params[] = $p;
            }
        }

        // Then main/base + WHERE params
        foreach ($this->getMainParams() as $p) {
            $params[] = $p;
        }

        return $params;
    }

    /**
     * Fetch first row or null
     *
     * @return T|null
     */
    public function one(): mixed
    {
        foreach ($this->limit(1) as $result) {
            return $result;
        }
        return null;
    }

    /**
     * Fetch first column from all rows
     *
     * Applies default limit of 1000 if no explicit limit was set.
     * Note: When using a PartialQuery as subquery via in(), the limit
     * is bypassed automatically.
     *
     * @return array<int, mixed>
     */
    public function column(): array
    {
        return $this->db->queryColumn($this->buildSql(1000), $this->getAllParams());
    }

    /**
     * Fetch first column of first row
     *
     * Does NOT apply default limit - intended for scalar subquery use cases
     *
     * @return mixed
     */
    public function field(): mixed
    {
        return $this->db->queryField($this->buildSql(), $this->getAllParams());
    }

    /**
     * Get all rows as array
     *
     * Warning: Materializes all results into memory.
     *
     * @return array<int, T>
     */
    public function toArray(): array
    {
        return iterator_to_array($this);
    }

    /**
     * JSON serialize - returns all rows
     *
     * @return array<int, T>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Count total matching rows (ignores ORDER BY and LIMIT/OFFSET)
     */
    public function count(): int
    {
        $cteSql = '';
        if (!empty($this->cteList)) {
            $parts = [];
            foreach ($this->cteList as $cte) {
                $parts[] = $cte['name'] . ' AS (' . $cte['sql'] . ')';
            }
            $cteSql = 'WITH ' . implode(', ', $parts) . ' ';
        }

        $whereSql = $this->buildWhereSql();

        $baseParams  = $this->baseParams;
        $whereParams = [];
        foreach ($this->whereParts as $part) {
            foreach ($part['params'] as $p) {
                $whereParams[] = $p;
            }
        }

        $cteParams = [];
        foreach ($this->cteList as $cte) {
            foreach ($cte['params'] as $p) {
                $cteParams[] = $p;
            }
        }

        $params = array_merge($cteParams, $baseParams, $whereParams);

        $sql = "SELECT COUNT(*) FROM ({$this->baseSql}) AS _count";

        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        return (int) $this->db->queryField($cteSql . $sql, $params);
    }

    /**
     * Iterator over results (streaming)
     *
     * Applies default limit of 1000 if no explicit limit was set.
     * This protects against accidental unbounded queries.
     *
     * @return \Traversable<int, T>
     */
    public function getIterator(): \Traversable
    {
        $rows = ($this->executor)($this->buildSql(1000), $this->getAllParams());

        // No hydration -> yield rows as-is
        if ($this->entityClass === null && $this->hydrator === null) {
            foreach ($rows as $row) {
                yield $row;
            }
            return;
        }

        // Entity class hydration
        if ($this->entityClass !== null) {
            $class = $this->entityClass;
            $args  = $this->entityConstructorArgs;

            // Check if class implements SqlRowHydrator for custom hydration
            if (is_subclass_of($class, SqlRowHydrator::class)) {
                foreach ($rows as $row) {
                    yield $class::fromSqlRow($row);
                }
                return;
            }

            // Default: reflection-based hydration
            try {
                $refClass = new \ReflectionClass($class);
                /** @var array<string, array{prop: \ReflectionProperty, type: ?string}> $reflectionCache */
                $reflectionCache = [];
                $converterRegistry = null;

                foreach ($rows as $row) {
                    // Create instance with or without constructor
                    if ($args === false) {
                        $obj = $refClass->newInstanceWithoutConstructor();
                    } else {
                        $obj = $refClass->newInstanceArgs($args);
                    }

                    // Map columns to properties by name if property exists
                    foreach ($row as $propertyName => $value) {
                        if (!isset($reflectionCache[$propertyName])) {
                            if (!$refClass->hasProperty($propertyName)) {
                                // Unknown column -> skip
                                continue;
                            }
                            $prop = $refClass->getProperty($propertyName);
                            $prop->setAccessible(true);

                            // Get target type name for conversion
                            $targetType = null;
                            $refType = $prop->getType();
                            if ($refType instanceof \ReflectionNamedType && !$refType->isBuiltin()) {
                                $targetType = $refType->getName();
                            }

                            $reflectionCache[$propertyName] = ['prop' => $prop, 'type' => $targetType];
                        }

                        $cached = $reflectionCache[$propertyName];

                        // Convert value if target is a class and value needs conversion
                        if ($value !== null && $cached['type'] !== null && !($value instanceof $cached['type'])) {
                            // Lazy-load converter registry
                            if ($converterRegistry === null) {
                                $converterRegistry = \mini\Mini::$mini->get(\mini\Converter\ConverterRegistryInterface::class);
                            }
                            // Use 'sql-value' as source type for database hydration
                            // tryConvert checks both registered converters and fallback handlers
                            $found = false;
                            $converted = $converterRegistry->tryConvert($value, $cached['type'], 'sql-value', $found);
                            if ($found) {
                                $value = $converted;
                            }
                        }

                        $cached['prop']->setValue($obj, $value);
                    }

                    yield $obj;
                }
            } catch (\ReflectionException $e) {
                throw new \RuntimeException(
                    "Failed to hydrate class '{$class}': " . $e->getMessage(),
                    0,
                    $e
                );
            }
            return;
        }

        // Custom closure hydration (PDO::FETCH_FUNC style)
        if ($this->hydrator !== null) {
            $hydrator = $this->hydrator;
            foreach ($rows as $row) {
                yield $hydrator(...array_values(get_object_vars($row)));
            }
            return;
        }
    }

    /**
     * Get WHERE clause SQL and parameters, for DELETE/UPDATE
     *
     * Returns only the WHERE portion; CTE/base params are intentionally excluded.
     *
     * @return array{sql: string, params: array<int, mixed>}
     */
    public function getWhere(): array
    {
        if (empty($this->whereParts)) {
            return ['sql' => '', 'params' => []];
        }

        $sql = $this->buildWhereSql();

        $params = [];
        foreach ($this->whereParts as $part) {
            foreach ($part['params'] as $p) {
                $params[] = $p;
            }
        }

        return [
            'sql'    => $sql,
            'params' => $params,
        ];
    }

    /**
     * Get LIMIT value (for DELETE/UPDATE implementations)
     *
     * Returns null if no limit was explicitly set.
     */
    public function getLimit(): ?int
    {
        return $this->limit;
    }

    /**
     * Get current offset
     */
    public function getOffset(): int
    {
        return $this->offset;
    }

    /**
     * Check if any rows exist
     */
    public function exists(): bool
    {
        return $this->limit(1)->one() !== null;
    }

    /**
     * Get column definitions
     *
     * PartialQuery doesn't track column metadata - returns empty array.
     * Use columns() to specify projection.
     */
    public function getColumns(): array
    {
        return [];
    }

    /**
     * Get all column definitions (including hidden columns)
     *
     * PartialQuery doesn't track column metadata - returns empty array.
     */
    public function getAllColumns(): array
    {
        return [];
    }

    /**
     * OR predicate support
     *
     * Not yet implemented for PartialQuery - use raw where() with OR.
     */
    public function or(\mini\Table\Predicate ...$predicates): TableInterface
    {
        throw new \RuntimeException("or() not yet implemented for PartialQuery - use where() with OR clause");
    }

    /**
     * Load a single row by ID
     *
     * PartialQuery doesn't track row IDs - not supported.
     */
    public function load(string|int $rowId): ?object
    {
        throw new \RuntimeException("load() not supported for PartialQuery - use eq() with primary key column");
    }

    /**
     * Get CTE (Common Table Expression) prefix for DELETE/UPDATE
     *
     * Returns the WITH clause if CTEs are present, empty string otherwise.
     * Also returns the parameters needed for the CTEs.
     *
     * @return array{sql: string, params: array<int, mixed>}
     */
    public function getCTEs(): array
    {
        if (empty($this->cteList)) {
            return ['sql' => '', 'params' => []];
        }

        $withParts = [];
        $params = [];
        foreach ($this->cteList as $cte) {
            $withParts[] = $cte['name'] . ' AS (' . $cte['sql'] . ')';
            foreach ($cte['params'] as $p) {
                $params[] = $p;
            }
        }

        return [
            'sql' => 'WITH ' . implode(', ', $withParts) . ' ',
            'params' => $params,
        ];
    }

    /**
     * Debug information for var_dump/print_r
     */
    public function __debugInfo(): array
    {
        return [
            'sql'    => $this->buildSql(),
            'params' => $this->getAllParams(),
        ];
    }

    /**
     * Convert to string for logging/debugging
     *
     * Uses GenericParser to correctly handle placeholders, avoiding
     * replacement of '?' characters inside quoted strings.
     */
    public function __toString(): string
    {
        $sql    = $this->buildSql();
        $params = $this->getAllParams();
        $db     = $this->db;

        $tree = GenericParser::sql()->parse($sql);

        $tree->walk(function ($node) use (&$params, $db) {
            if ($node instanceof TextNode) {
                return preg_replace_callback('/\?/', function () use (&$params, $db) {
                    $value = array_shift($params);
                    return $value === null ? 'NULL' : $db->quote($value);
                }, $node->text);
            }
            return null;
        });

        return (string) $tree;
    }
}
