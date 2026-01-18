<?php

namespace mini\Database;

use Closure;
use mini\Collection;
use mini\Contracts\CollectionInterface;
use mini\Table\ColumnDef;
use mini\Table\Contracts\MutableTableInterface;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\Predicate;
use mini\Table\Types\ColumnType;
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
use mini\Parsing\SQL\AST\SubqueryNode;
use mini\Parsing\SQL\AST\ColumnNode;
use mini\Parsing\SQL\AST\IdentifierNode;
use mini\Parsing\SQL\AST\BinaryOperation;
use mini\Parsing\SQL\AST\LiteralNode;
use mini\Parsing\SQL\AST\PlaceholderNode;
use mini\Parsing\SQL\AST\InOperation;
use mini\Parsing\SQL\AST\IsNullOperation;
use mini\Parsing\SQL\AST\LikeOperation;
use mini\Parsing\SQL\AST\FunctionCallNode;
use mini\Parsing\SQL\AST\UnaryOperation;
use mini\Parsing\SQL\AST\ASTNode;
use mini\Table\Types\Operator;
use stdClass;

/**
 * Immutable query builder for composable SQL queries
 *
 * @template T of array|object
 * @implements ResultSetInterface<T>
 */
final class PartialQuery implements ResultSetInterface, MutableTableInterface
{
    use TablePropertiesTrait;

    private DatabaseInterface $db;

    /** @var \Closure(PartialQuery, ?ASTNode): \Traversable Query executor */
    private \Closure $executor;

    /**
     * Original SQL string - used directly for fast path when AST is null
     */
    private string $baseSql;

    /**
     * Original params - used directly for fast path when AST is null
     * @var array<int|string, mixed>
     */
    private array $originalParams = [];

    /**
     * Single AST root - the source of truth for query structure
     *
     * Lazily parsed from baseSql on first modification.
     * Can be SelectStatement, WithStatement (for CTEs), or UnionNode.
     * Null means query hasn't been modified - use baseSql directly.
     */
    private ?ASTNode $ast = null;

    /**
     * Whether this instance owns its AST (for copy-on-write)
     *
     * When cloned, this is set to false. On first modification,
     * ensureMutableAST() deep-clones the AST and sets this to true.
     */
    private bool $astIsPrivate = false;


    /**
     * Track if select/columns was called (for has() validation)
     */
    private bool $selectCalled = false;

