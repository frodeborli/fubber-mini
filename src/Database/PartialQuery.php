<?php

namespace mini\Database;

use Closure;
use mini\Collection;
use mini\Contracts\CollectionInterface;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\Predicate;
use mini\Table\Utility\TablePropertiesTrait;
use mini\Table\Wrappers\AliasTable;
use mini\Table\Wrappers\DistinctTable;
use mini\Parsing\GenericParser;
use mini\Parsing\TextNode;
use mini\Parsing\SQL\SqlParser;
use mini\Parsing\SQL\SqlRenderer;
use mini\Parsing\SQL\AST\SelectStatement;
use mini\Parsing\SQL\AST\WithStatement;
use mini\Parsing\SQL\AST\UnionNode;
use mini\Parsing\SQL\AST\IdentifierNode;
use mini\Parsing\SQL\AST\BinaryOperation;
use mini\Parsing\SQL\AST\LiteralNode;
use mini\Parsing\SQL\AST\PlaceholderNode;
use mini\Parsing\SQL\AST\ASTNode;
use mini\Parsing\SQL\AstParameterBinder;
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
     * Parsed WHERE clause from base SQL with bound parameters
     * Used by matches() to validate rows against base SQL conditions
     */
    private ?ASTNode $baseWhereAst = null;

    /**
     * Each WHERE clause is stored with its own parameters
     * @var array<int, array{sql: string, params: array<int, mixed>}>
     */
    private array $whereParts = [];

    /**
     * CTE definitions stored as AST
     *
     * Each entry: ['name' => string, 'ast' => ASTNode, 'params' => array]
     *
     * AST is stored instead of SQL strings to enable identifier renaming
     * without re-parsing. Params are kept separate to maintain placeholder
     * order for prepared statements.
     *
     * @var array<int, array{name: string, ast: ASTNode, params: array<int, mixed>}>
     */
    private array $cteList = [];

    private ?string $select = null;
    private ?string $orderBy = null;
    private ?int $limit = null;
    private int $offset = 0;

    /**
     * Structured predicate for row matching (tracks eq/lt/gt/etc calls)
     * Raw where() calls are NOT included here.
     */
    private Predicate $predicate;

    private ?\Closure $hydrator = null;
    private ?string $entityClass = null;
    private array|false $entityConstructorArgs = false;
    private ?\Closure $loadCallback = null;

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
        $this->predicate  = new Predicate();
    }

    /**
     * Create a PartialQuery from SQL
     *
     * The executor closure handles raw query execution for iteration.
     *
     * Note: SQL with WHERE/ORDER BY/LIMIT acts as a "barrier" - subsequent
     * operations filter/sort WITHIN those results, not before them. This is
     * intentional: `query('SELECT * FROM t LIMIT 10')->order('id DESC')`
     * reorders those 10 rows, it doesn't fetch different rows.
     *
     * For composable queries where filters/sorts apply before limits, use
     * method chaining: `query('SELECT * FROM t')->order('id DESC')->limit(10)`
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

        // Extract WHERE clause for row matching
        if ($ast->where !== null) {
            $binder = new AstParameterBinder($this->baseParams);
            $this->baseWhereAst = $binder->bind($ast->where);
        }
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
     * Get the AST representation of this query
     *
     * Parses the base SQL and applies any modifications (WHERE, ORDER BY, etc.)
     * Returns the main query AST without CTEs - CTEs are stored separately.
     *
     * Used internally for query composition and by withCTE() for deferred rendering.
     *
     * @return ASTNode The parsed AST (SelectStatement, UnionNode, or WithStatement)
     */
    private function getAst(): ASTNode
    {
        $parser = new SqlParser();
        return $parser->parse($this->baseSql);
    }

    /**
     * Get a shared SqlRenderer instance
     */
    private static function getRenderer(): SqlRenderer
    {
        static $renderer = null;
        if ($renderer === null) {
            $renderer = new SqlRenderer();
        }
        return $renderer;
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
     * Set a callback to be called after each entity is hydrated
     *
     * Used by ModelTrait to mark entities as loaded from the database.
     *
     * @param \Closure(object):void $callback Called with each hydrated entity
     * @return self
     */
    public function withLoadCallback(\Closure $callback): self
    {
        $new = clone $this;
        $new->loadCallback = $callback;
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
            $new = $this->where("$col IS NULL");
            $new->predicate = $this->predicate->eq($column, null);
            return $new;
        }
        $new = $this->where("$col = ?", [$value]);
        $new->predicate = $this->predicate->eq($column, $value);
        return $new;
    }

    public function lt(string $column, mixed $value): self
    {
        $col = $this->db->quoteIdentifier($column);
        $new = $this->where("$col < ?", [$value]);
        $new->predicate = $this->predicate->lt($column, $value);
        return $new;
    }

    public function lte(string $column, mixed $value): self
    {
        $col = $this->db->quoteIdentifier($column);
        $new = $this->where("$col <= ?", [$value]);
        $new->predicate = $this->predicate->lte($column, $value);
        return $new;
    }

    public function gt(string $column, mixed $value): self
    {
        $col = $this->db->quoteIdentifier($column);
        $new = $this->where("$col > ?", [$value]);
        $new->predicate = $this->predicate->gt($column, $value);
        return $new;
    }

    public function gte(string $column, mixed $value): self
    {
        $col = $this->db->quoteIdentifier($column);
        $new = $this->where("$col >= ?", [$value]);
        $new->predicate = $this->predicate->gte($column, $value);
        return $new;
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
        $new = $this->where("$col LIKE ?", [$pattern]);
        $new->predicate = $this->predicate->like($column, $pattern);
        return $new;
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
     * CTEs are merged by name. Duplicate names with identical AST are silently
     * deduplicated. Conflicting definitions throw LogicException.
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
        $renderer = self::getRenderer();

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

                // Compare by rendering to SQL (AST comparison is complex)
                $existingSql = $renderer->render($existing['ast']);
                $foreignSql = $renderer->render($foreignCte['ast']);

                // Same definition -> reuse silently
                if ($existingSql === $foreignSql
                    && $existing['params'] === $foreignCte['params']
                ) {
                    continue;
                }

                // Same name, different definition -> ambiguous
                throw new \LogicException(
                    "Conflicting CTE definition for '{$name}' between combined PartialQueries."
                );
            }

            // New CTE -> deep clone AST to ensure independence
            $new->cteList[] = [
                'name' => $foreignCte['name'],
                'ast' => $renderer->deepClone($foreignCte['ast']),
                'params' => $foreignCte['params'],
            ];
        }

        return $new;
    }

    /**
     * Add a PartialQuery as a named CTE (Common Table Expression)
     *
     * Allows composing queries from other PartialQueries. The CTE can then
     * be referenced by name in the main query's SQL.
     *
     * When adding a CTE with the same name as an existing CTE, the existing
     * CTE is renamed and the new CTE wraps it, creating a filter chain:
     *
     * ```php
     * $q = db()->query('SELECT * FROM users WHERE age >= 18');
     * $q = $q->withCTE('users', db()->query('SELECT * FROM users WHERE age <= 67'));
     * $q = $q->withCTE('users', db()->query('SELECT * FROM users WHERE gender = "male"'));
     * ```
     *
     * Produces:
     * ```sql
     * WITH _cte_1 AS (SELECT * FROM users WHERE age <= 67),
     *      users AS (SELECT * FROM _cte_1 WHERE gender = "male")
     * SELECT * FROM users WHERE age >= 18
     * ```
     *
     * Each new CTE wraps the previous one, chaining filters together.
     * The baseSql always references the outermost CTE.
     *
     * @param string $name CTE name to use in the query
     * @param self $query PartialQuery to use as the CTE definition
     * @return self New instance with the CTE added
     * @throws \InvalidArgumentException If queries use different database connections
     */
    public function withCTE(string $name, self $query): self
    {
        if ($this->db !== $query->db) {
            throw new \InvalidArgumentException(
                "Cannot add CTE '{$name}': query uses a different database connection. " .
                'Use ->column() to materialize the subquery first.'
            );
        }

        $renderer = self::getRenderer();

        // Hide same-named CTE in the query being added (its internal implementation detail)
        $query = $query->hideCTE($name);

        // Merge any nested CTEs from the source query
        $new = $this->withCTEsFrom($query);

        // Get the AST for the new CTE - deep clone to ensure independence
        // If the query has its own CTEs (WithStatement), extract the inner query
        $queryAst = $query->getAst();
        if ($queryAst instanceof WithStatement) {
            // Flatten: the inner CTEs are already merged via withCTEsFrom()
            // Use the inner SELECT as the CTE definition
            $newCteAst = $renderer->deepClone($queryAst->query);
        } else {
            $newCteAst = $renderer->deepClone($queryAst);
        }

        // Get params for the new CTE (excluding CTE params which were already merged)
        $newCteParams = $query->getMainParams();

        // Check if we already have a CTE with this name
        $existingIndex = null;
        foreach ($new->cteList as $i => $cte) {
            if ($cte['name'] === $name) {
                $existingIndex = $i;
                break;
            }
        }

        if ($existingIndex !== null) {
            // Rename OLD CTE to unique name
            $renamedName = '_cte_' . str_replace('.', '_', (string) hrtime(true));
            $new->cteList[$existingIndex]['name'] = $renamedName;

            // Update NEW CTE's AST to reference the renamed OLD CTE
            // This makes the new CTE wrap the old one
            $newCteAst = $renderer->renameIdentifier($newCteAst, $name, $renamedName);
        }

        // Add NEW CTE with the requested name
        $new->cteList[] = [
            'name' => $name,
            'ast' => $newCteAst,
            'params' => $newCteParams,
        ];

        return $new;
    }

    /**
     * Hide a CTE by renaming it to a unique internal name
     *
     * This is a safety mechanism for composable queries. When a method
     * internally uses a CTE (e.g., for soft-delete filtering), it can
     * hide that CTE before returning, preventing conflicts when the
     * query is used as a CTE in an outer query.
     *
     * If no CTE with the given name exists, this is a no-op.
     *
     * @param string $name CTE name to hide
     * @return self New instance with the CTE renamed (or same instance if not found)
     * @internal
     */
    protected function hideCTE(string $name): self
    {
        // Find the CTE
        $cteIndex = null;
        foreach ($this->cteList as $i => $cte) {
            if ($cte['name'] === $name) {
                $cteIndex = $i;
                break;
            }
        }

        if ($cteIndex === null) {
            return $this; // No-op - safe to call even if CTE doesn't exist
        }

        // Generate unique name using monotonic nanosecond timestamp
        $newName = '_cte_' . str_replace('.', '_', (string) hrtime(true));

        $new = clone $this;
        $renderer = self::getRenderer();

        // Rename in cteList
        $new->cteList[$cteIndex]['name'] = $newName;

        // Rename references in CTE AST
        $new->cteList[$cteIndex]['ast'] = $renderer->renameIdentifier(
            $new->cteList[$cteIndex]['ast'],
            $name,
            $newName
        );

        // Rename references in baseSql using word boundaries
        // This is safe because $newName is unique and won't collide
        $pattern = '/\b' . preg_quote($name, '/') . '\b/';
        $new->baseSql = preg_replace($pattern, $newName, $new->baseSql);

        // Rename references in whereParts
        foreach ($new->whereParts as $i => $part) {
            $new->whereParts[$i]['sql'] = preg_replace($pattern, $newName, $part['sql']);
        }

        // Invalidate cached analysis (SQL changed)
        $new->analyzed = false;
        $new->sourceTable = null;
        $new->baseWhereAst = null;

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

        $renderer = self::getRenderer();
        $withParts = [];
        foreach ($this->cteList as $cte) {
            $cteSql = $renderer->render($cte['ast']);
            $withParts[] = $cte['name'] . ' AS (' . $cteSql . ')';
        }

        return 'WITH ' . implode(', ', $withParts) . ' ' . $core;
    }

    /**
     * Build core SQL (without WITH prefix)
     *
     * Strategy:
     * - If base SQL is a simple SELECT without WHERE/ORDER/LIMIT, append directly
     * - Otherwise, wrap as subquery for safety
     *
     * @param int|null $defaultLimit Default limit to apply if none set (null = no limit)
     */
    private function buildCoreSql(?int $defaultLimit = null): string
    {
        $dialect = $this->db->getDialect();

        // If no composition at all, use base SQL directly
        if (empty($this->whereParts) && $this->orderBy === null
            && $this->limit === null && $defaultLimit === null
            && $this->offset === 0 && $this->select === null) {
            return $this->baseSql;
        }

        // Try to append directly if base SQL is simple enough
        if ($this->canAppendToBaseSql()) {
            return $this->buildByAppending($dialect, $defaultLimit);
        }

        // Fall back to wrapping as subquery
        return $this->buildByWrapping($dialect, $defaultLimit);
    }

    /**
     * Check if we can safely append WHERE/ORDER/LIMIT to base SQL
     *
     * Returns true if base SQL is a simple SELECT without existing WHERE/ORDER/LIMIT
     */
    private function canAppendToBaseSql(): bool
    {
        // Only try if we're not changing SELECT columns
        if ($this->select !== null) {
            return false;
        }

        try {
            $parser = new SqlParser();
            $ast = $parser->parse($this->baseSql);
        } catch (\Throwable $e) {
            return false;
        }

        // Must be a simple SELECT (not UNION etc)
        if (!$ast instanceof SelectStatement) {
            return false;
        }

        // Can append if no existing WHERE, ORDER BY, or LIMIT
        return $ast->where === null
            && empty($ast->orderBy)
            && $ast->limit === null
            && $ast->offset === null;
    }

    /**
     * Build SQL by appending clauses to base SQL
     */
    private function buildByAppending(SqlDialect $dialect, ?int $defaultLimit): string
    {
        $sql = $this->baseSql;

        if (!empty($this->whereParts)) {
            $sql .= ' WHERE ' . $this->buildWhereSql();
        }

        if ($this->orderBy !== null) {
            $sql .= " ORDER BY {$this->orderBy}";
        }

        $sql .= $this->buildLimitClause($dialect, $defaultLimit);

        return $sql;
    }

    /**
     * Build SQL by wrapping base SQL as subquery
     */
    private function buildByWrapping(SqlDialect $dialect, ?int $defaultLimit): string
    {
        $select = $this->select ?? '*';
        $alias = $this->getAlias();
        $sql = "SELECT {$select} FROM ({$this->baseSql}) AS {$alias}";

        if (!empty($this->whereParts)) {
            $sql .= ' WHERE ' . $this->buildWhereSql();
        }

        if ($this->orderBy !== null) {
            $sql .= " ORDER BY {$this->orderBy}";
        } elseif ($dialect === SqlDialect::SqlServer || $dialect === SqlDialect::Oracle) {
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
                foreach ($part['params'] as $key => $p) {
                    if (is_string($key)) {
                        $params[$key] = $p;  // Named placeholder
                    } else {
                        $params[] = $p;  // Positional placeholder
                    }
                }
            }
        }

        return $params;
    }

    /**
     * Flattened params for all CTEs (in order), then main query params
     *
     * Preserves named placeholder keys (e.g., 'status' for :status).
     *
     * @return array<int|string, mixed>
     */
    private function getAllParams(): array
    {
        $params = [];

        // CTE params first
        foreach ($this->cteList as $cte) {
            foreach ($cte['params'] as $key => $p) {
                if (is_string($key)) {
                    $params[$key] = $p;
                } else {
                    $params[] = $p;
                }
            }
        }

        // Then main/base + WHERE params
        foreach ($this->getMainParams() as $key => $p) {
            if (is_string($key)) {
                $params[$key] = $p;
            } else {
                $params[] = $p;
            }
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
        throw new \RuntimeException("PartialQuery::toArray() is not supported; PartialQuery is immutable and can be passed to views - use iterator_to_array() if materialization is needed.");
    }

    /**
     * JSON serialize - returns all rows
     *
     * @return array<int, T>
     */
    public function jsonSerialize(): array
    {
        return iterator_to_array($this, false);
    }

    /**
     * Transform each row using a closure
     *
     * Materializes the query and returns a Collection with transformed items.
     *
     * @template U
     * @param Closure(T): U $fn
     * @return CollectionInterface<U>
     */
    public function map(Closure $fn): CollectionInterface
    {
        return Collection::from($this)->map($fn);
    }

    /**
     * Filter rows using a closure
     *
     * Materializes the query and returns a Collection with matching items.
     *
     * @param Closure(T): bool $fn
     * @return CollectionInterface<T>
     */
    public function filter(Closure $fn): CollectionInterface
    {
        return Collection::from($this)->filter($fn);
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

            // Check if class implements Hydration for custom hydration
            if (is_subclass_of($class, Hydration::class)) {
                foreach ($rows as $row) {
                    $obj = $class::fromSqlRow($row);
                    if ($this->loadCallback !== null) {
                        ($this->loadCallback)($obj);
                    }
                    yield $obj;
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

                    if ($this->loadCallback !== null) {
                        ($this->loadCallback)($obj);
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
     * Get the structured predicate for row matching
     *
     * Returns a Predicate representing the filter conditions added via
     * eq(), lt(), gt(), like(), etc. Raw where() clauses are NOT included
     * since they can't be represented structurally.
     *
     * Use ->getPredicate()->test($row) to check if a row matches.
     */
    public function getPredicate(): Predicate
    {
        return $this->predicate;
    }

    /**
     * Test if a row matches the structured filter conditions
     *
     * Convenience method for getPredicate()->test($row).
     *
     * Note: Only tests conditions added via eq(), lt(), gt(), like(), etc.
     * Raw where() clauses cannot be tested without database access.
     *
     * @param object $row The row to test
     * @return bool True if row matches all structured conditions
     */
    public function matches(object $row): bool
    {
        // First check base SQL WHERE conditions (if any)
        $this->analyze(); // Ensure AST is parsed
        if ($this->baseWhereAst !== null) {
            $evaluator = new ExpressionEvaluator();
            if (!$evaluator->evaluateAsBool($this->baseWhereAst, $row)) {
                return false;
            }
        }

        // Then check Predicate conditions (from eq()/lt()/etc. calls)
        return $this->predicate->test($row);
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