    /**
     * Available columns after explicit projection (null = unrestricted)
     *
     * Only set when user explicitly calls columns() or select().
     * We never fetch schema to populate this - avoids database calls.
     * Once set, enforces narrowing-only semantics.
     *
     * @var array<string, true>|null Column names as keys, null = all available
     */
    private ?array $availableColumns = null;

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
        $this->db             = $db;
        $this->executor       = $executor;
        $this->baseSql        = $sql;
        $this->originalParams = $params;
        // AST stays null - parsed lazily on first modification
    }

    /**
     * Ensure AST exists (lazy parsing)
     *
     * Called before any operation that reads or modifies the AST.
     * Parses baseSql and binds originalParams on first call.
     * Nulls out baseSql/originalParams to prevent accidental use of stale data.
     *
     * Uses cached parsing when available. If AST is from cache, it's marked
     * as shared (astIsPrivate = false) so ensureMutableAST() will clone
     * before any mutations.
     */
    private function ensureAST(): void
    {
        if ($this->ast !== null) {
            return;
        }

        // Use cached parsing - returns shared AST if cached
        $wasCached = false;
        $this->ast = SqlParser::parseCached($this->baseSql, $wasCached);

        // If we have params to bind, we'll mutate - must clone if shared
        if (!empty($this->originalParams)) {
            if ($wasCached) {
                $this->ast = $this->ast->deepClone();
                $this->astIsPrivate = true;
            } else {
                $this->astIsPrivate = true;
            }
            $paramsCopy = $this->originalParams;
            $this->bindParamsToAST($this->ast, $paramsCopy);
        } else {
            // No params - AST is shared if from cache, private if fresh
            $this->astIsPrivate = !$wasCached;
        }

        // Defensive: null out original data now that AST is source of truth
        $this->baseSql = '';
        $this->originalParams = [];
    }

    /**
     * Bind parameter values to PlaceholderNodes in an AST
     *
     * Supports both positional (?) and named (:name) placeholders.
     * For positional, params are consumed in order.
     * For named, params are looked up by name (without the colon).
     *
     * @param ASTNode $node The AST to bind params to
     * @param array $params Values to bind (positional or named)
     * @return int Number of params bound
     */
    private function bindParamsToAST(ASTNode $node, array &$params): int
    {
        $bound = 0;

        if ($node instanceof PlaceholderNode) {
            if (str_starts_with($node->token, ':')) {
                // Named placeholder - look up by name
                $name = substr($node->token, 1);
                if (!array_key_exists($name, $params)) {
                    throw new \RuntimeException("Missing parameter for placeholder :$name");
                }
                $node->bind($params[$name]);
            } else {
                // Positional placeholder - consume next value
                if (empty($params)) {
                    throw new \RuntimeException('Not enough parameters for placeholders in query');
                }
                $node->bind(array_shift($params));
            }
            return 1;
        }

        // Recursively walk all properties that could contain AST nodes
        foreach (get_object_vars($node) as $value) {
            if ($value instanceof ASTNode) {
                $bound += $this->bindParamsToAST($value, $params);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof ASTNode) {
                        $bound += $this->bindParamsToAST($item, $params);
                    } elseif (is_array($item)) {
                        // Handle nested arrays (like orderBy: [{column: ..., direction: ...}])
                        foreach ($item as $subItem) {
                            if ($subItem instanceof ASTNode) {
                                $bound += $this->bindParamsToAST($subItem, $params);
                            }
                        }
                    }
                }
            }
        }

        return $bound;
    }

    /**
     * Ensure AST is mutable (copy-on-write)
     *
     * Call this before any AST modification. If the AST is shared
     * (from a clone), this deep-clones it first.
     */
    private function ensureMutableAST(): void
    {
        $this->ensureAST();
        if (!$this->astIsPrivate) {
            $this->ast = $this->ast->deepClone();
            $this->astIsPrivate = true;
        }
    }

    /**
     * Get the innermost SelectStatement for modification
     *
     * If the AST is a UnionNode, wraps it in a subquery first.
     * If the AST is a WithStatement, returns its inner query.
     *
     * @return SelectStatement The modifiable SELECT statement
     */
    private function getModifiableSelect(): SelectStatement
    {
        $this->ensureMutableAST();

        // Direct SelectStatement - return it
        if ($this->ast instanceof SelectStatement) {
            return $this->ast;
        }

        // WithStatement - get the inner query
        if ($this->ast instanceof WithStatement) {
            if ($this->ast->query instanceof SelectStatement) {
                return $this->ast->query;
            }
            // Inner query is UnionNode - wrap it (won't have CTEs to bubble)
            $wrapped = $this->wrapInSelect($this->ast->query);
            $this->ast->query = $wrapped instanceof WithStatement ? $wrapped->query : $wrapped;
            return $this->ast->query;
        }

        // UnionNode - wrap in subquery (won't have CTEs to bubble)
        $wrapped = $this->wrapInSelect($this->ast);
        if ($wrapped instanceof WithStatement) {
            $this->ast = $wrapped;
            return $wrapped->query;
        }
        $this->ast = $wrapped;
        return $wrapped;
    }

    /**
     * Wrap an AST node in a SELECT * FROM (...) AS alias
     *
     * Uses the source table name as alias if the inner query is a simple
     * single-table SELECT, otherwise falls back to '_q'.
     *
     * CTEs are "bubbled up" - if the inner node is a WithStatement, the CTEs
     * are extracted and wrapped around the outer SELECT, since WITH clauses
     * cannot appear inside subqueries in SQL.
     */
    private function wrapInSelect(ASTNode $node): SelectStatement|WithStatement
    {
        $ctes = [];
        $innerNode = $node;

        // Extract CTEs - they must bubble up to the outer level
        // SQL doesn't allow WITH inside a subquery
        if ($node instanceof WithStatement) {
            $ctes = $node->ctes;
            $innerNode = $node->query;
        }

        $wrapper = new SelectStatement();
        $wrapper->columns = [new ColumnNode(new IdentifierNode(['*']))];
        $wrapper->from = new SubqueryNode($innerNode);

        // Try to use a meaningful alias based on the inner query's FROM table
        $innerSelect = null;
        if ($innerNode instanceof SelectStatement) {
            $innerSelect = $innerNode;
        }

        $alias = '_q';
        if ($innerSelect !== null
            && $innerSelect->from instanceof IdentifierNode
            && empty($innerSelect->joins)) {
            $alias = $innerSelect->fromAlias ?? $innerSelect->from->getFullName();
        }

        $wrapper->fromAlias = $alias;

        // Re-apply CTEs to the outside of the wrapper
        if (!empty($ctes)) {
            return new WithStatement($ctes, false, $wrapper);
        }

        return $wrapper;
    }

    /**
     * Clone handler - mark AST as shared for copy-on-write
     */
    public function __clone()
    {
        $this->astIsPrivate = false;
        $this->columnCache = null; // Clear cache - modifications may change columns
    }

    /**
     * Borrow AST for embedding into another query
     *
     * Marks this instance as shared so any later mutation clones first.
     * Use this when embedding this query's AST into another query.
     */
    private function borrowAstForEmbedding(): ASTNode
    {
        $this->ensureAST();
        $this->astIsPrivate = false;
        return $this->ast;
    }

    /**
     * Get the AST representation of this query
     *
     * Returns a deep clone of the AST to protect internal state from external
     * mutation. Use this for external consumers (e.g., VirtualDatabase evaluation).
     *
     * Internal PartialQuery code should access $this->ast directly to avoid
     * unnecessary cloning, and use ensureMutableAST() before mutations.
     *
     * @return ASTNode The query AST (SelectStatement, WithStatement, or UnionNode)
     */
    public function getAST(): ASTNode
    {
        $this->ensureAST();
        return $this->ast->deepClone();
    }

    /**
     * Get SQL and parameters for execution
     *
     * Fast path: If no modifications were made (AST is null), returns the
     * original SQL and params directly - no parsing or rendering needed.
     *
     * Slow path: If AST exists (query was modified), renders AST to SQL
     * using the specified dialect.
     *
     * @param SqlDialect $dialect SQL dialect for rendering (only used if AST exists)
     * @return array{string, array<int, mixed>} [sql, params]
     */
    public function getSql(SqlDialect $dialect = SqlDialect::Generic): array
    {
        // Fast path: no AST means query wasn't modified
        if ($this->ast === null) {
            return [$this->baseSql, $this->originalParams];
        }

        // Slow path: render AST to SQL
        $renderer = SqlRenderer::forDialect($dialect);
        return $renderer->renderWithParams($this->ast);
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
     * @param \Closure          $executor Query executor: fn(PartialQuery, ?ASTNode): Traversable
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
     * Create a PartialQuery from any TableInterface
     *
     * Wraps the table in a VirtualDatabase, enabling full SQL query power
     * on any table implementation (generators, arrays, CSV files, etc.).
     *
     * ```php
     * $users = PartialQuery::fromTable($generatorTable)
     *     ->eq('status', 'active')
     *     ->where('age BETWEEN ? AND ?', [18, 65])
     *     ->order('name')
     *     ->limit(10);
     * ```
     */
    public static function fromTable(TableInterface $table): self
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('_', $table);
        $query = $vdb->query('SELECT * FROM _');

        // Extract internal PartialQuery via reflection
        $ref = new \ReflectionProperty(Query::class, 'pq');
        return $ref->getValue($query);
    }

    /**
     * Get source table info from AST (computed on demand)
     *
     * @return array{table: string, alias: string}|null Null if multi-table/complex query
     */
    private function getSourceTableInfo(): ?array
    {
        $this->ensureAST();

        // Get the inner SELECT (skip WithStatement wrapper if present)
        $ast = $this->ast;
        if ($ast instanceof WithStatement) {
            $ast = $ast->query;
        }

        // UNION/INTERSECT/EXCEPT = multi-table
        if ($ast instanceof UnionNode) {
            return null;
        }

        if (!$ast instanceof SelectStatement) {
            return null;
        }

        // Has JOINs = multi-table
        if (!empty($ast->joins)) {
            return null;
        }

        // FROM must be a simple identifier (not a subquery)
        if (!$ast->from instanceof IdentifierNode) {
            return null;
        }

        return [
            'table' => $ast->from->getFullName(),
            'alias' => $ast->fromAlias ?? $ast->from->getFullName(),
        ];
    }

    /**
     * Check if this is a single-table query (supports UPDATE/DELETE)
     */
    public function isSingleTable(): bool
    {
        return $this->getSourceTableInfo() !== null;
    }

    /**
     * Get the underlying source table for UPDATE/DELETE operations
     *
     * @throws \RuntimeException If query has JOINs, UNIONs, or complex FROM clause
     */
    public function getSourceTable(): string
    {
        $info = $this->getSourceTableInfo();
        if ($info === null) {
            throw new \RuntimeException(
                "Cannot determine source table: query has JOINs, UNIONs, or complex FROM clause"
            );
        }
        return $info['table'];
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
     * @deprecated Use getAST() instead
     */
    // Old getAst() removed - use the new public getAST() method

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
     *
     * The SQL expression is parsed into AST and ANDed with existing conditions.
     * Placeholders (?) in the SQL are matched with the params array.
     *
     * If pagination exists (LIMIT/OFFSET), uses barrier() first to preserve
     * window semantics - filtering applies to the paginated result, not before.
     */
    public function where(string $sql, array $params = []): self
    {
        // If pagination exists, wrap in barrier first to preserve window semantics
        if ($this->hasPagination()) {
            return $this->barrier()->where($sql, $params);
        }

        $parser = new SqlParser();
        $condition = $parser->parseExpressionFragment($sql);

        // Bind params to placeholders in the parsed condition
        if (!empty($params)) {
            $paramsCopy = $params;
            $this->bindParamsToAST($condition, $paramsCopy);
        }

        $new = clone $this;
        $select = $new->getModifiableSelect();

        if ($select->where === null) {
            $select->where = $condition;
        } else {
            $select->where = new BinaryOperation($select->where, 'AND', $condition);
        }

        return $new;
    }

    /**
     * AND a predicate to the WHERE clause (internal helper)
     *
     * Handles pagination barrier automatically.
     */
    private function andPredicate(ASTNode $predicate): self
    {
        if ($this->hasPagination()) {
            return $this->barrier()->andPredicate($predicate);
        }

        $new = clone $this;
        $select = $new->getModifiableSelect();

        if ($select->where === null) {
            $select->where = $predicate;
        } else {
            $select->where = new BinaryOperation($select->where, 'AND', $predicate);
        }

        return $new;
    }

    /**
     * Return a query that matches no rows (WHERE 1=0)
     */
    private function matchNone(): self
    {
        $new = clone $this;
        $select = $new->getModifiableSelect();
        $select->where = new BinaryOperation(
            new LiteralNode(1, 'number'),
            '=',
            new LiteralNode(0, 'number')
        );
        return $new;
    }

    /**
     * Create column identifier from name (handles table.column)
     */
    private function columnNode(string $column): IdentifierNode
    {
        return new IdentifierNode(explode('.', $column));
    }

    /**
     * Create a placeholder with value pre-bound
     */
    private function boundPlaceholder(mixed $value): PlaceholderNode
    {
        $node = new PlaceholderNode('?');
        $node->bind($value);
        return $node;
    }

    /**
     * Add WHERE column = value clause (NULL -> IS NULL)
     */
    public function eq(string $column, mixed $value): self
    {
        if ($value === null) {
            return $this->andPredicate(new IsNullOperation($this->columnNode($column), false));
        }
        return $this->andPredicate(new BinaryOperation(
            $this->columnNode($column),
            '=',
            $this->boundPlaceholder($value)
        ));
    }

    public function lt(string $column, mixed $value): self
    {
        return $this->andPredicate(new BinaryOperation(
            $this->columnNode($column),
            '<',
            $this->boundPlaceholder($value)
        ));
    }

    public function lte(string $column, mixed $value): self
    {
        return $this->andPredicate(new BinaryOperation(
            $this->columnNode($column),
            '<=',
            $this->boundPlaceholder($value)
        ));
    }

    public function gt(string $column, mixed $value): self
    {
        return $this->andPredicate(new BinaryOperation(
            $this->columnNode($column),
            '>',
            $this->boundPlaceholder($value)
        ));
    }

    public function gte(string $column, mixed $value): self
    {
        return $this->andPredicate(new BinaryOperation(
            $this->columnNode($column),
            '>=',
            $this->boundPlaceholder($value)
        ));
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
        // Plain array - most common case
        if (is_array($values)) {
            if ($values === []) {
                return $this->matchNone();
            }
            $literals = array_map(fn($v) => $this->boundPlaceholder($v), array_values($values));
            return $this->andPredicate(new InOperation($this->columnNode($column), $literals, false));
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
                return $this->in($column, $list);
            }

            // Same database: use real SQL subquery via AST (no materialization)
            if ($this->hasPagination()) {
                return $this->barrier()->in($column, $values);
            }

            $new = clone $this;
            $new->mergeCTEsFrom($values);

            // Borrow AST - marks $values as shared so it clones on mutation
            $inCondition = new InOperation(
                $this->columnNode($column),
                new SubqueryNode($values->borrowAstForEmbedding()),
                false
            );

            // Add to WHERE clause - mark $new as shared since we reference external AST
            $select = $new->getModifiableSelect();
            if ($select->where === null) {
                $select->where = $inCondition;
            } else {
                $select->where = new BinaryOperation($select->where, 'AND', $inCondition);
            }
            $new->astIsPrivate = false;

            return $new;
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

        return $this->in($column, $list);
    }

    /**
     * Add WHERE column LIKE pattern clause
     */
    public function like(string $column, string $pattern): self
    {
        return $this->andPredicate(new LikeOperation(
            $this->columnNode($column),
            $this->boundPlaceholder($pattern),
            false
        ));
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

        $new = clone $this;
        $new->ensureMutableAST();  // Ensure we own our AST before mutating

        // Create UnionNode from inner queries (without CTE wrappers)
        // CTEs will be merged at the outer level
        $leftInner = $new->getInnerQuery();
        $rightInner = $other->getAST();  // Deep-clones $other's AST
        if ($rightInner instanceof WithStatement) {
            $rightInner = $rightInner->query;
        }

        $unionNode = new UnionNode($leftInner, $rightInner, false, 'UNION');

        // If we had CTEs, rewrap with them; otherwise just use the union
        if ($new->ast instanceof WithStatement) {
            $new->ast->query = $unionNode;
        } else {
            $new->ast = $unionNode;
        }

        // Merge CTEs from other query
        $new->mergeCTEsFrom($other);

        return $new;
    }

    /**
     * Difference from another query (NOT IN semantics)
     *
     * Creates an EXCEPT node in the AST. The renderer will throw if the
     * target dialect doesn't support EXCEPT (e.g., MySQL).
     */
    public function except(SetInterface $other): TableInterface
    {
        if (!($other instanceof self)) {
            throw new \InvalidArgumentException("PartialQuery::except() requires another PartialQuery");
        }

        if ($this->db !== $other->db) {
            throw new \InvalidArgumentException("Cannot except PartialQueries from different databases");
        }

        $new = clone $this;
        $new->ensureMutableAST();  // Ensure we own our AST before mutating

        // Create EXCEPT node from inner queries (without CTE wrappers)
        $leftInner = $new->getInnerQuery();
        $rightInner = $other->getAST();  // Deep-clones $other's AST
        if ($rightInner instanceof WithStatement) {
            $rightInner = $rightInner->query;
        }

        $exceptNode = new UnionNode($leftInner, $rightInner, false, 'EXCEPT');

        // If we had CTEs, rewrap with them; otherwise just use the except
        if ($new->ast instanceof WithStatement) {
            $new->ast->query = $exceptNode;
        } else {
            $new->ast = $exceptNode;
        }

        // Merge CTEs from other query
        $new->mergeCTEsFrom($other);

        return $new;
    }

    /**
     * Merge CTEs from another query into this one
     *
     * Used by union/except to combine CTEs from both queries.
     * CTEs with same name and same AST object are deduplicated.
     *
     * @throws \LogicException If conflicting CTE definitions are detected
     */
    private function mergeCTEsFrom(self $other): void
    {
        $other->ensureAST();
        if (!$other->ast instanceof WithStatement) {
            return; // No CTEs to merge
        }

        $with = $this->ensureWithStatement();

        foreach ($other->ast->ctes as $foreignCte) {
            $existingIdx = $this->findCTEIndex($foreignCte['name']);
            if ($existingIdx !== null) {
                // Same name - only skip if it's literally the same AST node
                if ($with->ctes[$existingIdx]['query'] === $foreignCte['query']) {
                    continue;
                }
                throw new \LogicException(
                    "Conflicting CTE definition for '{$foreignCte['name']}' between combined PartialQueries."
                );
            }
            // Deep-clone the foreign CTE query to avoid aliasing
            $with->ctes[] = [
                'name' => $foreignCte['name'],
                'columns' => $foreignCte['columns'],
                'query' => $foreignCte['query']->deepClone(),
            ];
        }
    }

    /**
     * Get the inner query (without CTE wrapper)
     */
    private function getInnerQuery(): ASTNode
    {
        $this->ensureAST();
        if ($this->ast instanceof WithStatement) {
            return $this->ast->query;
        }
        return $this->ast;
    }

    /**
     * Find a CTE by name in the AST
     *
     * @return int|null Index in WithStatement->ctes, or null if not found
     */
    private function findCTEIndex(string $name): ?int
    {
        if (!$this->ast instanceof WithStatement) {
            return null;
        }
        foreach ($this->ast->ctes as $i => $cte) {
            if ($cte['name'] === $name) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Ensure AST is wrapped in a WithStatement (for adding CTEs)
     *
     * If already a WithStatement, returns it. Otherwise wraps the current AST.
     */
    private function ensureWithStatement(): WithStatement
    {
        $this->ensureMutableAST();

        if ($this->ast instanceof WithStatement) {
            return $this->ast;
        }

        $this->ast = new WithStatement([], false, $this->ast);
        return $this->ast;
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

        // Get source query's AST and CTEs
        $sourceAst = $query->getAST();
        $sourceCtes = [];
        $sourceInnerQuery = $sourceAst;

        if ($sourceAst instanceof WithStatement) {
            $sourceCtes = $sourceAst->ctes;
            $sourceInnerQuery = $sourceAst->query;
        }

        // Check for name conflict with source query's CTEs
        foreach ($sourceCtes as $cte) {
            if ($cte['name'] === $name) {
                throw new \LogicException(
                    "Cannot add CTE '{$name}': the source query already has a CTE with this name. " .
                    'CTE shadowing is not supported.'
                );
            }
        }

        // Clone and ensure we have a WithStatement
        $this->ensureAST();
        $new = clone $this;
        $with = $new->ensureWithStatement();

        // Check for name conflict with existing CTEs
        if ($new->findCTEIndex($name) !== null) {
            throw new \LogicException(
                "Cannot add CTE '{$name}': a CTE with this name already exists. " .
                'CTE shadowing is not supported.'
            );
        }

        // Merge source CTEs into our WithStatement (already deep-cloned via getAST())
        foreach ($sourceCtes as $foreignCte) {
            $existingIdx = $new->findCTEIndex($foreignCte['name']);
            if ($existingIdx !== null) {
                // Same object = same CTE, skip
                if ($with->ctes[$existingIdx]['query'] === $foreignCte['query']) {
                    continue;
                }
                throw new \LogicException(
                    "Conflicting CTE definition for '{$foreignCte['name']}' between combined PartialQueries."
                );
            }
            $with->ctes[] = $foreignCte;
        }

        // Add the new CTE
        $with->ctes[] = [
            'name' => $name,
            'columns' => null,
            'query' => $sourceInnerQuery,
        ];

        return $new;
    }


    /**
     * Set SELECT clause (wraps query with new projection)
     *
     * Creates: SELECT {selectPart} FROM ({current_query}) AS _q
     *
     * Validates that column references are available (if restricted).
     * Computed expressions like `a + b as sum` are allowed if 'a' and 'b' exist.
     */
    public function select(string $selectPart): self
    {
        // Parse the select part to get column nodes
        $parser = new SqlParser();
        $columns = [];
        try {
            $tempSql = "SELECT {$selectPart} FROM _dummy";
            $tempAst = $parser->parse($tempSql);
            if ($tempAst instanceof SelectStatement) {
                $columns = $tempAst->columns;
            }
        } catch (\Throwable $e) {
            // Fallback: create a simple column node
            $columns = [new ColumnNode(new IdentifierNode([$selectPart]))];
        }

        // If we have restricted columns, validate all references
        if ($this->availableColumns !== null) {
            foreach ($columns as $col) {
                if ($col instanceof ColumnNode) {
                    $refs = $this->extractColumnRefs($col->expression);
                    foreach ($refs as $ref) {
                        if (!isset($this->availableColumns[$ref])) {
                            $available = implode(', ', array_keys($this->availableColumns));
                            throw new \InvalidArgumentException(
                                "Column '$ref' is not available. Available columns: $available"
                            );
                        }
                    }
                }
            }
        }

        // Determine output column names
        $outputColumns = [];
        foreach ($columns as $col) {
            if ($col instanceof ColumnNode) {
                $name = $this->getColumnOutputName($col);
                if ($name !== null) {
                    $outputColumns[$name] = true;
                }
            }
        }

        $new = $this->selectInternal($selectPart);

        // Update available columns to output names (if we tracked any)
        // Only restrict if we had restrictions or we have definite output names
        if ($this->availableColumns !== null || !empty($outputColumns)) {
            $new->availableColumns = $outputColumns ?: null;
        }

        return $new;
    }

    /**
     * Internal: wrap query with new SELECT clause
     */
    private function selectInternal(string $selectPart): self
    {
        $new = clone $this;
        $new->ensureMutableAST();
        $new->selectCalled = true;

        // Extract CTEs - they must bubble up (can't be inside subquery)
        $ctes = [];
        $innerAst = $new->ast;
        if ($new->ast instanceof WithStatement) {
            $ctes = $new->ast->ctes;
            $innerAst = $new->ast->query;
        }

        $wrapper = new SelectStatement();

        $parser = new SqlParser();
        try {
            $tempSql = "SELECT {$selectPart} FROM _dummy";
            $tempAst = $parser->parse($tempSql);
            if ($tempAst instanceof SelectStatement) {
                $wrapper->columns = $tempAst->columns;
            }
        } catch (\Throwable $e) {
            $wrapper->columns = [new ColumnNode(new IdentifierNode([$selectPart]))];
        }

        $wrapper->from = new SubqueryNode($innerAst);
        $wrapper->fromAlias = '_q';

        // Re-apply CTEs to the outside
        if (!empty($ctes)) {
            $new->ast = new WithStatement($ctes, false, $wrapper);
        } else {
            $new->ast = $wrapper;
        }
        return $new;
    }

    /**
     * Extract column references from an expression AST
     *
     * @return array<string> Column names referenced in the expression
     */
    private function extractColumnRefs(ASTNode $node): array
    {
        $refs = [];

        if ($node instanceof IdentifierNode) {
            // Single-part identifier is a column reference
            // Multi-part (table.column) - take the last part as column name
            $refs[] = $node->parts[count($node->parts) - 1];
        } elseif ($node instanceof BinaryOperation) {
            $refs = array_merge($refs, $this->extractColumnRefs($node->left));
            $refs = array_merge($refs, $this->extractColumnRefs($node->right));
        } elseif ($node instanceof UnaryOperation) {
            $refs = array_merge($refs, $this->extractColumnRefs($node->expression));
        } elseif ($node instanceof FunctionCallNode) {
            foreach ($node->arguments as $arg) {
                $refs = array_merge($refs, $this->extractColumnRefs($arg));
            }
        } elseif ($node instanceof SubqueryNode) {
            // Don't descend into subqueries - they have their own scope
        }
        // LiteralNode, PlaceholderNode - no column refs

        return $refs;
    }

    /**
     * Get the output name of a column node
     *
     * @return string|null Column name (alias or simple identifier), null if complex
     */
    private function getColumnOutputName(ColumnNode $col): ?string
    {
        // If aliased, use the alias
        if ($col->alias !== null) {
            return $col->alias;
        }

        // If simple identifier, use it
        if ($col->expression instanceof IdentifierNode) {
            $parts = $col->expression->parts;
            return $parts[count($parts) - 1];
        }

        // Complex expression without alias - can't determine name
        return null;
    }

    /**
     * Project to specific columns (TableInterface method)
     *
     * Enforces narrowing: once columns are restricted, you can only
     * narrow further, not add columns back.
     */
    public function columns(string ...$columns): self
    {
        // Validate narrowing if we already have restricted columns
        if ($this->availableColumns !== null) {
            foreach ($columns as $col) {
                if (!isset($this->availableColumns[$col])) {
                    $available = implode(', ', array_keys($this->availableColumns));
                    throw new \InvalidArgumentException(
                        "Column '$col' is not available. Available columns: $available"
                    );
                }
            }
        }

        $quoted = array_map(fn($c) => $this->db->quoteIdentifier($c), $columns);
        $new = $this->selectInternal(implode(', ', $quoted));

        // Update available columns to exactly what was requested
        $new->availableColumns = array_fill_keys($columns, true);

        return $new;
    }

    /**
     * Check if value(s) exist in the table (SetInterface method)
     *
     * Requires columns() to be called first to specify which columns to check.
     */
    public function has(object $member): bool
    {
        if (!$this->selectCalled) {
            throw new \RuntimeException("has() requires columns() or select() to be called first");
        }

        $query = $this;
        foreach (get_object_vars($member) as $col => $value) {
            $query = $query->eq($col, $value);
        }
        return $query->limit(1)->one() !== null;
    }


    /**
     * Set ORDER BY clause (overwrites previous)
     *
     * The order specification is parsed into AST for clean composition.
     */
    public function order(?string $orderSpec): TableInterface
    {
        // If pagination exists, barrier first to preserve row membership
        // Without barrier, ORDER BY could change which rows are selected
        if ($this->hasPagination()) {
            return $this->barrier()->order($orderSpec);
        }

        $new = clone $this;
        $select = $new->getModifiableSelect();

        if ($orderSpec === null) {
            $select->orderBy = null;
            return $new;
        }

        $parser = new SqlParser();
        $select->orderBy = $parser->parseOrderByFragment($orderSpec);
        return $new;
    }

    /**
     * Set LIMIT (can only narrow, never expand)
     *
     * PartialQuery represents a "window" into data. Limit can shrink the
     * window but never expand it beyond what was originally available.
     *
     * ```php
     * $q->limit(10)->limit(5);   // limit becomes 5 (shrink OK)
     * $q->limit(10)->limit(20);  // limit stays 10 (can't expand)
     * ```
     */
    public function limit(int $limit): self
    {
        $new = clone $this;
        $select = $new->getModifiableSelect();

        // Get current limit (if any)
        $currentLimit = $select->limit instanceof LiteralNode
            ? (int) $select->limit->value
            : null;

        // Can only shrink, never expand
        if ($currentLimit !== null) {
            $limit = min($limit, $currentLimit);
        }

        $select->limit = new LiteralNode($limit, 'number');
        return $new;
    }

    /**
     * Set OFFSET (additive, stays within window)
     *
     * Offset is added to any existing offset. If there's a limit, it's
     * reduced accordingly to stay within the original window.
     *
     * ```php
     * $q->limit(10)->offset(5);  // becomes LIMIT 5 OFFSET 5 (still within 10)
     * $q->offset(10)->offset(5); // becomes OFFSET 15
     * ```
     */
    public function offset(int $offset): self
    {
        $new = clone $this;
        $select = $new->getModifiableSelect();

        // Get current offset and limit
        $currentOffset = $select->offset instanceof LiteralNode
            ? (int) $select->offset->value
            : 0;
        $currentLimit = $select->limit instanceof LiteralNode
            ? (int) $select->limit->value
            : null;

        // New offset is additive
        $newOffset = $currentOffset + $offset;

        // If there was a limit, shrink it by the offset amount (stay within window)
        if ($currentLimit !== null) {
            $newLimit = max(0, $currentLimit - $offset);
            $select->limit = new LiteralNode($newLimit, 'number');
        }

        $select->offset = $newOffset > 0 ? new LiteralNode($newOffset, 'number') : null;
        return $new;
    }

    /**
     * Create a barrier - wrap current query as subquery (internal)
     *
     * Automatically called by filter methods (where, in, or) when pagination
     * exists, ensuring filters apply to the paginated result set rather than
     * modifying the original query structure.
     *
     * CTEs are captured inside the barrier (wrapped in the subquery).
     */
    protected function barrier(): self
    {
        // Borrow AST - marks $this as shared so it clones on mutation
        $ast = $this->borrowAstForEmbedding();
        $new = clone $this;

        // Wrap the entire AST (including any CTEs) in a subquery
        $new->ast = $new->wrapInSelect($ast);

        return $new;
    }

    /**
     * Return distinct rows only
     */
    public function distinct(): TableInterface
    {
        // If pagination exists, barrier first to preserve row membership
        // Without barrier, DISTINCT could change which rows are selected
        if ($this->hasPagination()) {
            return $this->barrier()->distinct();
        }

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
     * Get SelectStatement for reading (without triggering copy-on-write)
     */
    private function getSelectForReading(): ?SelectStatement
    {
        if ($this->ast === null) {
            return null;
        }

        if ($this->ast instanceof SelectStatement) {
            return $this->ast;
        }

        if ($this->ast instanceof WithStatement && $this->ast->query instanceof SelectStatement) {
            return $this->ast->query;
        }

        return null;
    }

    /**
     * Check if query has pagination (LIMIT or OFFSET)
     *
     * Used to determine if filter operations need barrier() to preserve
     * window semantics. Forces AST parsing if not yet done.
     */
    private function hasPagination(): bool
    {
        $this->ensureAST();
        $select = $this->getSelectForReading();
        return $select !== null && ($select->limit !== null || $select->offset !== null);
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
     * Uses the executor path (AST-based) for consistency with iteration.
     *
     * @return array<int, mixed>
     */
    public function column(): array
    {
        $result = [];
        foreach ($this as $row) {
            $vars = get_object_vars($row);
            $result[] = reset($vars);
        }
        return $result;
    }

    /**
     * Fetch first column of first row
     *
     * Uses the executor path (AST-based) for consistency with iteration.
     *
     * @return mixed
     */
    public function field(): mixed
    {
        foreach ($this->limit(1) as $row) {
            $vars = get_object_vars($row);
            return reset($vars);
        }
        return null;
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
     * Count rows that would be returned by iteration
     *
     * Respects LIMIT/OFFSET if set on the query.
     */
    public function count(): int
    {
        $this->ensureAST();

        // Clone and strip ORDER BY for performance (doesn't affect count)
        $innerAst = $this->ast->deepClone();
        $this->stripOrderBy($innerAst);

        // Build: SELECT COUNT(*) FROM (innerAst) AS _count
        $countSelect = new SelectStatement();
        $countSelect->columns = [
            new ColumnNode(new FunctionCallNode('COUNT', [new IdentifierNode(['*'])]), null)
        ];
        $countSelect->from = new SubqueryNode($innerAst);
        $countSelect->fromAlias = '_count';

        // Execute via executor and extract count from first row
        // Pass $this for context (db reference), but use constructed countSelect AST
        $rows = ($this->executor)($this, $countSelect);
        foreach ($rows as $row) {
            $vars = get_object_vars($row);
            return (int) reset($vars);
        }

        return 0;
    }

    /**
     * Strip ORDER BY from an AST (mutates)
     */
    private function stripOrderBy(ASTNode $ast): void
    {
        if ($ast instanceof SelectStatement) {
            $ast->orderBy = null;
        } elseif ($ast instanceof WithStatement && $ast->query instanceof SelectStatement) {
            $ast->query->orderBy = null;
        }
    }

    /**
     * Iterator over results (streaming)
     *
     * @return \Traversable<int, T>
     */
    public function getIterator(): \Traversable
    {
        // Pass both query and AST to executor
        // If AST is null, executor can use fast path with baseSql/originalParams
        $rows = ($this->executor)($this, $this->ast);

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
        $this->ensureAST();

        // Get WHERE from AST
        $select = $this->getSelectForReading();
        if ($select === null || $select->where === null) {
            return ['sql' => '', 'params' => []];
        }

        // Derive SQL and params from the WHERE clause (params are bound in PlaceholderNodes)
        $renderer = SqlRenderer::forDialect($this->db->getDialect());
        [$sql, $params] = $renderer->renderWithParams($select->where);

        return [
            'sql'    => $sql,
            'params' => $params,
        ];
    }

    /**
     * Test if a row matches the query's WHERE conditions
     *
     * Evaluates the full WHERE clause from the AST against the row.
     * This works correctly with barrier() - conditions apply to the
     * appropriate level of query nesting.
     *
     * @param object $row The row to test
     * @return bool True if row matches all WHERE conditions
     * @throws \RuntimeException If query has CTEs or UNION (can't evaluate)
     */
    public function matches(object $row): bool
    {
        $this->ensureAST();

        // CTEs not supported for in-memory evaluation
        if ($this->ast instanceof WithStatement) {
            throw new \RuntimeException(
                'matches() is not supported for queries with CTEs. ' .
                'Use the database to execute the query instead.'
            );
        }

        // UnionNode - can't easily evaluate
        if (!$this->ast instanceof SelectStatement) {
            throw new \RuntimeException(
                'matches() is not supported for UNION/EXCEPT queries. ' .
                'Use the database to execute the query instead.'
            );
        }

        // No WHERE clause - all rows match
        if ($this->ast->where === null) {
            return true;
        }

        // Params are already bound in PlaceholderNodes - evaluate directly
        $evaluator = new ExpressionEvaluator();
        return $evaluator->evaluateAsBool($this->ast->where, $row);
    }

    /**
     * Get LIMIT value (for DELETE/UPDATE implementations)
     *
     * Returns null if no limit was explicitly set.
     */
    public function getLimit(): ?int
    {
        $select = $this->getSelectForReading();
        if ($select !== null && $select->limit instanceof LiteralNode) {
            return (int) $select->limit->value;
        }
        return null;
    }

    /**
     * Get current offset
     */
    public function getOffset(): int
    {
        $select = $this->getSelectForReading();
        if ($select !== null && $select->offset instanceof LiteralNode) {
            return (int) $select->offset->value;
        }
        return 0;
    }

    /**
     * Check if any rows exist
     */
    public function exists(): bool
    {
        return $this->limit(1)->one() !== null;
    }

    /** @var array<string, ColumnDef>|null Cached column definitions */
    private ?array $columnCache = null;

    /**
     * Get column definitions
     *
     * Returns columns from the database schema for single-table SELECT * queries,
     * or extracts column names from the AST for explicit column lists.
     * Returns empty array for complex queries (JOINs, UNIONs, subqueries).
     *
     * @return array<string, ColumnDef>
     */
    public function getColumns(): array
    {
        return $this->getAllColumns();
    }

    /**
     * Get all column definitions (including hidden columns)
     *
     * Fetches column information from:
     * - Database schema for single-table SELECT * queries
     * - AST column list for explicit projections
     * - Returns empty array for complex queries
     *
     * @return array<string, ColumnDef>
     */
    public function getAllColumns(): array
    {
        if ($this->columnCache !== null) {
            return $this->columnCache;
        }

        $this->columnCache = $this->fetchColumnDefinitions();
        return $this->columnCache;
    }

    /**
     * Fetch column definitions from schema or AST
     *
     * @return array<string, ColumnDef>
     */
    private function fetchColumnDefinitions(): array
    {
        $this->ensureAST();

        // Get the inner SELECT (skip WithStatement wrapper if present)
        $ast = $this->ast;
        if ($ast instanceof WithStatement) {
            $ast = $ast->query;
        }

        // Complex queries (UNION, etc.) - can't determine columns
        if (!$ast instanceof SelectStatement) {
            return [];
        }

        // JOINs - complex, skip for now
        if (!empty($ast->joins)) {
            return [];
        }

        // Check if SELECT * from single table
        $columns = $ast->columns;
        if (count($columns) === 1
            && $columns[0] instanceof ColumnNode
            && $columns[0]->expression instanceof IdentifierNode
            && $columns[0]->expression->isWildcard()
        ) {
            // SELECT * - fetch from schema
            return $this->fetchSchemaColumns($ast);
        }

        // Explicit column list - extract from AST
        return $this->extractColumnsFromAST($columns);
    }

    /**
     * Fetch column definitions from database schema
     *
     * @return array<string, ColumnDef>
     */
    private function fetchSchemaColumns(SelectStatement $ast): array
    {
        // Need a simple table reference
        if (!$ast->from instanceof IdentifierNode) {
            return [];
        }

        $tableName = $ast->from->getFullName();
        $result = [];

        foreach ($this->db->getSchema()->eq('table_name', $tableName)->eq('type', 'column') as $col) {
            $type = $this->mapSqlTypeToColumnType($col->data_type ?? 'TEXT');
            $result[$col->name] = new ColumnDef($col->name, $type);
        }

        return $result;
    }

    /**
     * Extract column definitions from explicit SELECT list
     *
     * @param ColumnNode[] $columns
     * @return array<string, ColumnDef>
     */
    private function extractColumnsFromAST(array $columns): array
    {
        $result = [];

        foreach ($columns as $col) {
            if (!$col instanceof ColumnNode) {
                continue;
            }

            // Use alias if present, otherwise try to get column name from expression
            $name = $col->alias;
            if ($name === null) {
                if ($col->expression instanceof IdentifierNode) {
                    $name = $col->expression->getName();
                } elseif ($col->expression instanceof FunctionCallNode) {
                    // For functions like COUNT(*), use the function name as fallback
                    $name = strtolower($col->expression->name);
                } else {
                    // Skip columns we can't name
                    continue;
                }
            }

            // Default to Text type - we don't know the actual type without schema
            $result[$name] = new ColumnDef($name, ColumnType::Text);
        }

        return $result;
    }

    /**
     * Map SQL data type string to ColumnType
     */
    private function mapSqlTypeToColumnType(?string $sqlType): ColumnType
    {
        if ($sqlType === null) {
            return ColumnType::Text;
        }

        $sqlType = strtoupper($sqlType);

        // Integer types
        if (str_contains($sqlType, 'INT')) {
            return ColumnType::Int;
        }

        // Float types
        if (str_contains($sqlType, 'REAL') || str_contains($sqlType, 'FLOAT') || str_contains($sqlType, 'DOUBLE')) {
            return ColumnType::Float;
        }

        // Decimal types
        if (str_contains($sqlType, 'DECIMAL') || str_contains($sqlType, 'NUMERIC')) {
            return ColumnType::Decimal;
        }

        // Date/time types
        if ($sqlType === 'DATE') {
            return ColumnType::Date;
        }
        if ($sqlType === 'TIME') {
            return ColumnType::Time;
        }
        if (str_contains($sqlType, 'DATETIME') || str_contains($sqlType, 'TIMESTAMP')) {
            return ColumnType::DateTime;
        }

        // Binary types
        if (str_contains($sqlType, 'BLOB') || str_contains($sqlType, 'BINARY')) {
            return ColumnType::Binary;
        }

        // Default to Text
        return ColumnType::Text;
    }

    /**
     * OR predicate support
     *
     * Adds an OR condition to the WHERE clause. Each predicate's conditions
     * are ANDed together, then predicates are ORed.
     *
     * Example: or(p->eq('a', 1)->eq('b', 2), p->eq('c', 3))
     * Produces: WHERE ... AND ((a = 1 AND b = 2) OR (c = 3))
     *
     * Requires at least 2 predicates - OR semantically needs multiple alternatives.
     */
    public function or(Predicate $a, Predicate $b, Predicate ...$more): TableInterface
    {
        $predicates = [$a, $b, ...$more];

        // If pagination exists, wrap in barrier first to preserve window semantics
        if ($this->hasPagination()) {
            return $this->barrier()->or($a, $b, ...$more);
        }

        // Convert predicates to AST and OR them together
        $orCondition = null;
        foreach ($predicates as $predicate) {
            $predicateAst = $this->predicateToAst($predicate);
            if ($predicateAst === null) {
                continue; // Empty predicate matches everything
            }
            if ($orCondition === null) {
                $orCondition = $predicateAst;
            } else {
                $orCondition = new BinaryOperation($orCondition, 'OR', $predicateAst);
            }
        }

        if ($orCondition === null) {
            return $this; // All predicates were empty
        }

        // Add to WHERE clause
        $new = clone $this;
        $new->ensureMutableAST();
        $select = $new->getModifiableSelect();

        if ($select->where === null) {
            $select->where = $orCondition;
        } else {
            $select->where = new BinaryOperation($select->where, 'AND', $orCondition);
        }

        return $new;
    }

    /**
     * Convert a Predicate to AST expression
     *
     * Returns null for empty predicates (match everything).
     * Throws for predicates that match nothing.
     */
    private function predicateToAst(Predicate $predicate): ?ASTNode
    {
        $conditions = $predicate->getConditions();

        if (empty($conditions)) {
            return null; // Empty predicate matches everything
        }

        $result = null;
        foreach ($conditions as $cond) {
            $condAst = $this->conditionToAst($cond);
            if ($result === null) {
                $result = $condAst;
            } else {
                $result = new BinaryOperation($result, 'AND', $condAst);
            }
        }

        return $result;
    }

    /**
     * Convert a single condition to AST
     */
    private function conditionToAst(array $cond): ASTNode
    {
        $column = new IdentifierNode([$cond['column']]);
        $value = $cond['value'];
        $bound = $cond['bound'];

        // Create value node - bound values become literals, unbound become placeholders
        if ($cond['operator'] === Operator::In) {
            // IN requires special handling - value is a SetInterface
            if ($value instanceof SetInterface) {
                // Convert set to list of values for IN clause
                // Set should already be projected to the right column(s)
                $values = [];
                $setColumns = array_keys($value->getColumns());
                $colName = $setColumns[0] ?? $cond['column'];
                foreach ($value as $row) {
                    $rowValue = $row->$colName;
                    $values[] = $this->valueToLiteral($rowValue);
                }
                return new InOperation($column, $values, false);
            }
            throw new \RuntimeException("IN operator requires SetInterface value");
        }

        if ($cond['operator'] === Operator::Like) {
            $valueNode = $bound
                ? $this->valueToLiteral($value)
                : $this->createBoundPlaceholder($value);
            return new LikeOperation($column, $valueNode, false);
        }

        // Standard comparison operators
        $valueNode = $bound
            ? $this->valueToLiteral($value)
            : $this->createBoundPlaceholder($value);

        return new BinaryOperation($column, $cond['operator']->value, $valueNode);
    }

    /**
     * Convert a PHP value to a LiteralNode
     */
    private function valueToLiteral(mixed $value): LiteralNode
    {
        if ($value === null) {
            return new LiteralNode(null, 'null');
        }
        if (is_int($value) || is_float($value)) {
            return new LiteralNode((string)$value, 'number');
        }
        return new LiteralNode($value, 'string');
    }

    /**
     * Create a bound placeholder (named parameter that's already resolved)
     */
    private function createBoundPlaceholder(string $paramName): PlaceholderNode
    {
        // For unbound predicates, we create a named placeholder
        // The predicate should be bound before use
        $placeholder = new PlaceholderNode($paramName);
        // Note: The predicate's bind() should have been called before or()
        // If not, this will fail at evaluation time
        return $placeholder;
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
     * Note: CTE params are included in the AST and returned by getSql().
     *
     * @return array{sql: string, params: array<int, mixed>}
     */
    public function getCTEs(): array
    {
        $this->ensureAST();

        if (!$this->ast instanceof WithStatement || empty($this->ast->ctes)) {
            return ['sql' => '', 'params' => []];
        }

        $renderer = SqlRenderer::forDialect($this->db->getDialect());
        $withParts = [];
        foreach ($this->ast->ctes as $cte) {
            $withParts[] = $cte['name'] . ' AS (' . $renderer->render($cte['query']) . ')';
        }

        return [
            'sql' => 'WITH ' . implode(', ', $withParts) . ' ',
            'params' => [], // Params are bound in AST, returned by getSql()
        ];
    }

    /**
     * Debug information for var_dump/print_r
     */
    public function __debugInfo(): array
    {
        [$sql, $params] = $this->getSql($this->db->getDialect());
        return [
            'sql'    => $sql,
            'params' => $params,
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
        [$sql, $params] = $this->getSql($this->db->getDialect());
        $db = $this->db;

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

    // =========================================================================
    // MutableTableInterface implementation
    // =========================================================================

    /**
     * Insert a new row into the underlying table
     *
     * The row must match ALL query constraints (WHERE clause conditions).
     * This ensures you can only insert rows that would be visible in this query.
     *
     * ```php
     * $users = db()->query('SELECT * FROM users WHERE org_id = ?', [5]);
     * $users->insert(['org_id' => 5, 'name' => 'John']);  // OK
     * $users->insert(['org_id' => 6, 'name' => 'Jane']);  // Throws - wrong org_id
     * ```
     *
     * @throws \RuntimeException If row doesn't match query constraints
     * @throws \RuntimeException If query is not single-table
     */
    public function insert(array $row): int|string
    {
        if (!$this->isSingleTable()) {
            throw new \RuntimeException("Cannot insert: query has JOINs, UNIONs, or complex FROM");
        }

        // Check row matches ALL query constraints
        if (!$this->matches((object) $row)) {
            throw new \RuntimeException("Cannot insert: row violates query constraints");
        }

        return $this->db->insert($this->getSourceTable(), $row);
    }

    /**
     * Update rows matching this query
     *
     * Updates are scoped to rows matching this query's WHERE clause.
     * The $query parameter allows additional filtering within that scope.
     *
     * ```php
     * $users = db()->query('SELECT * FROM users WHERE org_id = ?', [5]);
     * $users->update($users->eq('active', false), ['status' => 'inactive']);
     * // Only updates inactive users within org_id = 5
     * ```
     *
     * @param TableInterface $query Query defining which rows to update (must be derived from this query)
     * @param array $changes Column => value pairs to update
     * @throws \RuntimeException If query is not single-table
     */
    public function update(TableInterface $query, array $changes): int
    {
        if (!$this->isSingleTable()) {
            throw new \RuntimeException("Cannot update: query has JOINs, UNIONs, or complex FROM");
        }

        // Get the filtering query
        $filterQuery = $query instanceof self ? $query : $this;

        // Combine base constraints with filter query
        $combinedQuery = $this->combineWithFilter($filterQuery);

        return $this->db->update($combinedQuery, $changes);
    }

    /**
     * Delete rows matching this query
     *
     * Deletes are scoped to rows matching this query's WHERE clause.
     * The $query parameter allows additional filtering within that scope.
     *
     * ```php
     * $users = db()->query('SELECT * FROM users WHERE org_id = ?', [5]);
     * $users->delete($users->eq('status', 'deleted'));
     * // Only deletes users with status='deleted' within org_id = 5
     * ```
     *
     * @param TableInterface $query Query defining which rows to delete (must be derived from this query)
     * @throws \RuntimeException If query is not single-table
     */
    public function delete(TableInterface $query): int
    {
        if (!$this->isSingleTable()) {
            throw new \RuntimeException("Cannot delete: query has JOINs, UNIONs, or complex FROM");
        }

        // Get the filtering query
        $filterQuery = $query instanceof self ? $query : $this;

        // Combine base constraints with filter query
        $combinedQuery = $this->combineWithFilter($filterQuery);

        return $this->db->delete($combinedQuery);
    }

    /**
     * Combine this query's constraints with a filter query
     */
    private function combineWithFilter(self $filterQuery): self
    {
        // If filter query has additional WHERE conditions, apply them
        $where = $filterQuery->getWhere();
        if ($where['sql'] !== '') {
            return $this->where($where['sql'], $where['params']);
        }
        return $this;
    }
}
