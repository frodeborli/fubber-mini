<?php

namespace mini\Database;

use mini\Parsing\SQL\SqlParser;
use mini\Parsing\SQL\AST\{
    ASTNode,
    SelectStatement,
    InsertStatement,
    UpdateStatement,
    DeleteStatement,
    CreateTableStatement,
    CreateIndexStatement,
    DropTableStatement,
    DropIndexStatement,
    ColumnDefinition,
    UnionNode,
    ColumnNode,
    IdentifierNode,
    LiteralNode,
    PlaceholderNode,
    BinaryOperation,
    UnaryOperation,
    LikeOperation,
    IsNullOperation,
    InOperation,
    BetweenOperation,
    ExistsOperation,
    SubqueryNode,
    FunctionCallNode,
    WindowFunctionNode,
    WithStatement
};
use mini\Table\Predicate;
use mini\Table\ColumnDef;
use mini\Table\InMemoryTable;
use mini\Table\ArrayTable;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;
use mini\Table\Contracts\MutableTableInterface;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\Utility\ColumnMappedSet;
use mini\Table\Utility\EmptyTable;
use mini\Table\Utility\SingleRowTable;
use mini\Table\Wrappers\ConcatTable;
use mini\Table\Wrappers\CrossJoinTable;
use mini\Table\Wrappers\FullJoinTable;
use mini\Table\Wrappers\InnerJoinTable;
use mini\Table\Wrappers\LeftJoinTable;
use mini\Table\Wrappers\RightJoinTable;
use mini\Table\Wrappers\SqlExceptTable;
use mini\Table\Wrappers\MultisetSetOpTable;
use mini\Table\Wrappers\SqlIntersectTable;

/**
 * Virtual database that executes SQL against registered TableInterface instances
 *
 * Implements DatabaseInterface to provide a drop-in replacement for PDODatabase
 * when working with in-memory table data structures.
 *
 * Phase 1: Single-table operations
 * - SELECT with WHERE, ORDER BY, LIMIT, column projection
 * - INSERT, UPDATE, DELETE on MutableTableInterface
 * - Subqueries in IN clauses: WHERE col IN (SELECT ...)
 *
 * Future phases will add JOINs, aggregates, DISTINCT, correlated subqueries.
 *
 * Usage:
 * ```php
 * $vdb = new VirtualDatabase();
 * $vdb->registerTable('users', $usersTable);
 * $vdb->registerTable('orders', $ordersTable);
 *
 * // SELECT queries return ResultSetInterface
 * foreach ($vdb->query('SELECT name, email FROM users WHERE status = ?', ['active']) as $row) {
 *     echo $row->name;
 * }
 *
 * // INSERT/UPDATE/DELETE return affected row count
 * $affected = $vdb->exec('DELETE FROM users WHERE id = ?', [123]);
 * ```
 */
class VirtualDatabase implements DatabaseInterface
{
    /** @var array<string, TableInterface> */
    private array $tables = [];

    /** @var array<string, ModelTableConfig> tableName (lowercase) => config */
    private array $modelConfigs = [];

    /** @var array<string, array{step: callable, final: callable, argCount: int}> */
    private array $aggregates = [];

    private PrecomputedEvaluator $evaluator;

    private AstOptimizer $optimizer;

    /** Reflection accessors for PartialQuery to avoid cloning overhead */
    private static ?\ReflectionProperty $astProperty = null;
    private static ?\ReflectionMethod $ensureAstMethod = null;

    /** Last insert ID from most recent INSERT */
    private ?string $lastInsertId = null;

    /** @var \WeakMap<Query, PartialQuery> Maps Query instances to their underlying PartialQuery */
    private \WeakMap $queryMap;

    /** Maximum query execution time in seconds (null = no limit) */
    private ?float $queryTimeout = null;

    /** Maximum rows buffered for one mutation; see setMaxMaterializedRows() */
    private ?int $maxMaterializedRows = 1_000_000;

    /** Query start time for timeout tracking */
    private ?float $queryStartTime = null;

    /** Table class to use for CREATE TABLE (InMemoryTable or ArrayTable) */
    private string $tableClass = ArrayTable::class;

    /** Current session for temp table resolution (set during query execution) */
    private ?Session $currentSession = null;

    public function __construct()
    {
        $this->evaluator = new PrecomputedEvaluator();
        $this->evaluator->setSubqueryExecutor(fn($query, $outerRow) => $this->executeSubqueryWithContext($query, $outerRow));
        $this->optimizer = new AstOptimizer();
        $this->registerBuiltinAggregates();
        $this->queryMap = new \WeakMap();
    }

    /**
     * Register a custom aggregate function
     *
     * Similar to SQLite3::createAggregate. The step callback is called for each
     * row with the current context and argument values. The final callback is
     * called after all rows to produce the result.
     *
     * ```php
     * // Example: Custom GROUP_CONCAT
     * $vdb->createAggregate(
     *     'group_concat',
     *     function(&$context, $value) {
     *         $context[] = $value;
     *     },
     *     function(&$context) {
     *         return implode(',', $context ?? []);
     *     },
     *     1
     * );
     * ```
     *
     * @param string $name Function name (case-insensitive)
     * @param callable $stepCallback Called for each row: function(&$context, ...$args)
     * @param callable $finalCallback Called at end: function(&$context): mixed
     * @param int $argCount Expected argument count (-1 for variable)
     */
    public function createAggregate(
        string $name,
        callable $stepCallback,
        callable $finalCallback,
        int $argCount = -1
    ): bool {
        $this->aggregates[strtoupper($name)] = [
            'step' => $stepCallback,
            'final' => $finalCallback,
            'argCount' => $argCount,
        ];
        return true;
    }

    /**
     * Check if a function name is a registered aggregate
     */
    public function isAggregate(string $name): bool
    {
        return isset($this->aggregates[strtoupper($name)]);
    }

    /**
     * Set maximum query execution time in seconds - BEST EFFORT
     *
     * This is a cooperative limit, not a hard one. The deadline is checked
     * while a SELECT's rows are being pulled (every 100 rows), so it bounds
     * long scans and runaway result sets. It does NOT bound:
     *
     * - time spent inside a single backing-table call (a slow remote
     *   TableInterface can block indefinitely; give it its own timeout),
     * - statements that are not SELECTs - INSERT/UPDATE/DELETE run to
     *   completion (their unbounded cases are bounded by the row cap, see
     *   setMaxMaterializedRows()),
     * - work that produces no rows to iterate, such as a sort or hash build
     *   over a very large input.
     *
     * Treat it as a guard rail that makes runaway SELECTs fail loudly, not
     * as a security boundary. When accepting untrusted SQL, combine it with
     * setMaxMaterializedRows(), a memory_limit, and a request-level timeout.
     *
     * @param float|null $seconds Maximum time in seconds, null to disable
     */
    public function setQueryTimeout(?float $seconds): self
    {
        $this->queryTimeout = $seconds;
        return $this;
    }

    /**
     * Set the maximum number of rows the engine will buffer for one mutation
     *
     * A mutation whose source reads the table it writes (`INSERT INTO t
     * SELECT ... FROM t`) must buffer its source rows before applying any
     * change - otherwise the new rows feed back into the scan and the
     * statement never terminates. Buffering makes it terminate; this cap
     * makes a genuinely enormous source fail loudly with an actionable
     * error instead of exhausting memory.
     *
     * Defaults to 1,000,000 rows. Raise it for legitimate bulk copies, or
     * lower it when accepting untrusted SQL.
     *
     * @param int|null $rows Maximum buffered rows, null to disable the cap
     */
    public function setMaxMaterializedRows(?int $rows): self
    {
        if ($rows !== null && $rows < 1) {
            throw new \InvalidArgumentException('Max materialized rows must be at least 1, or null to disable');
        }
        $this->maxMaterializedRows = $rows;
        return $this;
    }

    /**
     * Use ArrayTable instead of InMemoryTable for CREATE TABLE
     *
     * ArrayTable is a pure PHP implementation without SQLite dependency.
     * Useful for benchmarking or environments without SQLite extension.
     */
    public function useArrayTable(): self
    {
        $this->tableClass = ArrayTable::class;
        return $this;
    }

    /**
     * Create a new session for this database
     *
     * Sessions provide isolated temporary table storage. Each session has its
     * own namespace for TEMPORARY tables, preventing collisions in fiber/coroutine
     * environments where multiple requests may share the same VDB instance.
     *
     * @return Session A new session wrapping this database
     */
    public function session(): Session
    {
        return new Session($this);
    }

    /**
     * Execute a SELECT query with session context
     *
     * @internal Used by Session to execute queries with temp table resolution
     */
    public function queryWithSession(string $sql, array $params, Session $session): Query
    {
        $pq = PartialQuery::fromSql($this, $this->rawExecutorWithSession($session), $sql, $params);
        return $this->wrapQuery($pq);
    }

    /**
     * Execute an INSERT/UPDATE/DELETE/DDL with session context
     *
     * @internal Used by Session to execute statements with temp table support
     */
    public function execWithSession(string $sql, array $params, Session $session): int
    {
        $ast = SqlParser::parseWithParams($sql, $params);

        if ($ast instanceof InsertStatement) {
            return $this->executeInsertWithSession($ast, $session);
        }

        if ($ast instanceof UpdateStatement) {
            return $this->executeUpdateWithSession($ast, $session);
        }

        if ($ast instanceof DeleteStatement) {
            return $this->executeDeleteWithSession($ast, $session);
        }

        if ($ast instanceof CreateTableStatement) {
            return $this->executeCreateTableWithSession($ast, $session);
        }

        if ($ast instanceof DropTableStatement) {
            return $this->executeDropTableWithSession($ast, $session);
        }

        if ($ast instanceof CreateIndexStatement) {
            return 0;
        }

        if ($ast instanceof DropIndexStatement) {
            return 0;
        }

        throw new \RuntimeException("exec() only accepts INSERT, UPDATE, DELETE, or DDL statements");
    }

    /**
     * Check if current query has exceeded timeout
     *
     * @throws QueryTimeoutException if timeout exceeded
     */
    private function checkTimeout(): void
    {
        if ($this->queryTimeout !== null && $this->queryStartTime !== null) {
            $elapsed = microtime(true) - $this->queryStartTime;
            if ($elapsed > $this->queryTimeout) {
                throw new QueryTimeoutException(
                    sprintf("Query timeout: exceeded %.2f seconds", $this->queryTimeout)
                );
            }
        }
    }

    /**
     * Check if SELECT columns contain any aggregate function calls
     *
     * @param ColumnNode[] $columns
     */
    private function hasAggregates(array $columns): bool
    {
        foreach ($columns as $col) {
            if (!$col instanceof ColumnNode) {
                continue;
            }
            if ($this->expressionHasAggregate($col->expression)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Recursively check if an expression contains an aggregate function
     */
    private function expressionHasAggregate(\mini\Parsing\SQL\AST\ASTNode $node): bool
    {
        $found = [];
        $this->collectAggregateNodes($node, $found);
        return $found !== [];
    }

    /**
     * Check if any column contains a window function
     */
    private function hasWindowFunctions(array $columns): bool
    {
        foreach ($columns as $col) {
            if (!$col instanceof ColumnNode) {
                continue;
            }
            if ($this->expressionHasWindowFunction($col->expression)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Recursively check if an expression contains a window function
     */
    private function expressionHasWindowFunction(\mini\Parsing\SQL\AST\ASTNode $node): bool
    {
        return $this->extractWindowFunctions($node) !== [];
    }

    /**
     * Register built-in SQL aggregate functions
     */
    private function registerBuiltinAggregates(): void
    {
        // COUNT(*) or COUNT(column) - skips NULL values for COUNT(column)
        $this->createAggregate(
            'COUNT',
            function (&$context, $value = null) {
                // COUNT(column) skips NULLs, COUNT(*) passes 1 so never null
                if ($value !== null) {
                    $context = ($context ?? 0) + 1;
                }
            },
            function (&$context) {
                return $context ?? 0;
            },
            -1
        );

        // SUM(column)
        $this->createAggregate(
            'SUM',
            function (&$context, $value) {
                if ($value !== null) {
                    $context = ($context ?? 0) + $this->requireNumericAggregateValue('SUM', $value);
                }
            },
            function (&$context) {
                return $context;
            },
            1
        );

        // AVG(column)
        $this->createAggregate(
            'AVG',
            function (&$context, $value) {
                if ($value !== null) {
                    $context['sum'] = ($context['sum'] ?? 0)
                        + $this->requireNumericAggregateValue('AVG', $value);
                    $context['count'] = ($context['count'] ?? 0) + 1;
                }
            },
            function (&$context) {
                if (empty($context['count'])) {
                    return null;
                }
                return (float)($context['sum'] / $context['count']);
            },
            1
        );

        // MIN(column)
        $this->createAggregate(
            'MIN',
            function (&$context, $value) {
                if ($value !== null && ($context === null || $value < $context)) {
                    $context = $value;
                }
            },
            function (&$context) {
                return $context;
            },
            1
        );

        // MAX(column)
        $this->createAggregate(
            'MAX',
            function (&$context, $value) {
                if ($value !== null && ($context === null || $value > $context)) {
                    $context = $value;
                }
            },
            function (&$context) {
                return $context;
            },
            1
        );
    }

    /**
     * Guard for SUM()/AVG(): reject values PHP cannot add without a TypeError.
     *
     * Numeric strings are accepted (a Text-typed column holding "10" is common
     * in CSV/JSON backed tables) and converted; anything else raises a proper
     * SQL diagnostic instead of leaking "Unsupported operand types: int + string"
     * or a "non-numeric value encountered" warning out of the engine.
     */
    private function requireNumericAggregateValue(string $function, mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return (int)$value;
        }
        if (is_string($value) && is_numeric($value)) {
            return $value + 0;
        }
        $shown = is_scalar($value) ? var_export($value, true) : get_debug_type($value);
        throw new \RuntimeException(
            "$function() requires numeric values, got " . get_debug_type($value) . " $shown"
        );
    }

    /**
     * Register a table with a name
     */
    public function registerTable(string $name, TableInterface $table): self
    {
        $this->tables[strtolower($name)] = $table;
        return $this;
    }

    /**
     * Register a Model class for a table, enabling auth-aware operations
     *
     * Wraps the existing table with ModelScopedTable for per-entity authorization,
     * and stores scope configuration for WHERE merging at SQL level.
     *
     * Scope Queries (readable/updatable/deletable) are lazily evaluated: if null,
     * VDB calls the model's default method (e.g., User::query()) at execution time.
     * Explicit Query overrides are evaluated eagerly (must be created from this VDB).
     *
     * @param string $tableName Table name as registered in VDB
     * @param class-string<Model> $class Entity class
     * @param Query|null $readable Row-level read scope (null = $class::query())
     * @param Query|null $updatable Row-level update scope (null = $class::updatable())
     * @param Query|null $deletable Row-level delete scope (null = $class::deletable())
     * @param bool|null $allowInsert Insert gate (null = can(Ability::Create, $class))
     */
    public function registerModel(
        string $tableName,
        string $class,
        ?Query $readable = null,
        ?Query $updatable = null,
        ?Query $deletable = null,
        ?bool $allowInsert = null,
    ): self {
        $key = strtolower($tableName);

        $table = $this->tables[$key] ?? null;
        if ($table === null) {
            throw new \RuntimeException("Table '$tableName' must be registered before registerModel()");
        }

        if (!$table instanceof MutableTableInterface || !$table instanceof \mini\Table\AbstractTable) {
            throw new \RuntimeException(
                "Table '$tableName' must be a mutable AbstractTable for registerModel()"
            );
        }

        $config = new ModelTableConfig($class, $readable, $updatable, $deletable, $allowInsert);
        $this->modelConfigs[$key] = $config;
        $this->tables[$key] = new ModelScopedTable($table, $config);

        return $this;
    }

    /**
     * Get a registered table by name
     *
     * If a session is active (during query execution), checks the session's
     * temporary tables first before checking permanent tables.
     */
    public function getTable(string $name): ?TableInterface
    {
        // Check session temp tables first if we have a session context
        if ($this->currentSession !== null) {
            $tempTable = $this->currentSession->getTempTable($name);
            if ($tempTable !== null) {
                return $tempTable;
            }
        }

        return $this->tables[strtolower($name)] ?? null;
    }

    /**
     * Get all registered table names
     *
     * @return array<string> Table names
     */
    public function getTableNames(): array
    {
        return array_keys($this->tables);
    }

    /**
     * Create a new VirtualDatabase with shadowed tables
     *
     * Inherits all tables from this database, then registers the provided
     * tables which shadow any existing tables with the same names.
     *
     * @param array<string, TableInterface> $tables Table name => TableInterface
     * @return DatabaseInterface New VirtualDatabase with shadowed tables
     */
    public function withTables(array $tables): DatabaseInterface
    {
        $vdb = new self();

        // Copy existing tables
        foreach ($this->tables as $name => $table) {
            $vdb->tables[$name] = $table;
        }

        // Copy custom aggregates
        foreach ($this->aggregates as $name => $aggregate) {
            $vdb->aggregates[$name] = $aggregate;
        }

        // Copy model configs
        foreach ($this->modelConfigs as $name => $config) {
            $vdb->modelConfigs[$name] = $config;
        }

        // Shadow with provided tables
        foreach ($tables as $name => $table) {
            $vdb->registerTable($name, $table);
        }

        return $vdb;
    }

    /**
     * Execute a SELECT query
     *
     * @param string $sql SQL query
     * @param array $params Bound parameters
     * @return Query Composable query object
     */
    public function query(string $sql, array $params = []): Query
    {
        $pq = PartialQuery::fromSql($this, $this->rawExecutor(), $sql, $params);
        return $this->wrapQuery($pq);
    }

    /**
     * Wrap a PartialQuery in a Query and register the mapping
     */
    private function wrapQuery(PartialQuery $pq): Query
    {
        $query = new Query($pq, function (PartialQuery $derivedPq): Query {
            return $this->wrapQuery($derivedPq);
        });
        $this->queryMap[$query] = $pq;
        return $query;
    }

    /**
     * Get the underlying PartialQuery for a Query instance
     */
    private function unwrapQuery(Query $query): PartialQuery
    {
        if (!isset($this->queryMap[$query])) {
            throw new \InvalidArgumentException("Query was not created by this database");
        }
        return $this->queryMap[$query];
    }

    /**
     * Get AST from PartialQuery without cloning using reflection
     *
     * PartialQuery::getAST() always deep-clones to protect internal state,
     * but VirtualDatabase only reads the AST. Using reflection to access
     * the private $ast property avoids expensive cloning.
     */
    private static function peekAst(PartialQuery $query): ASTNode
    {
        if (self::$astProperty === null) {
            self::$astProperty = new \ReflectionProperty(PartialQuery::class, 'ast');
            self::$ensureAstMethod = new \ReflectionMethod(PartialQuery::class, 'ensureAST');
        }

        // Ensure AST is parsed using reflection to call private ensureAST()
        self::$ensureAstMethod->invoke($query);
        return self::$astProperty->getValue($query);
    }

    /**
     * Extract the WHERE clause from a model's scope query
     *
     * Lazily evaluates the scope: calls the model's method if no explicit
     * Query was provided. The scope Query must be backed by this VDB
     * (via provideDatabase()), so unwrapQuery() can access its PartialQuery.
     *
     * Returns a deep-cloned AST node safe for merging into another query.
     *
     * @param string $tableName Lowercase table name
     * @param string $scopeType 'readable', 'updatable', or 'deletable'
     * @return ASTNode|null The WHERE clause, or null if scope has no WHERE
     */
    private function getScopeWhere(string $tableName, string $scopeType): ?ASTNode
    {
        $config = $this->modelConfigs[$tableName] ?? null;
        if ($config === null) {
            return null;
        }

        // Get the scope Query — explicit override or lazy call to model method
        $scopeQuery = match ($scopeType) {
            'readable' => $config->readable ?? $config->modelClass::query(),
            'updatable' => $config->updatable ?? $config->modelClass::updatable(),
            'deletable' => $config->deletable ?? $config->modelClass::deletable(),
            default => throw new \InvalidArgumentException("Unknown scope type: $scopeType"),
        };

        // Unwrap to PartialQuery and peek at the AST
        $pq = $this->unwrapQuery($scopeQuery);
        $ast = self::peekAst($pq);

        // Extract WHERE from the SelectStatement
        if (!$ast instanceof SelectStatement) {
            return null;
        }

        $where = $ast->where;
        if ($where === null) {
            return null;
        }

        // Deep clone to avoid shared mutation when merging into another AST
        return $where->deepClone();
    }

    /**
     * Get a raw query executor closure for PartialQuery
     *
     * VirtualDatabase always needs AST to evaluate in-memory.
     * If AST is not provided, uses reflection to access it without cloning.
     */
    private function rawExecutor(): \Closure
    {
        return function (PartialQuery $query, ?ASTNode $ast): \Traversable {
            // If AST not provided (unmodified query), get it via reflection (no clone)
            if ($ast === null) {
                $ast = self::peekAst($query);
            }

            if ($ast instanceof WithStatement) {
                return $this->wrapWithTimeout($this->executeWithStatement($ast));
            }

            if ($ast instanceof UnionNode) {
                $table = $this->executeUnionAsTable($ast);
                return $this->wrapWithTimeout($table);
            }

            if (!$ast instanceof SelectStatement) {
                throw new \RuntimeException("query() only accepts SELECT statements");
            }

            return $this->wrapWithTimeout($this->executeSelect($ast));
        };
    }

    /**
     * Wrap an iterable with timeout checking
     *
     * When no timeout is configured, returns the result directly to avoid
     * generator overhead.
     */
    private function wrapWithTimeout(iterable $result): iterable
    {
        // Fast path: no timeout configured, skip the wrapper entirely
        if ($this->queryTimeout === null) {
            return $result;
        }

        return $this->timeoutGenerator($result);
    }

    /**
     * Generator that checks for timeout every 100 rows
     */
    private function timeoutGenerator(iterable $result): \Generator
    {
        $this->queryStartTime = microtime(true);
        $rowCount = 0;
        try {
            foreach ($result as $key => $row) {
                if (++$rowCount % 100 === 0) {
                    $this->checkTimeout();
                }
                yield $key => $row;
            }
        } finally {
            $this->queryStartTime = null;
        }
    }

    /**
     * Create raw executor closure with session context
     *
     * @internal Used by Session for query execution with temp table support
     */
    private function rawExecutorWithSession(Session $session): \Closure
    {
        // Uses yield from so finally runs after iteration completes, not when closure returns.
        // This ensures currentSession stays set during the entire query execution.
        return function (PartialQuery $query, ?ASTNode $ast) use ($session): \Traversable {
            $previousSession = $this->currentSession;
            $this->currentSession = $session;
            try {
                if ($ast === null) {
                    $ast = self::peekAst($query);
                }

                if ($ast instanceof WithStatement) {
                    yield from $this->wrapWithTimeout($this->executeWithStatement($ast));
                    return;
                }

                if ($ast instanceof UnionNode) {
                    $table = $this->executeUnionAsTable($ast);
                    yield from $this->wrapWithTimeout($table);
                    return;
                }

                if (!$ast instanceof SelectStatement) {
                    throw new \RuntimeException("query() only accepts SELECT statements");
                }

                yield from $this->wrapWithTimeout($this->executeSelect($ast));
            } finally {
                $this->currentSession = $previousSession;
            }
        };
    }

    /**
     * Execute INSERT with session context
     */
    private function executeInsertWithSession(InsertStatement $ast, Session $session): int
    {
        $previousSession = $this->currentSession;
        $this->currentSession = $session;
        try {
            return $this->executeInsert($ast);
        } finally {
            $this->currentSession = $previousSession;
        }
    }

    /**
     * Execute UPDATE with session context
     */
    private function executeUpdateWithSession(UpdateStatement $ast, Session $session): int
    {
        $previousSession = $this->currentSession;
        $this->currentSession = $session;
        try {
            return $this->executeUpdate($ast);
        } finally {
            $this->currentSession = $previousSession;
        }
    }

    /**
     * Execute DELETE with session context
     */
    private function executeDeleteWithSession(DeleteStatement $ast, Session $session): int
    {
        $previousSession = $this->currentSession;
        $this->currentSession = $session;
        try {
            return $this->executeDelete($ast);
        } finally {
            $this->currentSession = $previousSession;
        }
    }

    /**
     * Execute CREATE TABLE with session context
     *
     * TEMPORARY tables are stored in the session, not the main tables array.
     */
    private function executeCreateTableWithSession(CreateTableStatement $ast, Session $session): int
    {
        $tableName = $ast->table->getName();

        // Check existence (temp tables take precedence)
        $exists = $session->hasTempTable($tableName) || $this->tableExists($tableName);
        if ($exists) {
            if ($ast->ifNotExists) {
                return 0;
            }
            throw new \RuntimeException("Table already exists: $tableName");
        }

        // Convert AST column definitions to ColumnDef objects
        $columnDefs = [];
        foreach ($ast->columns as $col) {
            $columnDefs[] = $this->astColumnToColumnDef($col, $ast->constraints);
        }

        // Create the table
        $tableClass = $this->tableClass;
        $table = new $tableClass(...$columnDefs);

        // Store in session if TEMPORARY, otherwise in main tables
        if ($ast->temporary) {
            $session->setTempTable($tableName, $table);
        } else {
            $this->registerTable($tableName, $table);
        }

        return 0;
    }

    /**
     * Execute DROP TABLE with session context
     */
    private function executeDropTableWithSession(DropTableStatement $ast, Session $session): int
    {
        $tableName = $ast->table->getName();

        // Check temp tables first
        if ($session->hasTempTable($tableName)) {
            $session->dropTempTable($tableName);
            return 0;
        }

        // Fall back to permanent tables
        if (!$this->tableExists($tableName)) {
            if ($ast->ifExists) {
                return 0;
            }
            throw new \RuntimeException("Table not found: $tableName");
        }

        unset($this->tables[strtolower($tableName)]);
        return 0;
    }

    /**
     * Execute an INSERT, UPDATE, DELETE, or DDL statement
     *
     * @param string $sql SQL statement
     * @param array $params Bound parameters
     * @return int Number of affected rows (or last insert ID for INSERT)
     */
    public function exec(string $sql, array $params = []): int
    {
        $ast = SqlParser::parseWithParams($sql, $params);

        if ($ast instanceof InsertStatement) {
            return $this->executeInsert($ast);
        }

        if ($ast instanceof UpdateStatement) {
            return $this->executeUpdate($ast);
        }

        if ($ast instanceof DeleteStatement) {
            return $this->executeDelete($ast);
        }

        if ($ast instanceof CreateTableStatement) {
            return $this->executeCreateTable($ast);
        }

        if ($ast instanceof DropTableStatement) {
            return $this->executeDropTable($ast);
        }

        if ($ast instanceof CreateIndexStatement) {
            // Indexes are no-op in VirtualDatabase (InMemoryTable handles this internally)
            return 0;
        }

        if ($ast instanceof DropIndexStatement) {
            // Indexes are no-op in VirtualDatabase
            return 0;
        }

        throw new \RuntimeException("exec() only accepts INSERT, UPDATE, DELETE, or DDL statements");
    }

    /**
     * {@inheritdoc}
     */
    public function queryOne(string $sql, array $params = []): ?object
    {
        foreach ($this->query($sql, $params)->limit(1) as $row) {
            return $row;
        }
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function queryField(string $sql, array $params = []): mixed
    {
        $row = $this->queryOne($sql, $params);
        if ($row === null) {
            return null;
        }
        $vars = get_object_vars($row);
        return reset($vars);
    }

    /**
     * {@inheritdoc}
     */
    public function queryColumn(string $sql, array $params = []): array
    {
        $result = [];
        foreach ($this->query($sql, $params) as $row) {
            $vars = get_object_vars($row);
            $result[] = reset($vars);
        }
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function lastInsertId(): ?string
    {
        return $this->lastInsertId;
    }

    /**
     * {@inheritdoc}
     */
    public function tableExists(string $tableName): bool
    {
        return isset($this->tables[strtolower($tableName)]);
    }

    /**
     * {@inheritdoc}
     */
    public function transaction(\Closure $task): mixed
    {
        // VirtualDatabase doesn't support transactions - just execute the task
        // This allows code to work uniformly but without transaction guarantees
        return $task($this);
    }

    /**
     * {@inheritdoc}
     */
    public function getDialect(): SqlDialect
    {
        return SqlDialect::Virtual;
    }

    /**
     * {@inheritdoc}
     */
    public function quote(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    /**
     * {@inheritdoc}
     */
    public function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * {@inheritdoc}
     */
    public function delete(Query|PartialQuery $query): int
    {
        throw new \RuntimeException("delete() with Query not yet supported in VirtualDatabase");
    }

    /**
     * {@inheritdoc}
     */
    public function update(Query|PartialQuery $query, string|array $set, array $params = []): int
    {
        throw new \RuntimeException("update() with Query not yet supported in VirtualDatabase");
    }

    /**
     * {@inheritdoc}
     */
    public function insert(string $table, array $data): string
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($data), '?');

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $this->exec($sql, array_values($data));
        return $this->lastInsertId ?? '';
    }

    /**
     * {@inheritdoc}
     */
    public function upsert(string $table, array $data, string ...$conflictColumns): int
    {
        throw new \RuntimeException("upsert() not yet supported in VirtualDatabase");
    }

    /**
     * {@inheritdoc}
     */
    public function getSchema(): \mini\Table\Contracts\TableInterface
    {
        $tables = $this->tables;

        return new \mini\Table\GeneratorTable(
            function () use ($tables): \Generator {
                $rowKey = 0;

                foreach ($tables as $tblName => $table) {
                    $ordinal = 0;
                    foreach ($table->getColumns() as $colName => $colDef) {
                        $ordinal++;
                        yield $rowKey++ => (object)[
                            'table_name' => $tblName,
                            'name' => $colName,
                            'type' => 'column',
                            'data_type' => $colDef->type->name,
                            'is_nullable' => 1,  // VirtualDatabase tables don't track nullability
                            'default_value' => null,
                            'ordinal' => $ordinal,
                            'extra' => null,
                        ];

                        // Add index info if column has an index
                        if ($colDef->index !== \mini\Table\Types\IndexType::None) {
                            $indexType = match ($colDef->index) {
                                \mini\Table\Types\IndexType::Primary => 'primary',
                                \mini\Table\Types\IndexType::Unique => 'unique',
                                default => 'index',
                            };
                            yield $rowKey++ => (object)[
                                'table_name' => $tblName,
                                'name' => $colName . '_idx',
                                'type' => $indexType,
                                'data_type' => null,
                                'is_nullable' => null,
                                'default_value' => null,
                                'ordinal' => null,
                                'extra' => $colName,
                            ];
                        }
                    }
                }
            },
            new \mini\Table\ColumnDef('table_name', \mini\Table\Types\ColumnType::Text),
            new \mini\Table\ColumnDef('name', \mini\Table\Types\ColumnType::Text),
            new \mini\Table\ColumnDef('type', \mini\Table\Types\ColumnType::Text),
            new \mini\Table\ColumnDef('data_type', \mini\Table\Types\ColumnType::Text),
            new \mini\Table\ColumnDef('is_nullable', \mini\Table\Types\ColumnType::Int),
            new \mini\Table\ColumnDef('default_value', \mini\Table\Types\ColumnType::Text),
            new \mini\Table\ColumnDef('ordinal', \mini\Table\Types\ColumnType::Int),
            new \mini\Table\ColumnDef('extra', \mini\Table\Types\ColumnType::Text),
        );
    }

    /**
     * Execute a UNION query as TableInterface
     *
     * Uses ConcatTable for UNION ALL (concatenation without dedup),
     * and ConcatTable + distinct() for UNION (with dedup).
     */
    private function executeUnionAsTable(UnionNode $ast): TableInterface
    {
        $left = $this->executeUnionBranchAsTable($ast->left);
        $right = $this->executeUnionBranchAsTable($ast->right);

        $table = match ($ast->operator) {
            'UNION' => $ast->all
                ? new ConcatTable($left, $right)
                : (new ConcatTable($left, $right))->distinct(),
            // The ALL forms are multiset operations (min/max of multiplicities),
            // not membership tests, so they cannot reuse the semi-/anti-join
            // wrappers that back the DISTINCT forms.
            'INTERSECT' => $ast->all
                ? new MultisetSetOpTable($left, $right, true)
                : $this->intersectTables($left, $right)->distinct(),
            'EXCEPT' => $ast->all
                ? new MultisetSetOpTable($left, $right, false)
                : $this->exceptTables($left, $right)->distinct(),
            default => throw new \RuntimeException("Unknown set operator: {$ast->operator}"),
        };

        // Trailing ORDER BY / LIMIT / OFFSET apply to the whole set result,
        // not to the last branch (the parser lifts them onto the UnionNode).
        if ($ast->orderBy) {
            $table = $this->applyOrderBy($table, $ast->orderBy);
        }
        if ($ast->offset !== null) {
            $table = $table->offset((int) $this->evaluator->evaluate($ast->offset, null));
        }
        if ($ast->limit !== null) {
            $table = $table->limit((int) $this->evaluator->evaluate($ast->limit, null));
        }

        return $table;
    }

    /**
     * INTERSECT: rows that exist in both tables
     *
     * Uses SqlIntersectTable wrapper which maintains predicate pushdown.
     */
    private function intersectTables(TableInterface $left, TableInterface $right): TableInterface
    {
        return new SqlIntersectTable($left, $right);
    }

    /**
     * EXCEPT: rows from left that don't exist in right
     *
     * Uses SqlExceptTable wrapper which maintains predicate pushdown.
     */
    private function exceptTables(TableInterface $left, TableInterface $right): TableInterface
    {
        return new SqlExceptTable($left, $right);
    }

    /**
     * Execute a branch of a UNION as TableInterface
     */
    private function executeUnionBranchAsTable($ast): TableInterface
    {
        if ($ast instanceof UnionNode) {
            return $this->executeUnionAsTable($ast);
        }
        if ($ast instanceof SelectStatement) {
            // executeSelectAsTable() is a projection-only executor: it maps
            // SELECT columns onto source-table columns. It cannot evaluate
            // aggregates, GROUP BY/HAVING, expressions or column aliases, and
            // silently falls back to the unprojected source rows instead of
            // failing. Set operands routinely use those, so any branch it
            // cannot express goes through the full SELECT executor and is
            // materialised.
            if ($this->branchNeedsFullExecutor($ast)) {
                return $this->materializeSelect($ast);
            }
            return $this->executeSelectAsTable($ast);
        }
        if ($ast instanceof WithStatement) {
            // Reached from EXISTS (WITH … SELECT …): a <query expression> body.
            return $this->rowsToTable(iterator_to_array($this->executeWithStatement($ast)));
        }
        throw new \RuntimeException("Unexpected UNION branch type: " . get_class($ast));
    }

    /**
     * Can executeSelectAsTable() express this set-operation branch faithfully?
     */
    private function branchNeedsFullExecutor(SelectStatement $ast): bool
    {
        if ($ast->from === null) {
            return false; // SELECT without FROM → buildScalarTable(), which does evaluate
        }
        if ($ast->groupBy !== null || $ast->having !== null) {
            return true;
        }
        if ($this->hasAggregates($ast->columns) || $this->hasWindowFunctions($ast->columns)) {
            return true;
        }

        foreach ($ast->columns as $col) {
            if (!$col instanceof ColumnNode) {
                continue;
            }
            if ($col->expression instanceof IdentifierNode && $col->expression->isWildcard()) {
                continue;
            }
            // An alias renames the result column; projection-only cannot do that.
            if ($col->alias !== null) {
                return true;
            }
            // Any non-identifier expression must be evaluated.
            if (!$col->expression instanceof IdentifierNode) {
                return true;
            }
            // `SELECT u.id FROM users u` — executeSelectAsTable() only applies
            // withAlias() when the query has JOINs, so the qualified name would
            // not resolve.
            if ($ast->fromAlias !== null && $col->expression->isQualified() && empty($ast->joins)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run a SELECT through the full executor and freeze the result as a table.
     *
     * Used for set-operation branches that the projection-only executor cannot
     * express. Materialising loses laziness and predicate pushdown, which is
     * the price of a correct answer.
     */
    private function materializeSelect(SelectStatement $ast): TableInterface
    {
        $rows = [];
        foreach ($this->executeSelect($ast) as $row) {
            $rows[] = $row;
        }

        if ($rows !== []) {
            $names = array_keys((array) $rows[0]);
        } else {
            $names = [];
            foreach ($ast->columns as $col) {
                if (!$col instanceof ColumnNode) {
                    continue;
                }
                if ($col->expression instanceof IdentifierNode && $col->expression->isWildcard()) {
                    continue;
                }
                $names[] = $col->alias ?? $this->expressionToColumnName($col->expression);
            }
            if ($names === []) {
                throw new \RuntimeException(
                    'Cannot determine result columns of an empty set-operation branch; ' .
                    'name the selected columns explicitly instead of using *'
                );
            }
        }

        $defs = [];
        foreach ($names as $name) {
            $type = ColumnType::Text;
            foreach ($rows as $row) {
                $value = ((array) $row)[$name] ?? null;
                if ($value === null) {
                    continue;
                }
                $type = match (true) {
                    is_int($value) => ColumnType::Int,
                    is_float($value) => ColumnType::Float,
                    default => ColumnType::Text,
                };
                break;
            }
            $defs[] = new ColumnDef($name, $type);
        }

        return new \mini\Table\GeneratorTable(
            static function () use ($rows): \Generator {
                foreach ($rows as $i => $row) {
                    yield $i + 1 => $row;
                }
            },
            ...$defs
        );
    }

    /**
     * Execute a WITH statement (CTE)
     *
     * @param WithStatement $ast The WITH statement AST
     * @return ResultSetInterface The query result
     */
    private function executeWithStatement(WithStatement $ast): ResultSetInterface
    {
        // Track which CTEs we register so we can clean them up. A CTE may
        // shadow a registered table of the same name for the duration of the
        // statement (SQL:2003), so the shadowed table is saved and restored -
        // unsetting unconditionally would destroy it for the life of the
        // VirtualDatabase.
        $registeredCtes = [];
        $shadowedTables = [];

        try {
            // Process each CTE definition
            foreach ($ast->ctes as $cte) {
                $cteName = strtolower($cte['name']);

                if ($ast->recursive && $this->isCteRecursive($cte, $cteName)) {
                    // Recursive CTE - requires iterative execution. It applies
                    // the declared column list itself, before iterating: the
                    // names must be in scope for the recursive term's
                    // self-reference, not just on the finished table.
                    $table = $this->executeRecursiveCte($cte, $cteName);
                } else {
                    // Non-recursive CTE - simple execution
                    $table = $this->executeCteQuery($cte['query']);

                    // Apply column aliasing if specified
                    if ($cte['columns'] !== null) {
                        $table = $this->renameCteColumns($table, $cte['columns']);
                    }
                }

                // Register as a temporary table, preserving any table it shadows
                if (isset($this->tables[$cteName]) && !array_key_exists($cteName, $shadowedTables)) {
                    $shadowedTables[$cteName] = $this->tables[$cteName];
                }
                $this->tables[$cteName] = $table;
                $registeredCtes[] = $cteName;
            }

            // Execute the main query and materialize results
            // (must materialize before cleaning up CTE tables)
            // Use executeSelect to properly evaluate expressions
            if ($ast->query instanceof UnionNode) {
                $table = $this->executeUnionAsTable($ast->query);
            } else {
                $rows = iterator_to_array($this->executeSelect($ast->query));
                $table = $this->rowsToTable($rows);
            }

            return new ResultSet($table);
        } finally {
            // Remove CTE tables, restoring any registered table they shadowed
            foreach ($registeredCtes as $cteName) {
                if (array_key_exists($cteName, $shadowedTables)) {
                    $this->tables[$cteName] = $shadowedTables[$cteName];
                } else {
                    unset($this->tables[$cteName]);
                }
            }
        }
    }

    /**
     * Check if a CTE references itself (is recursive)
     */
    private function isCteRecursive(array $cte, string $cteName): bool
    {
        // A recursive CTE must be a UNION and reference itself
        if (!$cte['query'] instanceof UnionNode) {
            return false;
        }

        // Check if any table reference in the query matches the CTE name
        return $this->astReferencesTable($cte['query'], $cteName);
    }

    /**
     * Check if an AST node references a specific table name
     */
    private function astReferencesTable($ast, string $tableName): bool
    {
        if ($ast instanceof SelectStatement) {
            if ($ast->from instanceof IdentifierNode) {
                if (strtolower($ast->from->getFullName()) === $tableName) {
                    return true;
                }
            }
            foreach ($ast->joins as $join) {
                if ($join->table instanceof IdentifierNode) {
                    if (strtolower($join->table->getFullName()) === $tableName) {
                        return true;
                    }
                }
            }
        }

        if ($ast instanceof UnionNode) {
            return $this->astReferencesTable($ast->left, $tableName)
                || $this->astReferencesTable($ast->right, $tableName);
        }

        return false;
    }

    /**
     * Execute a recursive CTE
     *
     * Algorithm:
     * 1. Execute the anchor (non-recursive part of UNION)
     * 2. Create working table with anchor results
     * 3. Iterate: execute recursive part with current working table
     * 4. Append new rows to result, update working table
     * 5. Stop when no new rows are generated
     */
    private function executeRecursiveCte(array $cte, string $cteName): TableInterface
    {
        $union = $cte['query'];
        if (!$union instanceof UnionNode) {
            throw new \RuntimeException("Recursive CTE must use UNION");
        }

        // A CTE shadows a registered table of the same name only for the
        // duration of the statement - preserve it across the fixpoint loop.
        $shadowedTable = $this->tables[$cteName] ?? null;

        // Find the anchor (non-recursive) and recursive parts
        // For simplicity, assume left is anchor, right is recursive
        $anchorAst = $union->left;
        $recursiveAst = $union->right;

        // SQL:2003: a declared column list renames the CTE's output columns.
        // Push those names onto both branches as SELECT aliases *before*
        // executing them. Rows are name-keyed objects, so a positional list
        // like `SELECT 1, 0, 1` would otherwise collapse its two `1` columns
        // into one and the arity check below would reject a valid query.
        if ($cte['columns'] !== null) {
            $anchorAst = $this->aliasCteBranchColumns($anchorAst, $cte['columns']);
            $recursiveAst = $this->aliasCteBranchColumns($recursiveAst, $cte['columns']);
        }

        // A trailing ORDER BY / LIMIT / OFFSET on the recursive body binds to the
        // whole set operation (parseSelectOrUnion() lifts it there). LIMIT is
        // honoured below by stopping the fixpoint early; ORDER BY and OFFSET
        // cannot be - the rows are produced iteratively and there is no complete
        // result to sort or skip into until the loop has already run. Say so
        // rather than dropping the clause and returning a different answer.
        if ($union->orderBy !== null || $union->offset !== null) {
            throw new \RuntimeException(
                'Unsupported: ORDER BY / OFFSET on the body of a recursive CTE. ' .
                'Apply them to the query that selects from the CTE instead.'
            );
        }

        $recursionLimit = null;
        if ($union->limit !== null) {
            $recursionLimit = (int) $this->evaluator->evaluate($union->limit, null);
            if ($recursionLimit <= 0) {
                return $this->rowsToTable([]);
            }
        }

        // Execute anchor query (this is the base case)
        $anchorTable = $this->executeCteQuery($anchorAst);
        $anchorRows = array_values(iterator_to_array($anchorTable));

        if (empty($anchorRows)) {
            return $cte['columns'] !== null
                ? $this->renameCteColumns($anchorTable, $cte['columns'])
                : $anchorTable;
        }

        // SQL:2003: a declared column list names the CTE for ALL references,
        // including the recursive term's self-reference. Apply it to the anchor
        // before iterating - otherwise `WITH RECURSIVE c(n) AS (SELECT 1 UNION
        // ALL SELECT n+1 FROM c ...)` registers the working table under the
        // anchor's own column name ('1'), `n` never binds, the recursive term
        // matches nothing, and the fixpoint loop stops after the anchor row.
        if ($cte['columns'] !== null) {
            $anchorRows = $this->renameRowColumns($anchorRows, $cte['columns']);
        }

        // Column names defining the CTE's schema for this iteration
        $anchorColumnNames = array_keys(get_object_vars($anchorRows[0]));

        // Build result table
        $resultRows = $anchorRows;
        $workingRows = $anchorRows;

        // Rows already emitted, for UNION (as opposed to UNION ALL) dedup
        $seen = [];
        if (!$union->all) {
            foreach ($anchorRows as $row) {
                $seen[serialize(get_object_vars($row))] = true;
            }
        }

        if ($recursionLimit !== null && count($resultRows) >= $recursionLimit) {
            unset($this->tables[$cteName]);
            return $this->rowsToTable(array_slice($resultRows, 0, $recursionLimit));
        }

        // Iteration limit to prevent infinite loops
        $maxIterations = 10000;
        $iteration = 0;

        while (!empty($workingRows) && $iteration < $maxIterations) {
            $iteration++;

            // Register current working set as the CTE table
            $workingTable = $this->rowsToTable($workingRows);
            $this->tables[$cteName] = $workingTable;

            // Execute recursive part
            $recursiveTable = $this->executeCteQuery($recursiveAst);
            $newRows = array_values(iterator_to_array($recursiveTable));

            if (empty($newRows)) {
                break;
            }

            // Rename columns to match anchor's column names
            // This is required because the recursive SELECT may generate different column names
            $newRows = $this->renameRowColumns($newRows, $anchorColumnNames);

            // UNION (without ALL) removes duplicates, and the fixpoint is
            // reached once an iteration produces nothing that was not already
            // in the result - otherwise a cyclic recursive term never
            // terminates and silently returns $maxIterations rows.
            if (!$union->all) {
                $fresh = [];
                foreach ($newRows as $row) {
                    $key = serialize(get_object_vars($row));
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $fresh[] = $row;
                }
                $newRows = $fresh;

                if (empty($newRows)) {
                    break;
                }
            }

            $resultRows = array_merge($resultRows, $newRows);
            $workingRows = $newRows;

            // A LIMIT on the recursive body stops the fixpoint as soon as it is
            // satisfied - that is what makes `SELECT 1 UNION ALL SELECT n+1 FROM c
            // LIMIT 5` a terminating query despite having no WHERE guard.
            if ($recursionLimit !== null && count($resultRows) >= $recursionLimit) {
                $resultRows = array_slice($resultRows, 0, $recursionLimit);
                $workingRows = [];
                break;
            }
        }

        // Remove the working table, restoring any registered table it shadowed.
        // executeWithStatement() saves the shadowed binding only around its own
        // registration, which happens after this method returns.
        if ($shadowedTable !== null) {
            $this->tables[$cteName] = $shadowedTable;
        } else {
            unset($this->tables[$cteName]);
        }

        // Hitting the cap means the recursion had not reached its fixpoint. The
        // rows collected so far are an arbitrary prefix of an unbounded result,
        // so returning them would be a silently wrong answer.
        if (!empty($workingRows) && $iteration >= $maxIterations) {
            throw new \RuntimeException(
                "Recursive CTE '$cteName' did not terminate within $maxIterations iterations. " .
                'Add a terminating condition to the recursive term, or a LIMIT on it.'
            );
        }

        return $this->rowsToTable($resultRows);
    }

    /**
     * Apply a CTE's declared column list as SELECT aliases on one UNION branch.
     *
     * Returns a rewritten clone when the branch is a plain SELECT with a
     * matching, wildcard-free column count; otherwise the branch unchanged
     * (the caller's positional rename still applies).
     */
    private function aliasCteBranchColumns(mixed $branch, array $columnNames): mixed
    {
        if (!$branch instanceof SelectStatement || count($branch->columns) !== count($columnNames)) {
            return $branch;
        }

        foreach ($branch->columns as $col) {
            if (!$col instanceof ColumnNode) {
                return $branch;
            }
            if ($col->expression instanceof IdentifierNode && $col->expression->isWildcard()) {
                return $branch;
            }
        }

        $clone = $branch->deepClone();
        foreach (array_values($clone->columns) as $i => $col) {
            $col->alias = $columnNames[$i];
        }

        return $clone;
    }

    /**
     * Rename row columns to match expected column names
     */
    private function renameRowColumns(array $rows, array $columnNames): array
    {
        if (empty($rows)) {
            return $rows;
        }

        $result = [];
        foreach ($rows as $row) {
            $oldVars = get_object_vars($row);
            $oldKeys = array_keys($oldVars);

            if (count($oldKeys) !== count($columnNames)) {
                throw new \RuntimeException(
                    "Recursive CTE column count mismatch: got " . count($oldKeys) .
                    " columns, expected " . count($columnNames)
                );
            }

            $newRow = new \stdClass();
            foreach ($columnNames as $i => $newName) {
                $newRow->$newName = $oldVars[$oldKeys[$i]];
            }
            $result[] = $newRow;
        }

        return $result;
    }

    /**
     * Execute a CTE query and return as TableInterface
     *
     * Uses executeSelect (not executeSelectAsTable) to properly evaluate
     * expressions in the column list, then materializes results to a table.
     */
    private function executeCteQuery($query): TableInterface
    {
        if ($query instanceof UnionNode) {
            return $this->executeUnionAsTable($query);
        }
        if ($query instanceof SelectStatement) {
            // Use executeSelect to properly evaluate expressions,
            // then materialize to a table
            $rows = iterator_to_array($this->executeSelect($query));
            return $this->rowsToTable($rows);
        }
        if ($query instanceof WithStatement) {
            // Nested WITH: `WITH a AS (WITH b AS (...) SELECT * FROM b) ...`.
            // SQL:2003 allows a <with clause> on any <query expression>, so a
            // CTE body is itself a full query expression.
            return $this->rowsToTable(iterator_to_array($this->executeWithStatement($query)));
        }
        throw new \RuntimeException("Unexpected CTE query type: " . get_class($query));
    }

    /**
     * Convert stdClass rows to InMemoryTable
     */
    private function rowsToTable(array $rows): TableInterface
    {
        // Re-index to ensure sequential keys starting from 0
        $rows = array_values($rows);

        if (empty($rows)) {
            // Return truly empty table
            return new \mini\Table\InMemoryTable(
                new \mini\Table\ColumnDef('_empty', \mini\Table\Types\ColumnType::Int)
            );
        }

        // Build columns from first row
        $firstRow = $rows[0];
        $columns = [];
        foreach (get_object_vars($firstRow) as $colName => $value) {
            $type = match (true) {
                is_int($value) => \mini\Table\Types\ColumnType::Int,
                is_float($value) => \mini\Table\Types\ColumnType::Float,
                is_bool($value) => \mini\Table\Types\ColumnType::Int,
                default => \mini\Table\Types\ColumnType::Text,
            };
            $columns[] = new \mini\Table\ColumnDef($colName, $type);
        }

        // Create and populate table
        $table = new \mini\Table\InMemoryTable(...$columns);
        foreach ($rows as $row) {
            $table->insert((array)$row);
        }

        return $table;
    }

    /**
     * Rename CTE table columns according to specified column list
     */
    private function renameCteColumns(TableInterface $table, array $columnNames): TableInterface
    {
        // Get rows from table. Discard iterator keys - table iterators are not
        // guaranteed to yield 0-based integer keys, and this code indexes $rows[0].
        $rows = iterator_to_array($table, false);

        // SQL:2003 - a declared column list RENAMES the columns; it does not
        // retype them. Defaulting to Text makes InMemoryTable coerce ints and
        // floats to strings, and the strict comparisons behind JOIN and
        // NOT EXISTS then never match ("1" is not 1) - a silently empty join
        // and a silently unfiltered NOT EXISTS. Carry the source types over.
        $sourceTypes = [];
        foreach ($table->getColumns() as $sourceName => $sourceDef) {
            $sourceTypes[$sourceName] = $sourceDef->type;
        }

        if (empty($rows)) {
            // Create table with renamed columns
            $sourceNames = array_keys($sourceTypes);
            $columns = [];
            foreach (array_values($columnNames) as $i => $name) {
                $columns[] = new \mini\Table\ColumnDef(
                    $name,
                    $sourceTypes[$sourceNames[$i] ?? ''] ?? \mini\Table\Types\ColumnType::Text
                );
            }
            return new \mini\Table\InMemoryTable(...$columns);
        }

        // Map old column names to new ones
        $firstRow = $rows[0];
        $oldNames = array_keys(get_object_vars($firstRow));

        if (count($oldNames) !== count($columnNames)) {
            throw new \RuntimeException(
                "CTE column count mismatch: query returns " . count($oldNames) .
                " columns but " . count($columnNames) . " names specified"
            );
        }

        $nameMap = array_combine($oldNames, $columnNames);

        // Create new columns with renamed names, keeping each source column's type
        $columns = [];
        foreach (array_values($columnNames) as $i => $name) {
            $columns[] = new \mini\Table\ColumnDef(
                $name,
                $sourceTypes[$oldNames[$i]] ?? \mini\Table\Types\ColumnType::Text
            );
        }

        $newTable = new \mini\Table\InMemoryTable(...$columns);

        foreach ($rows as $row) {
            $newRow = [];
            foreach (get_object_vars($row) as $oldName => $value) {
                $newRow[$nameMap[$oldName]] = $value;
            }
            $newTable->insert($newRow);
        }

        return $newTable;
    }

    private function executeSelect(SelectStatement $ast): iterable
    {
        // Get the source table
        if ($ast->from === null) {
            // SELECT without FROM - use SingleRowTable
            yield from $this->executeScalarSelect($ast);
            return;
        }

        // Handle derived table (subquery in FROM)
        if ($ast->from instanceof SubqueryNode) {
            $table = $this->executeDerivedTable($ast->from, $ast->fromAlias);
            $tableName = $ast->fromAlias; // Use alias as table name
        } else {
            $tableName = $ast->from->getFullName();
            $table = $this->getTable($tableName);

            if ($table === null) {
                throw new \RuntimeException("Table not found: $tableName");
            }

            // Merge readable scope for model-registered tables.
            // Shallow-clone $ast to avoid mutating the shared SQL parse cache.
            $readableWhere = $this->getScopeWhere(strtolower($tableName), 'readable');
            if ($readableWhere !== null) {
                $ast = clone $ast;
                $ast->where = $ast->where !== null
                    ? new BinaryOperation($ast->where, 'AND', $readableWhere)
                    : $readableWhere;
            }
        }

        // Use predicate pushdown for multi-table queries with WHERE.
        // This pushes simple predicates (eq/lt/gt/like with constants) to tables
        // before joins. Outer joins are excluded: the pushdown planner rebuilds
        // joins from equi-join predicates with inner semantics, and pushing
        // WHERE into the null-extended side of an outer join is not valid.
        $hasMultipleTables = !empty($ast->joins);
        $hasWhere = $ast->where !== null;
        $hasOuterJoin = false;
        foreach ($ast->joins as $join) {
            if (in_array(strtoupper($join->joinType), ['LEFT', 'LEFT OUTER', 'RIGHT', 'RIGHT OUTER', 'FULL', 'FULL OUTER'], true)) {
                $hasOuterJoin = true;
                break;
            }
        }

        if ($hasMultipleTables && $hasWhere && !$hasOuterJoin) {
            // Limit tables to avoid exponential explosion
            $tableCount = 1 + count($ast->joins);
            if ($tableCount > 8) {
                throw new \RuntimeException(
                    "VDB limitation: joins limited to 8 tables (got $tableCount). " .
                    "Break into smaller queries or use subqueries."
                );
            }

            // Use optimized path: push predicates to tables first, then join
            $table = $this->executeSelectWithPredicatePushdown($ast);
        } else {
            // Standard path: process JOINs then WHERE
            if (!empty($ast->joins)) {
                $baseAlias = $ast->fromAlias ?? $tableName;
                $table = $table->withAlias($baseAlias);

                foreach ($ast->joins as $join) {
                    $table = $this->applyJoin($table, $join);
                }
            }

            // Apply WHERE - delegate to table backend
            if ($ast->where !== null) {
                $where = $this->optimizer->optimize($ast->where);
                $table = $this->applyWhereToTableInterface($table, $where);
            }
        }

        // Check for window functions - requires materializing rows first.
        // Window functions are evaluated AFTER GROUP BY/HAVING and BEFORE
        // DISTINCT/ORDER BY/LIMIT, so a query that also groups has to compute
        // the groups first and run the windows over the *grouped* rows -
        // otherwise the GROUP BY and HAVING would be silently ignored.
        if ($this->hasWindowFunctions($ast->columns)) {
            if (!empty($ast->groupBy) || $ast->having !== null || $this->hasAggregates($ast->columns)) {
                yield from $this->executeGroupedWindowSelect($ast, $table);
            } else {
                yield from $this->executeWindowSelect($ast, $table);
            }
            return;
        }

        // Check for aggregate functions - requires different execution path.
        // GROUP BY / HAVING also force the grouping path even when the SELECT
        // list has no aggregate at all (`SELECT role FROM users GROUP BY role`),
        // otherwise the grouping would be silently ignored.
        if ($this->hasAggregates($ast->columns)
            || !empty($ast->groupBy)
            || $ast->having !== null
        ) {
            yield from $this->executeAggregateSelect($ast, $table);
            return;
        }

        // Check if ORDER BY needs expression evaluation (aliases or expressions)
        $orderByNeedsEval = $ast->orderBy && $this->orderByNeedsExpressionEval($ast->orderBy, $ast->columns);

        if ($orderByNeedsEval) {
            // Expression-based ORDER BY: project first, then sort, then apply offset/limit
            yield from $this->executeSelectWithExpressionOrderBy($ast, $table);
            return;
        }

        // Apply ORDER BY - delegate to table backend (simple column names only)
        if ($ast->orderBy) {
            $table = $this->applyOrderBy($table, $ast->orderBy);
        }

        // Apply OFFSET - delegate to table backend
        if ($ast->offset !== null) {
            $offset = $this->evaluator->evaluate($ast->offset, null);
            $table = $table->offset((int)$offset);
        }

        // Apply LIMIT - delegate to table backend
        if ($ast->limit !== null) {
            $limit = $this->evaluator->evaluate($ast->limit, null);
            $table = $table->limit((int)$limit);
        }

        // Project columns (with optional DISTINCT)
        if ($ast->distinct) {
            $seen = new \mini\Table\Index\TreapIndex();
            foreach ($table as $row) {
                $projected = $this->projectRow($row, $ast->columns);
                $key = serialize($projected);
                if (!$seen->has($key)) {
                    $seen->insert($key, 0);
                    yield $projected;
                }
            }
        } else {
            foreach ($table as $row) {
                yield $this->projectRow($row, $ast->columns);
            }
        }
    }

    /**
     * Execute a SELECT with window functions
     *
     * Window functions are computed over the entire result set (or partitions),
     * producing a value for each row while maintaining the row granularity.
     */
    private function executeWindowSelect(SelectStatement $ast, iterable $source): iterable
    {
        // 1. Materialize all rows - window functions need the full dataset
        $rows = [];
        foreach ($source as $row) {
            $rows[] = $row;
        }

        // 2. Collect window function info from columns
        $windowFuncs = $this->collectWindowFunctions($ast->columns);

        // 3. Compute window function values for each row.
        // windowValues[rowIndex] is an identity-keyed WindowFunctionNode => value
        // map, so several window functions in one SELECT stay distinct.
        $windowValues = [];
        foreach ($rows as $idx => $row) {
            $windowValues[$idx] = new \SplObjectStorage();
        }

        foreach ($windowFuncs as $wfn) {
            $values = $this->computeWindowFunction($wfn, $rows);
            foreach ($values as $idx => $val) {
                $windowValues[$idx][$wfn] = $val;
            }
        }

        // 4. Apply ORDER BY if present (on the result rows, not the window ordering)
        if ($ast->orderBy) {
            // SELECT aliases are visible to ORDER BY, including aliases that
            // name a window function (`ORDER BY rn`).
            $aliases = [];
            foreach ($ast->columns as $col) {
                if ($col instanceof ColumnNode && $col->alias !== null) {
                    $aliases[$col->alias] = $col->expression;
                }
            }

            // Sort the rows maintaining their window values
            $indices = array_keys($rows);
            usort($indices, function ($a, $b) use ($rows, $windowValues, $ast, $aliases) {
                return $this->compareRowsForOrderBy(
                    $rows[$a],
                    $rows[$b],
                    $ast->orderBy,
                    $windowValues[$a],
                    $windowValues[$b],
                    $aliases
                );
            });
            $sortedRows = [];
            $sortedWindowValues = [];
            foreach ($indices as $idx) {
                $sortedRows[] = $rows[$idx];
                $sortedWindowValues[] = $windowValues[$idx];
            }
            $rows = $sortedRows;
            $windowValues = $sortedWindowValues;
        }

        // 5. Apply OFFSET/LIMIT
        $offset = 0;
        $limit = null;
        if ($ast->offset !== null) {
            $offset = (int)$this->evaluator->evaluate($ast->offset, null);
        }
        if ($ast->limit !== null) {
            $limit = (int)$this->evaluator->evaluate($ast->limit, null);
        }

        // 6. Project, then apply DISTINCT. SQL evaluates SELECT DISTINCT after
        // the window functions, so duplicates are removed from the *projected*
        // rows (window values included) - not from the input rows.
        $projected = [];
        $seen = [];
        foreach ($rows as $idx => $row) {
            $out = $this->projectRowWithWindowValues($row, $ast->columns, $windowValues[$idx]);
            if ($ast->distinct) {
                $key = serialize($out);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
            }
            $projected[] = $out;
        }

        // 7. Apply OFFSET/LIMIT last
        $count = 0;
        foreach ($projected as $idx => $out) {
            if ($idx < $offset) {
                continue;
            }
            if ($limit !== null && $count >= $limit) {
                break;
            }
            yield $out;
            $count++;
        }
    }

    /**
     * Execute a SELECT that has both window functions and grouping.
     *
     * SQL evaluates GROUP BY/HAVING before window functions, so the query is
     * split in two: an inner grouped SELECT that produces one row per group,
     * and an outer window pass over those rows. Aggregates referenced from
     * inside an OVER clause (`RANK() OVER (ORDER BY COUNT(*))`) are projected
     * by the inner query under a synthetic name and rewritten to reference it.
     */
    private function executeGroupedWindowSelect(SelectStatement $ast, TableInterface $table): iterable
    {
        $innerColumns = [];
        $outerColumns = [];
        $synthetic = 0;

        foreach ($ast->columns as $col) {
            if (!$col instanceof ColumnNode) {
                continue;
            }
            $outName = $col->alias;
            if ($outName === null && $col->expression instanceof IdentifierNode) {
                $outName = $col->expression->getName();
            }
            $outName ??= 'col_' . spl_object_id($col);

            if ($this->expressionHasWindowFunction($col->expression)) {
                $outerColumns[] = new ColumnNode(
                    $this->rewriteWindowAggregates($col->expression, $innerColumns, $synthetic),
                    $outName
                );
                continue;
            }

            if ($col->expression instanceof IdentifierNode && $col->expression->isWildcard()) {
                throw new \RuntimeException(
                    'VDB limitation: SELECT * cannot be combined with window functions and ' .
                    'GROUP BY - list the grouped columns explicitly.'
                );
            }

            // Non-window column: computed by the inner grouped query, then
            // referenced by name from the outer window pass.
            $innerColumns[] = new ColumnNode($col->expression, $outName);
            $outerColumns[] = new ColumnNode(new IdentifierNode($outName), $outName);
        }

        // ORDER BY may reference an aggregate too (`ORDER BY COUNT(*)`).
        $outerOrderBy = null;
        if ($ast->orderBy) {
            $outerOrderBy = [];
            foreach ($ast->orderBy as $item) {
                if (isset($item['column']) && $item['column'] instanceof ASTNode) {
                    $item['column'] = $this->aggregateToInnerColumn($item['column'], $innerColumns, $synthetic);
                }
                $outerOrderBy[] = $item;
            }
        }

        // Inner query: same FROM/WHERE (already applied to $table) plus the
        // grouping. Shallow clone so the shared parse cache is never mutated.
        $inner = clone $ast;
        $inner->columns = $innerColumns;
        $inner->distinct = false;
        $inner->orderBy = null;
        $inner->limit = null;
        $inner->offset = null;

        $groupedRows = iterator_to_array($this->executeAggregateSelect($inner, $table), false);

        $outer = new SelectStatement();
        $outer->columns = $outerColumns;
        $outer->distinct = $ast->distinct;
        $outer->orderBy = $outerOrderBy;
        $outer->limit = $ast->limit;
        $outer->offset = $ast->offset;

        yield from $this->executeWindowSelect($outer, $groupedRows);
    }

    /**
     * Deep-copy a SELECT column expression, replacing aggregates inside its
     * OVER clauses with references to synthetic inner-query columns.
     *
     * Returns the original expression untouched when no rewrite is needed, so
     * the cached AST is only ever copied when it actually has to change.
     */
    private function rewriteWindowAggregates(ASTNode $expr, array &$innerColumns, int &$synthetic): ASTNode
    {
        $needsRewrite = false;
        foreach ($this->extractWindowFunctions($expr) as $wfn) {
            foreach ($wfn->partitionBy as $part) {
                if ($this->expressionHasAggregate($part)) {
                    $needsRewrite = true;
                }
            }
            foreach ($wfn->orderBy as $spec) {
                if (isset($spec['expr']) && $this->expressionHasAggregate($spec['expr'])) {
                    $needsRewrite = true;
                }
            }
        }
        if (!$needsRewrite) {
            return $expr;
        }

        // Deep copy before mutating - the AST comes from the shared parse cache.
        $copy = unserialize(serialize($expr));
        foreach ($this->extractWindowFunctions($copy) as $wfn) {
            foreach ($wfn->partitionBy as $i => $part) {
                $wfn->partitionBy[$i] = $this->aggregateToInnerColumn($part, $innerColumns, $synthetic);
            }
            foreach ($wfn->orderBy as $i => $spec) {
                if (isset($spec['expr'])) {
                    $wfn->orderBy[$i]['expr'] =
                        $this->aggregateToInnerColumn($spec['expr'], $innerColumns, $synthetic);
                }
            }
        }
        return $copy;
    }

    /**
     * Move an aggregate expression into the inner grouped query and return an
     * identifier referencing it. Expressions without aggregates pass through.
     */
    private function aggregateToInnerColumn(ASTNode $expr, array &$innerColumns, int &$synthetic): ASTNode
    {
        if (!$this->expressionHasAggregate($expr)) {
            return $expr;
        }
        if (!($expr instanceof FunctionCallNode && isset($this->aggregates[strtoupper($expr->name)]))) {
            throw new \RuntimeException(
                'VDB limitation: an aggregate nested inside a larger expression is not supported ' .
                'in this position when the query also uses window functions.'
            );
        }
        $name = '__agg' . $synthetic++;
        $innerColumns[] = new ColumnNode($expr, $name);
        return new IdentifierNode($name);
    }

    /**
     * Collect window functions from SELECT columns
     *
     * @return array<int, WindowFunctionNode> Keyed by spl_object_id
     */
    private function collectWindowFunctions(array $columns): array
    {
        $result = [];
        foreach ($columns as $col) {
            if (!$col instanceof ColumnNode) {
                continue;
            }
            foreach ($this->extractWindowFunctions($col->expression) as $wfn) {
                $result[spl_object_id($wfn)] = $wfn;
            }
        }
        return $result;
    }

    /**
     * Extract all WindowFunctionNode instances from an expression
     */
    private function extractWindowFunctions(\mini\Parsing\SQL\AST\ASTNode $node): array
    {
        if ($node instanceof WindowFunctionNode) {
            return [$node];
        }
        if ($node instanceof SubqueryNode || $node instanceof SelectStatement) {
            return [];
        }

        $found = [];
        foreach (get_object_vars($node) as $value) {
            $found = array_merge($found, $this->extractWindowFunctionsFrom($value));
        }
        return $found;
    }

    /**
     * Walk an AST node property (node, array, or nested array) collecting
     * window function calls.
     */
    private function extractWindowFunctionsFrom(mixed $value): array
    {
        if ($value instanceof ASTNode) {
            return $this->extractWindowFunctions($value);
        }
        if (is_array($value)) {
            $found = [];
            foreach ($value as $item) {
                $found = array_merge($found, $this->extractWindowFunctionsFrom($item));
            }
            return $found;
        }
        return [];
    }

    /**
     * Compute window function values for all rows
     * Returns: [rowIndex => value, ...]
     */
    private function computeWindowFunction(WindowFunctionNode $wfn, array $rows): array
    {
        // Group rows by partition key
        $partitions = [];
        foreach ($rows as $idx => $row) {
            $key = $this->computePartitionKey($wfn->partitionBy, $row);
            if (!isset($partitions[$key])) {
                $partitions[$key] = [];
            }
            $partitions[$key][$idx] = $row;
        }

        $result = [];
        foreach ($partitions as $partitionRows) {
            // Sort partition by ORDER BY
            if (!empty($wfn->orderBy)) {
                $indices = array_keys($partitionRows);
                usort($indices, function ($a, $b) use ($partitionRows, $wfn) {
                    return $this->compareRowsForWindowOrderBy($partitionRows[$a], $partitionRows[$b], $wfn->orderBy);
                });
                $sortedPartition = [];
                foreach ($indices as $idx) {
                    $sortedPartition[$idx] = $partitionRows[$idx];
                }
                $partitionRows = $sortedPartition;
            }

            // Compute window function value for each row in partition
            $values = $this->evaluateWindowFunctionForPartition($wfn, $partitionRows);
            foreach ($values as $idx => $val) {
                $result[$idx] = $val;
            }
        }

        return $result;
    }

    /**
     * Compute partition key from PARTITION BY expressions
     */
    private function computePartitionKey(array $partitionBy, object $row): string
    {
        if (empty($partitionBy)) {
            return '__all__'; // All rows in same partition
        }
        $parts = [];
        foreach ($partitionBy as $expr) {
            $parts[] = serialize($this->evaluator->evaluate($expr, $row));
        }
        return implode('|', $parts);
    }

    /**
     * Compare two rows for window ORDER BY
     */
    private function compareRowsForWindowOrderBy(object $a, object $b, array $orderBy): int
    {
        foreach ($orderBy as $spec) {
            $valA = $this->evaluator->evaluate($spec['expr'], $a);
            $valB = $this->evaluator->evaluate($spec['expr'], $b);
            $cmp = $valA <=> $valB;
            if ($cmp !== 0) {
                return $spec['direction'] === 'DESC' ? -$cmp : $cmp;
            }
        }
        return 0;
    }

    /**
     * Evaluate window function for a sorted partition
     * Returns: [originalRowIndex => value, ...]
     */
    private function evaluateWindowFunctionForPartition(WindowFunctionNode $wfn, array $sortedRows): array
    {
        $funcName = strtoupper($wfn->function->name);
        $result = [];
        $rank = 0;
        $denseRank = 0;
        $prevValues = null;
        $rowNum = 0;

        foreach ($sortedRows as $idx => $row) {
            $rowNum++;

            // Get ORDER BY values for RANK/DENSE_RANK
            $currentValues = [];
            foreach ($wfn->orderBy as $spec) {
                $currentValues[] = $this->evaluator->evaluate($spec['expr'], $row);
            }

            switch ($funcName) {
                case 'ROW_NUMBER':
                    $result[$idx] = $rowNum;
                    break;

                case 'RANK':
                    if ($prevValues === null || $currentValues !== $prevValues) {
                        $rank = $rowNum; // Rank jumps to current row number on change
                    }
                    $result[$idx] = $rank;
                    $prevValues = $currentValues;
                    break;

                case 'DENSE_RANK':
                    if ($prevValues === null || $currentValues !== $prevValues) {
                        $denseRank++; // Dense rank increments by 1 on change
                    }
                    $result[$idx] = $denseRank;
                    $prevValues = $currentValues;
                    break;

                default:
                    throw new \RuntimeException("Unknown window function: $funcName");
            }
        }

        return $result;
    }

    /**
     * Project a row with window function values
     */
    private function projectRowWithWindowValues(object $row, array $columns, \SplObjectStorage $windowValues): object
    {
        $result = new \stdClass();

        foreach ($columns as $col) {
            if (!$col instanceof ColumnNode) {
                continue;
            }

            // Determine output column name (same logic as projectRow)
            $name = $col->alias;
            if ($name === null && $col->expression instanceof IdentifierNode) {
                $name = $col->expression->getName();
            }
            if ($name === null) {
                $name = 'col_' . spl_object_id($col);
            }

            $value = $this->evaluateExpressionWithWindowValues($col->expression, $row, $windowValues);
            $result->$name = $value;
        }

        return $result;
    }

    /**
     * Evaluate expression, substituting window function results
     */
    private function evaluateExpressionWithWindowValues(
        \mini\Parsing\SQL\AST\ASTNode $expr,
        object $row,
        \SplObjectStorage $windowValues
    ): mixed {
        // Window values are keyed by node identity, so each window function in
        // the SELECT list resolves to its own value even when several appear.
        $this->evaluator->pushValues($windowValues);
        try {
            return $this->evaluator->evaluate($expr, $row);
        } finally {
            $this->evaluator->popValues();
        }
    }

    /**
     * Compare two rows for ORDER BY (for result sorting)
     */
    private function compareRowsForOrderBy(
        object $a,
        object $b,
        array $orderBy,
        ?\SplObjectStorage $windowValuesA = null,
        ?\SplObjectStorage $windowValuesB = null,
        array $aliases = []
    ): int {
        foreach ($orderBy as $spec) {
            // SelectStatement::$orderBy items are ['column' => ASTNode, 'direction' => string]
            $expr = $spec['column'];
            $direction = strtoupper($spec['direction'] ?? 'ASC');

            // ORDER BY may name a SELECT alias rather than a source column
            if ($expr instanceof IdentifierNode && isset($aliases[$expr->getName()])) {
                $expr = $aliases[$expr->getName()];
            }

            $valA = $this->evaluateOrderKey($expr, $a, $windowValuesA);
            $valB = $this->evaluateOrderKey($expr, $b, $windowValuesB);

            $cmp = $valA <=> $valB;
            if ($cmp !== 0) {
                return $direction === 'DESC' ? -$cmp : $cmp;
            }
        }
        return 0;
    }

    /**
     * Evaluate a single ORDER BY key against a source row, with window function
     * values in scope when the query has any.
     */
    private function evaluateOrderKey(ASTNode $expr, object $row, ?\SplObjectStorage $windowValues): mixed
    {
        if ($windowValues === null) {
            return $this->evaluator->evaluate($expr, $row);
        }
        $this->evaluator->pushValues($windowValues);
        try {
            return $this->evaluator->evaluate($expr, $row);
        } finally {
            $this->evaluator->popValues();
        }
    }

    /**
     * Execute an aggregate SELECT (e.g., SELECT COUNT(*), SUM(price) FROM orders)
     *
     * Without GROUP BY: returns a single row with aggregate results.
     * With GROUP BY: returns one row per group with group columns + aggregate results.
     */
    private function executeAggregateSelect(SelectStatement $ast, TableInterface $table): iterable
    {
        // Collect aggregate function info. Aggregates are collected from the
        // SELECT list, HAVING and ORDER BY so that `HAVING SUM(stock) > 200`
        // or `ORDER BY COUNT(*)` work even when the aggregate is not projected.
        $aggregateInfos = $this->collectAggregateInfos($ast->columns, $ast->having, $ast->orderBy);

        // Collect non-aggregate columns (group key columns or expressions on them)
        $nonAggregateColumns = $this->collectNonAggregateColumns($ast->columns);

        if (empty($ast->groupBy)) {
            // No GROUP BY: single implicit group
            yield from $this->executeSimpleAggregate($ast, $table, $aggregateInfos, $nonAggregateColumns);
        } else {
            // GROUP BY: group rows by key and aggregate per group
            yield from $this->executeGroupByAggregate($ast, $table, $aggregateInfos, $nonAggregateColumns);
        }
    }

    /**
     * Execute aggregate without GROUP BY (single group containing all rows)
     */
    private function executeSimpleAggregate(
        SelectStatement $ast,
        TableInterface $table,
        array $aggregateInfos,
        array $nonAggregateColumns
    ): iterable {
        // Bare (non-aggregated) columns need a representative row. See
        // extremeAggregateInfo(): with a lone MIN()/MAX() that is the extreme
        // row, otherwise the first row of the input.
        $extreme = $this->extremeAggregateInfo($aggregateInfos);
        $repRow = null;
        $repValue = null;

        // Step phase: iterate through all rows
        foreach ($table as $row) {
            if ($repRow === null) {
                $repRow = $row;
            }
            if ($extreme !== null) {
                $value = $this->evaluator->evaluate($extreme['arg'], $row);
                if ($value !== null
                    && ($repValue === null
                        || ($extreme['isMax'] ? $value > $repValue : $value < $repValue))
                ) {
                    $repValue = $value;
                    $repRow = $row;
                }
            }
            $this->stepAggregates($aggregateInfos, $row);
        }

        // Final phase: build result row
        $values = $this->finalizeAggregates($aggregateInfos);
        $result = $this->buildAggregateResultRow($aggregateInfos, $nonAggregateColumns, $repRow, $values);

        // HAVING applies to the single implicit group as well
        if ($ast->having !== null && !$this->evaluateHaving($ast->having, $result, $repRow, $values)) {
            return;
        }

        // The implicit group produces exactly one row, but LIMIT/OFFSET still
        // apply to it: `LIMIT 0` and any positive OFFSET yield nothing.
        if ($ast->offset !== null && (int)$this->evaluator->evaluate($ast->offset, null) > 0) {
            return;
        }
        if ($ast->limit !== null && (int)$this->evaluator->evaluate($ast->limit, null) < 1) {
            return;
        }

        yield $result;
    }

    /**
     * Which row supplies the values for bare (non-aggregated) columns?
     *
     * SQL:2003 makes `SELECT MAX(price), name FROM products` illegal - `name`
     * is neither grouped nor aggregated. VDB follows the SQLite/MySQL
     * extension and returns a representative row instead of erroring, and
     * therefore has to follow SQLite's *rule* for picking it: when the query
     * contains exactly one aggregate and it is a single-argument MIN() or
     * MAX(), the bare columns come from the row that produced the extreme
     * value. With any other aggregate set the representative row is arbitrary
     * (VDB uses the first row of the group).
     *
     * @param array $aggregateInfos As built by collectAggregateInfos()
     * @return array{arg: ASTNode, isMax: bool}|null
     */
    private function extremeAggregateInfo(array $aggregateInfos): ?array
    {
        if (count($aggregateInfos) !== 1) {
            return null;
        }
        $info = $aggregateInfos[array_key_first($aggregateInfos)];
        $name = strtoupper($info['node']->name);
        if ($name !== 'MIN' && $name !== 'MAX') {
            return null;
        }
        if (count($info['args']) !== 1) {
            return null;
        }
        $arg = $info['args'][0];
        if ($arg instanceof IdentifierNode && $arg->isWildcard()) {
            return null;
        }
        return ['arg' => $arg, 'isMax' => $name === 'MAX'];
    }

    /**
     * Execute aggregate with GROUP BY
     */
    private function executeGroupByAggregate(
        SelectStatement $ast,
        TableInterface $table,
        array $aggregateInfos,
        array $nonAggregateColumns
    ): iterable {
        $groupBy = $this->resolveGroupByOrdinals($ast->groupBy, $ast->columns, $table);
        // Groups: key => ['aggregates' => [...], 'sampleRow' => object]
        $groups = [];

        // Bare (non-aggregated) columns take their values from the group's
        // representative row - the MIN()/MAX() row when the query has exactly
        // one such aggregate, otherwise the group's first row.
        // See extremeAggregateInfo().
        $extreme = $this->extremeAggregateInfo($aggregateInfos);

        // Step phase: iterate through all rows, grouping by key
        foreach ($table as $row) {
            // Compute group key from GROUP BY expressions
            $keyParts = [];
            foreach ($groupBy as $groupExpr) {
                $keyParts[] = $this->evaluator->evaluate($groupExpr, $row);
            }
            $groupKey = serialize($keyParts);

            // Initialize group if new
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'keyValues' => $keyParts,
                    'sampleRow' => $row,
                    'extremeValue' => null,
                    'aggregates' => $this->cloneAggregateInfos($aggregateInfos),
                ];
            }

            // Track the group's MIN()/MAX() row as its representative row
            if ($extreme !== null) {
                $value = $this->evaluator->evaluate($extreme['arg'], $row);
                if ($value !== null
                    && ($groups[$groupKey]['extremeValue'] === null
                        || ($extreme['isMax']
                            ? $value > $groups[$groupKey]['extremeValue']
                            : $value < $groups[$groupKey]['extremeValue']))
                ) {
                    $groups[$groupKey]['extremeValue'] = $value;
                    $groups[$groupKey]['sampleRow'] = $row;
                }
            }

            // Step aggregates for this group
            $this->stepAggregates($groups[$groupKey]['aggregates'], $row);
        }

        // Final phase: build result rows
        $results = [];
        $resultValues = [];
        foreach ($groups as $group) {
            $values = $this->finalizeAggregates($group['aggregates']);
            $result = $this->buildAggregateResultRow(
                $group['aggregates'],
                $nonAggregateColumns,
                $group['sampleRow'],
                $values
            );

            // Apply HAVING filter
            if ($ast->having !== null
                && !$this->evaluateHaving($ast->having, $result, $group['sampleRow'], $values)
            ) {
                continue;
            }

            $results[] = $result;
            $resultValues[] = $values;
        }

        // SELECT DISTINCT applies after HAVING and before ORDER BY
        if ($ast->distinct) {
            $seen = [];
            $unique = [];
            $uniqueValues = [];
            foreach ($results as $i => $row) {
                $key = serialize($row);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $unique[] = $row;
                $uniqueValues[] = $resultValues[$i];
            }
            $results = $unique;
            $resultValues = $uniqueValues;
        }

        // Apply ORDER BY if present
        if ($ast->orderBy) {
            $results = $this->sortAggregateResults($results, $resultValues, $ast->orderBy);
        }

        // Apply OFFSET
        $offset = 0;
        if ($ast->offset !== null) {
            $offset = (int)$this->evaluator->evaluate($ast->offset, null);
        }

        // Apply LIMIT
        $limit = null;
        if ($ast->limit !== null) {
            $limit = (int)$this->evaluator->evaluate($ast->limit, null);
        }

        // Yield results with offset/limit
        $count = 0;
        foreach ($results as $i => $result) {
            if ($i < $offset) {
                continue;
            }
            if ($limit !== null && $count >= $limit) {
                break;
            }
            yield $result;
            $count++;
        }
    }

    /**
     * Clone aggregate infos with fresh contexts for a new group
     */
    private function cloneAggregateInfos(array $aggregateInfos): array
    {
        $cloned = [];
        foreach ($aggregateInfos as $info) {
            $cloned[] = [
                'name' => $info['name'],
                'position' => $info['position'],
                'node' => $info['node'],
                'step' => $info['step'],
                'final' => $info['final'],
                'args' => $info['args'],
                'context' => null, // Fresh context for this group
                'distinct' => $info['distinct'] ?? false,
                'seenValues' => [], // Fresh seen values for this group
            ];
        }
        return $cloned;
    }

    /**
     * Step all aggregates with values from a row
     */
    private function stepAggregates(array &$aggregateInfos, object $row): void
    {
        for ($i = 0; $i < count($aggregateInfos); $i++) {
            $args = [];
            foreach ($aggregateInfos[$i]['args'] as $argNode) {
                // Handle COUNT(*) - wildcard means "count rows", not evaluate a column
                if ($argNode instanceof IdentifierNode && $argNode->isWildcard()) {
                    $args[] = 1; // Pass dummy value for COUNT(*)
                } else {
                    $args[] = $this->evaluator->evaluate($argNode, $row);
                }
            }

            // Handle DISTINCT: skip if we've seen this value before
            if ($aggregateInfos[$i]['distinct'] && !empty($args)) {
                // Use serialized args as key to track uniqueness
                $key = serialize($args);
                if (isset($aggregateInfos[$i]['seenValues'][$key])) {
                    continue; // Skip duplicate value
                }
                $aggregateInfos[$i]['seenValues'][$key] = true;
            }

            $step = $aggregateInfos[$i]['step'];
            $step($aggregateInfos[$i]['context'], ...$args);
        }
    }

    /**
     * Resolve `GROUP BY <ordinal>` to the corresponding SELECT list expression.
     *
     * `GROUP BY 1` means "group by the first select column", not "group by the
     * constant 1" - evaluating it as a constant silently collapses every row
     * into a single group.
     *
     * @param ASTNode[] $groupBy
     * @param ColumnNode[] $columns
     * @return ASTNode[]
     */
    private function resolveGroupByOrdinals(array $groupBy, array $columns, ?TableInterface $table = null): array
    {
        $selectExprs = [];
        $aliases = [];
        foreach ($columns as $col) {
            if ($col instanceof ColumnNode) {
                $selectExprs[] = $col->expression;
                if ($col->alias !== null) {
                    $aliases[$col->alias] = $col->expression;
                }
            }
        }

        // A real column always wins over a SELECT alias of the same name.
        $tableColumns = $table !== null ? $table->getColumns() : [];

        $resolved = [];
        foreach ($groupBy as $expr) {
            if ($expr instanceof LiteralNode
                && $expr->valueType === 'number'
                && (float)(int)$expr->value === (float)$expr->value
            ) {
                $idx = (int)$expr->value - 1;
                if (!isset($selectExprs[$idx])) {
                    throw new \RuntimeException(
                        "GROUP BY position " . (int)$expr->value . " is not in the select list"
                    );
                }
                $resolved[] = $selectExprs[$idx];
                continue;
            }

            // GROUP BY <select alias> - accepted by SQLite/MySQL/PostgreSQL.
            // Only an unqualified name that is not an actual column resolves,
            // so `GROUP BY category` never picks up `... AS category`.
            if ($expr instanceof IdentifierNode
                && !$expr->isQualified()
                && !$expr->isWildcard()
                && isset($aliases[$expr->getName()])
                && !isset($tableColumns[$expr->getName()])
            ) {
                $resolved[] = $aliases[$expr->getName()];
                continue;
            }

            $resolved[] = $expr;
        }

        return $resolved;
    }

    /**
     * Finalize every aggregate and return an identity-keyed node => value map.
     *
     * @return \SplObjectStorage<ASTNode, mixed>
     */
    private function finalizeAggregates(array $aggregateInfos): \SplObjectStorage
    {
        $values = new \SplObjectStorage();
        foreach ($aggregateInfos as $info) {
            $final = $info['final'];
            $values[$info['node']] = $final($info['context']);
        }
        return $values;
    }

    /**
     * Evaluate HAVING for one group.
     *
     * The condition sees the projected result row, the group's representative
     * source row (so `HAVING category = 'tools'` works when `category` is not
     * projected) and the pre-computed aggregate values (so `HAVING COUNT(*) > 1`
     * works without the aggregate being projected).
     *
     * @param \SplObjectStorage<ASTNode, mixed> $values
     */
    private function evaluateHaving(
        ASTNode $having,
        \stdClass $result,
        ?object $sampleRow,
        \SplObjectStorage $values
    ): bool {
        $row = new \stdClass();
        if ($sampleRow !== null) {
            foreach (get_object_vars($sampleRow) as $k => $v) {
                $row->$k = $v;
            }
        }
        foreach (get_object_vars($result) as $k => $v) {
            $row->$k = $v;
        }

        $this->evaluator->pushValues($values);
        try {
            return (bool)$this->evaluator->evaluate($having, $row);
        } finally {
            $this->evaluator->popValues();
        }
    }

    /**
     * Sort aggregate result rows, keeping each row paired with its aggregate
     * values so that `ORDER BY COUNT(*)` can be evaluated per row.
     *
     * @param \stdClass[] $results
     * @param \SplObjectStorage[] $resultValues
     * @return \stdClass[]
     */
    private function sortAggregateResults(array $results, array $resultValues, array $orderBy): array
    {
        if (count($results) < 2) {
            return $results;
        }

        $needsValues = false;
        foreach ($orderBy as $item) {
            if ($this->expressionHasAggregate($item['column'])) {
                $needsValues = true;
                break;
            }
        }

        if (!$needsValues) {
            return $this->sortResults($results, $orderBy);
        }

        $indices = array_keys($results);
        usort($indices, function ($a, $b) use ($results, $resultValues, $orderBy) {
            foreach ($orderBy as $item) {
                $expr = $item['column'];
                $direction = strtoupper($item['direction'] ?? 'ASC');

                $this->evaluator->pushValues($resultValues[$a]);
                try {
                    $aVal = $this->evaluator->evaluate($expr, $results[$a]);
                } finally {
                    $this->evaluator->popValues();
                }
                $this->evaluator->pushValues($resultValues[$b]);
                try {
                    $bVal = $this->evaluator->evaluate($expr, $results[$b]);
                } finally {
                    $this->evaluator->popValues();
                }

                if ($aVal === $bVal) {
                    continue;
                }
                $cmp = $aVal <=> $bVal;
                return $direction === 'DESC' ? -$cmp : $cmp;
            }
            return 0;
        });

        $sorted = [];
        foreach ($indices as $i) {
            $sorted[] = $results[$i];
        }
        return $sorted;
    }

    /**
     * Build result row from finalized aggregates and non-aggregate columns
     *
     * @param \SplObjectStorage<ASTNode, mixed>|null $values Pre-finalized aggregate values
     */
    private function buildAggregateResultRow(
        array $aggregateInfos,
        array $nonAggregateColumns,
        ?object $sampleRow,
        ?\SplObjectStorage $values = null
    ): \stdClass {
        $values ??= $this->finalizeAggregates($aggregateInfos);
        $result = new \stdClass();

        // Add non-aggregate columns (evaluated from sample row). The aggregate
        // values are in scope so that expressions such as `SUM(price) * 2` or
        // `ROUND(AVG(price), 2)` evaluate correctly.
        $projected = [];

        $this->evaluator->pushValues($values);
        try {
            foreach ($nonAggregateColumns as $colInfo) {
                if (isset($colInfo['wildcard'])) {
                    if ($sampleRow !== null) {
                        foreach ($this->expandWildcard($colInfo['wildcard'], $sampleRow) as $k => $v) {
                            $projected[] = [$colInfo['position'], $k, $v];
                        }
                    }
                    continue;
                }
                if ($sampleRow !== null) {
                    $value = $this->evaluator->evaluate($colInfo['expression'], $sampleRow);
                } elseif ($this->expressionHasAggregate($colInfo['expression'])) {
                    // Empty input set: aggregate-only expressions still have a value
                    $value = $this->evaluator->evaluate($colInfo['expression'], new \stdClass());
                } else {
                    $value = null;
                }
                $projected[] = [$colInfo['position'] ?? count($projected), $colInfo['name'], $value];
            }
        } finally {
            $this->evaluator->popValues();
        }

        // Add aggregate results that are projected as whole columns
        foreach ($aggregateInfos as $info) {
            if ($info['name'] === null) {
                continue; // nested inside an expression - already projected above
            }
            $projected[] = [$info['position'] ?? count($projected), $info['name'], $values[$info['node']]];
        }

        // Emit in SELECT list order
        usort($projected, fn($a, $b) => $a[0] <=> $b[0]);
        foreach ($projected as [, $name, $value]) {
            $result->$name = $value;
        }

        return $result;
    }

    /**
     * Expand a `*` or `alias.*` select item against a representative row.
     *
     * @return array<string, mixed>
     */
    private function expandWildcard(IdentifierNode $wildcard, object $row): array
    {
        $vars = get_object_vars($row);

        if (!$wildcard->isQualified()) {
            return $vars;
        }

        $prefix = $wildcard->parts[count($wildcard->parts) - 2] . '.';
        $filtered = [];
        foreach ($vars as $name => $value) {
            if (str_starts_with($name, $prefix)) {
                $filtered[$name] = $value;
            }
        }

        return $filtered !== [] ? $filtered : $vars;
    }

    /**
     * Collect non-aggregate columns from SELECT
     *
     * These are columns that reference GROUP BY expressions or constants.
     */
    private function collectNonAggregateColumns(array $columns): array
    {
        $nonAggregates = [];

        foreach ($columns as $position => $col) {
            if (!$col instanceof ColumnNode) {
                continue;
            }

            // Skip aggregate functions
            if ($col->expression instanceof FunctionCallNode) {
                $funcName = strtoupper($col->expression->name);
                if (isset($this->aggregates[$funcName])) {
                    continue;
                }
            }

            // Wildcards expand from the group's representative row. SQL:2003
            // forbids a bare `*` alongside GROUP BY, but dropping it silently
            // would produce a result with no columns at all.
            if ($col->expression instanceof IdentifierNode && $col->expression->isWildcard()) {
                $nonAggregates[] = [
                    'name' => null,
                    'expression' => $col->expression,
                    'position' => $position,
                    'wildcard' => $col->expression,
                ];
                continue;
            }

            // Determine output column name
            $name = $col->alias;
            if ($name === null && $col->expression instanceof IdentifierNode) {
                $name = $col->expression->getName();
            }
            if ($name === null) {
                $name = 'col_' . spl_object_id($col);
            }

            $nonAggregates[] = [
                'name' => $name,
                'expression' => $col->expression,
                'position' => $position,
            ];
        }

        return $nonAggregates;
    }

    /**
     * Sort result rows by ORDER BY specification
     */
    private function sortResults(array $results, array $orderBy): array
    {
        if (empty($results)) {
            return $results;
        }

        // Get column names from first result for numeric index lookups
        $columnNames = array_keys(get_object_vars($results[0]));

        usort($results, function ($a, $b) use ($orderBy, $columnNames) {
            foreach ($orderBy as $item) {
                $colExpr = $item['column'];
                $direction = strtoupper($item['direction'] ?? 'ASC');

                // Get column name - could be identifier, numeric index, or expression
                if ($colExpr instanceof IdentifierNode) {
                    $colName = $colExpr->getName();
                    $aVal = $a->$colName ?? null;
                    $bVal = $b->$colName ?? null;
                } elseif ($colExpr instanceof LiteralNode && is_numeric($colExpr->value)) {
                    // ORDER BY 1, ORDER BY 2, etc. - 1-based column index
                    $idx = (int)$colExpr->value - 1;
                    $colName = $columnNames[$idx] ?? null;
                    if ($colName === null) {
                        continue; // Invalid index, skip
                    }
                    $aVal = $a->$colName ?? null;
                    $bVal = $b->$colName ?? null;
                } else {
                    // Evaluate expression against result row
                    $aVal = $this->evaluator->evaluate($colExpr, $a);
                    $bVal = $this->evaluator->evaluate($colExpr, $b);
                }

                if ($aVal === $bVal) {
                    continue;
                }

                $cmp = $aVal <=> $bVal;
                if ($direction === 'DESC') {
                    $cmp = -$cmp;
                }

                return $cmp;
            }
            return 0;
        });

        return $results;
    }

    /**
     * Sort results that include both projected and original row data
     *
     * Used for ORDER BY expressions that reference columns not in the SELECT list.
     * Each result item is ['projected' => object, 'original' => object].
     *
     * @param array $results Array of ['projected' => ..., 'original' => ...] pairs
     * @param array $orderBy ORDER BY items from AST
     * @param array $aliasToExpr Map of SELECT aliases to their expressions
     */
    private function sortResultsWithOriginal(array $results, array $orderBy, array $aliasToExpr): array
    {
        if (empty($results)) {
            return $results;
        }

        // Get column names from first projected result for numeric index lookups
        $columnNames = array_keys(get_object_vars($results[0]['projected']));

        usort($results, function ($a, $b) use ($orderBy, $aliasToExpr, $columnNames) {
            foreach ($orderBy as $item) {
                $colExpr = $item['column'];
                $direction = strtoupper($item['direction'] ?? 'ASC');

                // Determine which row to evaluate against
                if ($colExpr instanceof IdentifierNode) {
                    $name = $colExpr->getName();
                    if (isset($aliasToExpr[$name])) {
                        // It's a SELECT alias - use projected row
                        $aVal = $a['projected']->$name ?? null;
                        $bVal = $b['projected']->$name ?? null;
                    } else {
                        // Table column - use original row
                        $aVal = $a['original']->$name ?? null;
                        $bVal = $b['original']->$name ?? null;
                    }
                } elseif ($colExpr instanceof LiteralNode && is_numeric($colExpr->value)) {
                    // ORDER BY 1, ORDER BY 2, etc. - 1-based column index
                    $idx = (int)$colExpr->value - 1;
                    $colName = $columnNames[$idx] ?? null;
                    if ($colName === null) {
                        continue;
                    }
                    $aVal = $a['projected']->$colName ?? null;
                    $bVal = $b['projected']->$colName ?? null;
                } else {
                    // Expression - evaluate against original row
                    $aVal = $this->evaluator->evaluate($colExpr, $a['original']);
                    $bVal = $this->evaluator->evaluate($colExpr, $b['original']);
                }

                if ($aVal === $bVal) {
                    continue;
                }

                $cmp = $aVal <=> $bVal;
                if ($direction === 'DESC') {
                    $cmp = -$cmp;
                }

                return $cmp;
            }
            return 0;
        });

        return $results;
    }

    /**
     * Collect aggregate function info from SELECT columns
     *
     * Returns array of aggregate info with keys:
     * - name: output column name
     * - step: step callback
     * - final: final callback
     * - args: argument AST nodes
     * - context: initial null context (to be mutated)
     */
    private function collectAggregateInfos(array $columns, ?ASTNode $having = null, ?array $orderBy = null): array
    {
        // Aggregates that are a whole SELECT column get an output column name;
        // everything else (nested in an expression, or only in HAVING/ORDER BY)
        // is computed but not projected.
        $named = [];
        $nodes = [];

        foreach ($columns as $position => $col) {
            if (!$col instanceof ColumnNode) {
                continue;
            }

            if ($col->expression instanceof FunctionCallNode
                && isset($this->aggregates[strtoupper($col->expression->name)])
            ) {
                $funcNode = $col->expression;
                $named[spl_object_id($funcNode)] = [
                    'name' => $col->alias ?? $this->defaultAggregateName($funcNode),
                    'position' => $position,
                ];
            }

            $this->collectAggregateNodes($col->expression, $nodes);
        }

        if ($having !== null) {
            $this->collectAggregateNodes($having, $nodes);
        }

        foreach ($orderBy ?? [] as $item) {
            if (isset($item['column']) && $item['column'] instanceof ASTNode) {
                $this->collectAggregateNodes($item['column'], $nodes);
            }
        }

        $infos = [];
        foreach ($nodes as $id => $funcNode) {
            $aggregate = $this->aggregates[strtoupper($funcNode->name)];
            $infos[] = [
                'name' => $named[$id]['name'] ?? null,
                'position' => $named[$id]['position'] ?? null,
                'node' => $funcNode,
                'step' => $aggregate['step'],
                'final' => $aggregate['final'],
                'args' => $funcNode->arguments,
                'context' => null,
                'distinct' => $funcNode->distinct,
                'seenValues' => [], // Track seen values for DISTINCT
            ];
        }

        return $infos;
    }

    /**
     * Default output column name for an unaliased aggregate, e.g. "COUNT(*)".
     */
    private function defaultAggregateName(FunctionCallNode $funcNode): string
    {
        $argNames = [];
        foreach ($funcNode->arguments as $arg) {
            if ($arg instanceof IdentifierNode) {
                $argNames[] = $arg->isWildcard() ? '*' : $arg->getName();
            } else {
                $argNames[] = '?';
            }
        }
        return $funcNode->name . '(' . implode(', ', $argNames) . ')';
    }

    /**
     * Recursively collect aggregate function calls from an expression.
     *
     * Does not descend into subqueries - aggregates there belong to the
     * subquery - nor into window functions, which the window path handles.
     *
     * @param array<int, FunctionCallNode> $out Keyed by spl_object_id
     */
    private function collectAggregateNodes(ASTNode $node, array &$out): void
    {
        if ($node instanceof SubqueryNode
            || $node instanceof SelectStatement
            || $node instanceof ExistsOperation
            || $node instanceof WindowFunctionNode
        ) {
            return;
        }

        if ($node instanceof FunctionCallNode && isset($this->aggregates[strtoupper($node->name)])) {
            $out[spl_object_id($node)] = $node;
            return; // SQL forbids nesting an aggregate inside an aggregate
        }

        foreach (get_object_vars($node) as $value) {
            $this->collectAggregateNodesFrom($value, $out);
        }
    }

    /**
     * Walk an AST node property (node, array, or nested array such as
     * CaseWhenNode::$whenClauses) collecting aggregate calls.
     *
     * @param array<int, FunctionCallNode> $out
     */
    private function collectAggregateNodesFrom(mixed $value, array &$out): void
    {
        if ($value instanceof ASTNode) {
            $this->collectAggregateNodes($value, $out);
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                $this->collectAggregateNodesFrom($item, $out);
            }
        }
    }

    /**
     * Execute a SELECT and return as TableInterface (for subqueries)
     *
     * Used for IN subqueries where we need to pass the result as a SetInterface
     * to the table backend, preserving the ability to optimize.
     */
    private function executeSelectAsTable(SelectStatement $ast): TableInterface
    {
        if ($ast->from === null) {
            // SELECT without FROM - build SingleRowTable from expressions
            return $this->buildScalarTable($ast);
        }

        // GROUP BY / HAVING cannot be expressed as a TableInterface pipeline -
        // this method would drop them and return the ungrouped rows. Materialise
        // the grouped result instead (affects IN- and EXISTS-subqueries).
        // A derived table in FROM is materialised for the same reason: the
        // pipeline below expects a named table it can look up.
        if (!empty($ast->groupBy) || $ast->having !== null || $ast->from instanceof SubqueryNode) {
            return $this->rowsToTable(iterator_to_array($this->executeSelect($ast), false));
        }

        $tableName = $ast->from->getFullName();
        $table = $this->getTable($tableName);

        if ($table === null) {
            throw new \RuntimeException("Table not found: $tableName");
        }

        // Merge readable scope for model-registered tables.
        // Shallow-clone $ast to avoid mutating the shared SQL parse cache.
        $readableWhere = $this->getScopeWhere(strtolower($tableName), 'readable');
        if ($readableWhere !== null) {
            $ast = clone $ast;
            $ast->where = $ast->where !== null
                ? new BinaryOperation($ast->where, 'AND', $readableWhere)
                : $readableWhere;
        }

        // Use predicate pushdown for multi-table queries with WHERE.
        // Outer joins are excluded: the pushdown planner rebuilds joins from
        // equi-join predicates with inner semantics, and pushing WHERE into
        // the null-extended side of an outer join is not valid.
        $hasMultipleTables = !empty($ast->joins);
        $hasWhere = $ast->where !== null;
        $hasOuterJoin = false;
        foreach ($ast->joins as $join) {
            if (in_array(strtoupper($join->joinType), ['LEFT', 'LEFT OUTER', 'RIGHT', 'RIGHT OUTER', 'FULL', 'FULL OUTER'], true)) {
                $hasOuterJoin = true;
                break;
            }
        }

        if ($hasMultipleTables && $hasWhere && !$hasOuterJoin) {
            // Limit tables to avoid exponential explosion
            $tableCount = 1 + count($ast->joins);
            if ($tableCount > 8) {
                throw new \RuntimeException(
                    "VDB limitation: joins limited to 8 tables (got $tableCount). " .
                    "Break into smaller queries or use subqueries."
                );
            }

            // Use optimized path: push predicates to tables first, then join
            $table = $this->executeSelectWithPredicatePushdown($ast);
        } else {
            // Standard path: process JOINs then WHERE
            if (!empty($ast->joins)) {
                $baseAlias = $ast->fromAlias ?? $tableName;
                $table = $table->withAlias($baseAlias);

                foreach ($ast->joins as $join) {
                    $table = $this->applyJoin($table, $join);
                }
            }

            // Apply WHERE
            if ($ast->where !== null) {
                $where = $this->optimizer->optimize($ast->where);
                $table = $this->applyWhereToTableInterface($table, $where);
            }
        }

        // Apply ORDER BY
        if ($ast->orderBy) {
            $table = $this->applyOrderBy($table, $ast->orderBy);
        }

        // Apply OFFSET
        if ($ast->offset !== null) {
            $offset = $this->evaluator->evaluate($ast->offset, null);
            $table = $table->offset((int)$offset);
        }

        // Apply LIMIT
        if ($ast->limit !== null) {
            $limit = $this->evaluator->evaluate($ast->limit, null);
            $table = $table->limit((int)$limit);
        }

        // Project to requested columns
        $columnNames = $this->extractColumnNames($ast->columns);
        if ($columnNames !== null) {
            // Without JOINs the base table is never wrapped in an AliasTable, so
            // its columns are unqualified. A qualified reference to the single
            // FROM table ("SELECT o.user_id FROM orders o") must be stripped
            // down to the bare column name or the projection fails.
            if (empty($ast->joins)) {
                $prefixes = [strtolower($tableName) . '.'];
                if ($ast->fromAlias !== null) {
                    $prefixes[] = strtolower($ast->fromAlias) . '.';
                }
                $columnNames = array_map(function (string $name) use ($prefixes): string {
                    foreach ($prefixes as $prefix) {
                        if (str_starts_with(strtolower($name), $prefix)) {
                            return substr($name, strlen($prefix));
                        }
                    }
                    return $name;
                }, $columnNames);
            }
            $table = $table->columns(...$columnNames);
        }

        // Apply DISTINCT (after column projection, per SQL semantics)
        if ($ast->distinct) {
            $table = $table->distinct();
        }

        return $table;
    }

    /**
     * Execute SELECT with CROSS JOINs using predicate pushdown optimization
     *
     * Analyzes WHERE clause to push single-table predicates to their source
     * tables before building the cross join. This dramatically reduces the
     * intermediate result size.
     *
     * @param SelectStatement $ast The SELECT statement with CROSS JOINs
     * @return TableInterface
     */
    private function executeSelectWithPredicatePushdown(SelectStatement $ast): TableInterface
    {
        // 1. Collect all tables with their aliases
        $tableName = $ast->from->getFullName();
        $baseAlias = $ast->fromAlias ?? $tableName;
        $tables = [$baseAlias => $this->getTable($tableName)->withAlias($baseAlias)];

        foreach ($ast->joins as $join) {
            $joinTableName = $join->table->getFullName();
            $joinAlias = $join->alias ?? $joinTableName;
            $tables[$joinAlias] = $this->getTable($joinTableName)->withAlias($joinAlias);
        }

        // 2. Optimize and flatten WHERE into AND-connected predicates.
        //    JOIN ... ON conditions participate on equal footing: with inner
        //    semantics, ON is equivalent to a WHERE predicate, and the
        //    equi-join graph below needs them to connect the tables.
        $predicates = [];
        $optimizedWhere = $ast->where !== null ? $this->optimizer->optimize($ast->where) : null;
        $this->flattenAndPredicates($optimizedWhere, $predicates);
        foreach ($ast->joins as $join) {
            if ($join->condition !== null) {
                $this->flattenAndPredicates($this->optimizer->optimize($join->condition), $predicates);
            }
        }

        // 3. Classify predicates and push single-table ones
        $remainingPredicates = [];
        foreach ($predicates as $pred) {
            $tablesReferenced = $this->findTablesInPredicate($pred, array_keys($tables));

            if (count($tablesReferenced) === 1) {
                // Single-table predicate - push to that table
                $tableAlias = $tablesReferenced[0];
                try {
                    $tables[$tableAlias] = $this->applyWhereToTableInterface($tables[$tableAlias], $pred);
                } catch (\RuntimeException $e) {
                    // Can't push this predicate - keep for later
                    $remainingPredicates[] = $pred;
                }
            } else {
                // Cross-table predicate - apply after join
                $remainingPredicates[] = $pred;
            }
        }

        // 4. Build equi-join graph and find connected components
        // This ensures we fully reduce each component via InnerJoins before cross-joining
        $tableNames = array_keys($tables);

        // Extract all equi-join predicates with their table pairs
        $equiJoins = []; // [{pred, key, tables: [t1, t2], cols: [left, right]}]
        foreach ($remainingPredicates as $k => $pred) {
            $equiJoin = $this->tryExtractEquiJoin($pred, $tableNames);
            if ($equiJoin !== null) {
                $deps = $this->findTablesInPredicate($pred, $tableNames);
                if (count($deps) === 2) {
                    $equiJoins[] = [
                        'key' => $k,
                        'pred' => $pred,
                        'tables' => $deps,
                        'cols' => $equiJoin,
                    ];
                }
            }
        }

        // Build connected components using union-find
        $parent = array_combine($tableNames, $tableNames);
        $find = function($x) use (&$parent, &$find) {
            if ($parent[$x] !== $x) {
                $parent[$x] = $find($parent[$x]);
            }
            return $parent[$x];
        };
        $union = function($x, $y) use (&$parent, $find) {
            $px = $find($x);
            $py = $find($y);
            if ($px !== $py) {
                $parent[$px] = $py;
            }
        };

        // Union tables connected by equi-joins
        foreach ($equiJoins as $ej) {
            $union($ej['tables'][0], $ej['tables'][1]);
        }

        // Group tables by component
        $components = [];
        foreach ($tableNames as $t) {
            $root = $find($t);
            $components[$root][] = $t;
        }
        $components = array_values($components);

        // 5. Join within each component using InnerJoins, then cross-join components
        $componentResults = [];
        $usedPredicateKeys = [];

        foreach ($components as $componentTables) {
            $result = null;
            $joinedTables = [];

            // Greedily join tables in this component
            while (count($joinedTables) < count($componentTables)) {
                if ($result === null) {
                    // Start with first table
                    $firstTable = $componentTables[0];
                    $result = $tables[$firstTable];
                    $joinedTables[] = $firstTable;
                    continue;
                }

                // Find an equi-join connecting a new table to already-joined tables
                $foundJoin = false;
                foreach ($equiJoins as $ej) {
                    if (isset($usedPredicateKeys[$ej['key']])) {
                        continue;
                    }

                    $t1 = $ej['tables'][0];
                    $t2 = $ej['tables'][1];

                    // Check if this joins a new table to existing result
                    $t1Joined = in_array($t1, $joinedTables, true);
                    $t2Joined = in_array($t2, $joinedTables, true);

                    if ($t1Joined && !$t2Joined && in_array($t2, $componentTables, true)) {
                        // Join t2 to result
                        $bindPredicate = (new \mini\Table\Predicate())
                            ->eqBind($ej['cols']['left'], ':' . $ej['cols']['right']);
                        $leftWithBind = $result->withProperty('__bind__', $bindPredicate);
                        $result = new \mini\Table\Wrappers\InnerJoinTable($leftWithBind, $tables[$t2]);
                        $joinedTables[] = $t2;
                        $usedPredicateKeys[$ej['key']] = true;
                        unset($remainingPredicates[$ej['key']]);
                        $foundJoin = true;
                        break;
                    } elseif ($t2Joined && !$t1Joined && in_array($t1, $componentTables, true)) {
                        // Join t1 to result (swap columns)
                        $bindPredicate = (new \mini\Table\Predicate())
                            ->eqBind($ej['cols']['right'], ':' . $ej['cols']['left']);
                        $leftWithBind = $result->withProperty('__bind__', $bindPredicate);
                        $result = new \mini\Table\Wrappers\InnerJoinTable($leftWithBind, $tables[$t1]);
                        $joinedTables[] = $t1;
                        $usedPredicateKeys[$ej['key']] = true;
                        unset($remainingPredicates[$ej['key']]);
                        $foundJoin = true;
                        break;
                    }
                }

                // If no equi-join found, add remaining tables via cross-join (shouldn't happen in a component)
                if (!$foundJoin) {
                    foreach ($componentTables as $t) {
                        if (!in_array($t, $joinedTables, true)) {
                            $result = new CrossJoinTable($result, $tables[$t]);
                            $joinedTables[] = $t;
                            break;
                        }
                    }
                }
            }

            $componentResults[] = $result;
        }

        // 6. Cross-join the components together
        $result = $componentResults[0];
        for ($i = 1; $i < count($componentResults); $i++) {
            $result = new CrossJoinTable($result, $componentResults[$i]);
        }

        // 7. Apply remaining predicates (non-equi-join conditions)
        if (!empty($remainingPredicates)) {
            $combinedPredicate = $this->rebuildAndExpression(array_values($remainingPredicates));
            $result = $this->filterByExpression($result, $combinedPredicate);
        }

        return $result;
    }

    /**
     * Flatten an AND-connected expression into a list of predicates
     */
    private function flattenAndPredicates(ASTNode $node, array &$predicates): void
    {
        if ($node instanceof BinaryOperation && strtoupper($node->operator) === 'AND') {
            $this->flattenAndPredicates($node->left, $predicates);
            $this->flattenAndPredicates($node->right, $predicates);
        } else {
            $predicates[] = $node;
        }
    }

    /**
     * Find which tables are referenced in a predicate
     *
     * @param ASTNode $node The predicate to analyze
     * @param array $knownTables List of table aliases to look for
     * @return array Table aliases referenced
     */
    private function findTablesInPredicate(ASTNode $node, array $knownTables): array
    {
        $tables = [];
        $this->collectTableReferences($node, $knownTables, $tables);
        return array_unique($tables);
    }

    /**
     * Try to extract equi-join columns from a predicate
     *
     * Detects patterns like: col1 = col2 or table1.col1 = table2.col2
     * Returns null if not a simple equi-join between two columns.
     *
     * @param ASTNode $node The predicate to check
     * @param array $knownTables List of table aliases to resolve unqualified columns
     * @return array{left: string, right: string}|null Fully qualified column names or null
     */
    private function tryExtractEquiJoin(ASTNode $node, array $knownTables = []): ?array
    {
        if (!$node instanceof BinaryOperation) {
            return null;
        }

        // Must be equality comparison
        if ($node->operator !== '=') {
            return null;
        }

        // Both sides must be simple column references
        if (!$node->left instanceof IdentifierNode || !$node->right instanceof IdentifierNode) {
            return null;
        }

        $leftCol = $this->resolveColumnWithTable($node->left, $knownTables);
        $rightCol = $this->resolveColumnWithTable($node->right, $knownTables);

        return ['left' => $leftCol, 'right' => $rightCol];
    }

    /**
     * Resolve a column identifier to its fully qualified name (table.column)
     */
    private function resolveColumnWithTable(IdentifierNode $node, array $knownTables): string
    {
        // Already qualified
        if ($node->isQualified()) {
            $qualifier = $node->getQualifier()[0] ?? '';
            return $qualifier . '.' . $node->getName();
        }

        $colName = $node->getName();

        // Try to infer table from column numeric suffix (a1 -> t1, b36 -> t36, x61 -> t61)
        // Column names follow pattern: letter + digits (e.g., a1, b36, x61)
        if (preg_match('/^[a-z]+(\d+)$/i', $colName, $m)) {
            $tableSuffix = $m[1];
            $inferredTable = 't' . $tableSuffix;
            if (in_array($inferredTable, $knownTables, true)) {
                return $inferredTable . '.' . $colName;
            }
        }

        // Fallback: return unqualified name
        return $colName;
    }

    /**
     * Recursively collect table references from an expression
     */
    private function collectTableReferences(ASTNode $node, array $knownTables, array &$tables): void
    {
        if ($node instanceof IdentifierNode) {
            // Check if identifier is qualified (e.g., t1.id)
            if ($node->isQualified()) {
                $qualifier = $node->getQualifier();
                $tableAlias = $qualifier[0] ?? null;
                if ($tableAlias !== null && in_array($tableAlias, $knownTables, true)) {
                    $tables[] = $tableAlias;
                    return;
                }
            }

            $colName = $node->getName();
            // Check if column name starts with a known table alias (legacy format)
            foreach ($knownTables as $alias) {
                if (str_starts_with($colName, $alias . '.')) {
                    $tables[] = $alias;
                    return;
                }
            }
            // For unqualified columns, infer table from numeric suffix (a1 -> t1, b36 -> t36)
            if (!str_contains($colName, '.') && preg_match('/^[a-z]+(\d+)$/i', $colName, $m)) {
                $inferredTable = 't' . $m[1];
                if (in_array($inferredTable, $knownTables, true)) {
                    $tables[] = $inferredTable;
                    return;
                }
            }
        } elseif ($node instanceof BinaryOperation) {
            $this->collectTableReferences($node->left, $knownTables, $tables);
            $this->collectTableReferences($node->right, $knownTables, $tables);
        } elseif ($node instanceof UnaryOperation) {
            $this->collectTableReferences($node->expression, $knownTables, $tables);
        } elseif ($node instanceof InOperation) {
            $this->collectTableReferences($node->left, $knownTables, $tables);
            if (!$node->isSubquery()) {
                foreach ($node->values as $val) {
                    $this->collectTableReferences($val, $knownTables, $tables);
                }
            }
        } elseif ($node instanceof BetweenOperation) {
            $this->collectTableReferences($node->expression, $knownTables, $tables);
            $this->collectTableReferences($node->low, $knownTables, $tables);
            $this->collectTableReferences($node->high, $knownTables, $tables);
        } elseif ($node instanceof IsNullOperation) {
            $this->collectTableReferences($node->expression, $knownTables, $tables);
        } elseif ($node instanceof LikeOperation) {
            $this->collectTableReferences($node->left, $knownTables, $tables);
            $this->collectTableReferences($node->pattern, $knownTables, $tables);
        } elseif ($node instanceof FunctionCallNode) {
            foreach ($node->arguments as $arg) {
                $this->collectTableReferences($arg, $knownTables, $tables);
            }
        }
    }

    /**
     * Rebuild an AND expression from a list of predicates
     */
    private function rebuildAndExpression(array $predicates): ASTNode
    {
        if (empty($predicates)) {
            throw new \RuntimeException("Cannot rebuild empty predicate list");
        }

        $result = array_shift($predicates);
        foreach ($predicates as $pred) {
            $result = new BinaryOperation($result, 'AND', $pred);
        }
        return $result;
    }

    /**
     * Execute a subquery and return as SetInterface for IN clause
     *
     * Handles column name mapping if the subquery column differs from
     * the outer query's expected column name.
     *
     * @param SubqueryNode $subquery The subquery AST node
     * @param string $expectedColumn The column name expected by the outer query
     * @return SetInterface
     */
    private function executeSubqueryAsSet(SubqueryNode $subquery, string $expectedColumn): SetInterface
    {
        // A projection that is not a bare column - an aggregate, an arithmetic
        // expression, a function call - cannot be expressed as a table-level
        // column selection. executeSelectAsTable() silently skips the projection
        // in that case and the set would end up carrying the *source* table's
        // first column instead of the computed value. Materialise the rows.
        $query = $subquery->query;
        if ($query instanceof SelectStatement && $this->selectListIsComputed($query)) {
            $values = [];
            foreach ($this->executeSelect($query) as $row) {
                $props = get_object_vars($row);
                $value = reset($props);
                // Set keys members by string, so a NULL would become an empty
                // string and wrongly match rows holding ''. A NULL in an IN list
                // can only make the predicate UNKNOWN, never TRUE, so dropping
                // it is exact for the (non-negated) membership test this feeds.
                if ($value !== null) {
                    $values[] = $value;
                }
            }
            return new \mini\Table\Utility\Set($expectedColumn, $values);
        }

        // `IN (SELECT … UNION SELECT …)` — the subquery is a set operation,
        // not a plain SELECT. `IN (WITH c AS (…) SELECT … FROM c)` — it is a
        // WITH statement. Both are legal <query expression>s under SQL:2003.
        $table = match (true) {
            $query instanceof UnionNode => $this->executeUnionAsTable($query),
            $query instanceof WithStatement => $this->rowsToTable(
                iterator_to_array($this->executeWithStatement($query))
            ),
            default => $this->executeSelectAsTable($query),
        };

        // Get the subquery's column name(s)
        $subqueryColumns = array_keys($table->getColumns());

        if (empty($subqueryColumns)) {
            throw new \RuntimeException("Subquery must select at least one column");
        }

        // Use first column for IN comparison
        $subqueryColumn = $subqueryColumns[0];

        // If column names match, return table directly (it implements SetInterface)
        if ($subqueryColumn === $expectedColumn) {
            return $table;
        }

        // Column names differ - wrap with mapping
        return new ColumnMappedSet($table, $subqueryColumn, $expectedColumn);
    }

    /**
     * Materialise a non-correlated subquery's first projected column as a plain
     * value list, preserving NULLs.
     *
     * executeSubqueryAsSet() returns a SetInterface, and Set keys its members by
     * string, so a NULL in the result becomes an empty string. NOT IN needs to
     * know whether a NULL is present - it makes the whole predicate UNKNOWN -
     * so it reads the values through here instead.
     *
     * @return list<mixed>
     */
    private function subqueryScalarValues(SubqueryNode $subquery): array
    {
        $query = $subquery->query;
        $values = [];

        // A computed projection (function call, arithmetic, aggregate) cannot be
        // expressed as a table-level column selection - execute it row by row.
        if ($query instanceof SelectStatement && $this->selectListIsComputed($query)) {
            foreach ($this->executeSelect($query) as $row) {
                $props = get_object_vars($row);
                $values[] = reset($props);
            }
            return $values;
        }

        $table = $query instanceof UnionNode
            ? $this->executeUnionAsTable($query)
            : $this->executeSelectAsTable($query);

        $columns = array_keys($table->getColumns());
        if ($columns === []) {
            throw new \RuntimeException("Subquery must select at least one column");
        }
        $column = $columns[0];

        foreach ($table as $row) {
            $values[] = $row->$column ?? null;
        }

        return $values;
    }

    /**
     * Does this SELECT project a computed value rather than a bare column?
     *
     * Only the first column matters: IN-subqueries and scalar subqueries read
     * the first column of each row.
     */
    private function selectListIsComputed(SelectStatement $ast): bool
    {
        $first = $ast->columns[0] ?? null;

        return $first instanceof ColumnNode && !$first->expression instanceof IdentifierNode;
    }

    /**
     * Execute a derived table (subquery in FROM position)
     *
     * Executes the subquery and returns an InMemoryTable with the results.
     *
     * @param SubqueryNode $subquery The subquery to execute
     * @param string|null $alias The alias for the derived table
     * @return TableInterface The materialized table
     */
    private function executeDerivedTable(SubqueryNode $subquery, ?string $alias): TableInterface
    {
        // Execute the inner query (SELECT, UNION, or WITH)
        if ($subquery->query instanceof UnionNode) {
            return $this->executeUnionAsTable($subquery->query);
        }
        if ($subquery->query instanceof WithStatement) {
            // Execute WITH and materialize to table
            $resultSet = $this->executeWithStatement($subquery->query);
            return $this->rowsToTable(iterator_to_array($resultSet));
        }
        $rows = iterator_to_array($this->executeSelect($subquery->query));

        if (empty($rows)) {
            // Return empty table - need to infer columns from the query
            $columns = $this->inferColumnsFromSelect($subquery->query);
            return new \mini\Table\InMemoryTable(...$columns);
        }

        // Build columns from first row
        $firstRow = $rows[0];
        $columns = [];
        foreach (get_object_vars($firstRow) as $colName => $value) {
            $type = match (true) {
                is_int($value) => \mini\Table\Types\ColumnType::Int,
                is_float($value) => \mini\Table\Types\ColumnType::Float,
                is_bool($value) => \mini\Table\Types\ColumnType::Int,
                default => \mini\Table\Types\ColumnType::Text,
            };
            $columns[] = new \mini\Table\ColumnDef($colName, $type);
        }

        // Create and populate table
        $table = new \mini\Table\InMemoryTable(...$columns);
        foreach ($rows as $row) {
            $table->insert((array)$row);
        }

        return $table;
    }

    /**
     * Infer column definitions from a SELECT statement (for empty derived tables)
     */
    private function inferColumnsFromSelect(SelectStatement $query): array
    {
        $columns = [];
        foreach ($query->columns as $col) {
            if ($col instanceof ColumnNode) {
                $name = $col->alias ?? ($col->expression instanceof IdentifierNode
                    ? $col->expression->getName()
                    : 'column');
                $columns[] = new \mini\Table\ColumnDef($name, \mini\Table\Types\ColumnType::Text);
            }
        }
        return $columns ?: [new \mini\Table\ColumnDef('column', \mini\Table\Types\ColumnType::Text)];
    }

    /**
     * Execute any query body that may appear in a subquery position.
     *
     * SQL:2003 puts a <query expression> - not merely a <query specification> -
     * everywhere a subquery is allowed, so IN, EXISTS, a scalar subquery and a
     * derived table must all accept a set operation and a WITH clause, not just
     * a plain SELECT.
     */
    private function executeSubqueryBody(\mini\Parsing\SQL\AST\ASTNode $ast): iterable
    {
        if ($ast instanceof SelectStatement) {
            yield from $this->executeSelect($ast);
            return;
        }
        if ($ast instanceof UnionNode) {
            yield from $this->executeUnionAsTable($ast);
            return;
        }
        if ($ast instanceof WithStatement) {
            yield from $this->executeWithStatement($ast);
            return;
        }
        throw new \RuntimeException("Unexpected subquery body: " . get_class($ast));
    }

    /**
     * Execute a subquery with outer row context for correlated subqueries
     *
     * Used by ExpressionEvaluator when evaluating scalar subqueries in SELECT or WHERE.
     *
     * @param \mini\Parsing\SQL\AST\ASTNode $query The subquery body: a SELECT, a
     *        set operation (UNION/INTERSECT/EXCEPT), or a WITH statement
     * @param object|null $outerRow The outer row for correlated subquery references
     * @return iterable Result rows
     */
    private function executeSubqueryWithContext(\mini\Parsing\SQL\AST\ASTNode $query, ?object $outerRow): iterable
    {
        // Only a plain SELECT has the specialised paths below. A set operation
        // or a WITH body (SQL:2003 allows a <with clause> on any <query
        // expression>) is executed generically, with the outer row substituted
        // in first when it is correlated.
        if (!$query instanceof SelectStatement) {
            if ($outerRow !== null && $this->selectIsCorrelated($query)) {
                $resolved = $query->deepClone();
                $this->substituteOuterReferences($resolved, [], $outerRow);
                yield from $this->executeSubqueryBody($resolved);
                return;
            }
            yield from $this->executeSubqueryBody($query);
            return;
        }

        // For non-correlated subqueries (no outer row), use standard execution
        if ($outerRow === null) {
            yield from $this->executeSelect($query);
            return;
        }

        // For correlated subqueries, we need to evaluate with outer row context
        // Detect outer references in WHERE
        if ($query->from === null) {
            // SELECT without FROM - just evaluate expressions
            yield from $this->executeScalarSelect($query);
            return;
        }

        if (!$this->selectIsCorrelated($query)) {
            yield from $this->executeSelect($query);
            return;
        }

        // Fast path: filter the single source table in place, resolving outer
        // references from a context map. It only covers a narrow shape - see
        // contextEvaluatorCanExpress() - because everything it does not
        // recognise it silently treats as TRUE, and it has no notion of JOIN,
        // GROUP BY, HAVING, DISTINCT, ORDER BY, LIMIT or OFFSET.
        //
        // The projection is just as restricted: projectRow()/executeAggregateOnRows()
        // evaluate the SELECT list against *inner* rows with no outer context map,
        // so an outer reference there would silently resolve against the inner
        // table (SELECT u.id FROM orders o -> reads o.id) or fail to resolve at
        // all. The select list must therefore be outer-free too.
        $innerScope = $this->innerScopeOf($query);
        $fastPath = $query->from instanceof IdentifierNode
            && empty($query->joins)
            && empty($query->groupBy)
            && $query->having === null
            && !$query->distinct
            && empty($query->orderBy)
            && $query->limit === null
            && $query->offset === null
            && $this->contextEvaluatorCanExpress($query->where, $innerScope)
            && $this->nodeIsOuterFree($query->columns, $innerScope);

        if (!$fastPath) {
            // General path: substitute the outer row's values into a private
            // clone and run it through the normal executor, so every clause
            // behaves as it would in a standalone query.
            $resolved = $query->deepClone();
            $this->substituteOuterReferences($resolved, [], $outerRow);
            yield from $this->executeSelect($resolved);
            return;
        }

        $tableName = $query->from->getFullName();
        $tableAlias = $query->fromAlias ?? $tableName;
        $table = $this->getTable($tableName);

        if ($table === null) {
            throw new \RuntimeException("Table not found: $tableName");
        }

        $outerRefs = $query->where !== null
            ? $this->findOuterReferences($query, $tableName, $tableAlias)
            : [];

        // Build outer context map from outer row
        $outerContext = [];
        foreach ($outerRefs as $ref) {
            $qualifiedCol = $ref['table'] . '.' . $ref['column'];
            $outerContext[$qualifiedCol] = $outerRow->$qualifiedCol ?? $outerRow->{$ref['column']} ?? null;
        }

        // Filter rows manually with outer context
        $filteredRows = [];
        foreach ($table as $row) {
            if ($query->where === null || $this->evaluateWhereWithContext($query->where, $row, $outerContext)) {
                $filteredRows[] = $row;
            }
        }

        // Check for aggregates
        if ($this->hasAggregates($query->columns)) {
            yield from $this->executeAggregateOnRows($query, $filteredRows);
            return;
        }

        // Project and return
        foreach ($filteredRows as $row) {
            yield $this->projectRow($row, $query->columns);
        }
    }

    /**
     * Lowercased table names/aliases introduced by this SELECT's own FROM/JOINs.
     *
     * @return array<int,string>
     */
    private function innerScopeOf(SelectStatement $query): array
    {
        $scope = [];

        if ($query->fromAlias !== null) {
            $scope[] = strtolower($query->fromAlias);
        } elseif ($query->from instanceof IdentifierNode) {
            $scope[] = strtolower($query->from->getFullName());
        }

        foreach ($query->joins as $join) {
            if ($join->alias !== null) {
                $scope[] = strtolower($join->alias);
            } elseif ($join->table instanceof IdentifierNode) {
                $scope[] = strtolower($join->table->getFullName());
            }
        }

        return $scope;
    }

    /**
     * Can evaluateWhereWithContext() faithfully evaluate this WHERE clause?
     *
     * That evaluator understands AND/OR over comparison operators whose operands
     * are literals, identifiers, or expressions free of outer references - and
     * returns TRUE for anything else, quietly deleting the predicate. Callers
     * must check before using it.
     *
     * @param array<int,string> $innerScope Table names/aliases local to the subquery
     */
    private function contextEvaluatorCanExpress(?\mini\Parsing\SQL\AST\ASTNode $node, array $innerScope): bool
    {
        if ($node === null) {
            return true;
        }

        if (!$node instanceof BinaryOperation) {
            return false;
        }

        $op = strtoupper($node->operator);

        if ($op === 'AND' || $op === 'OR') {
            return $this->contextEvaluatorCanExpress($node->left, $innerScope)
                && $this->contextEvaluatorCanExpress($node->right, $innerScope);
        }

        if (!in_array($op, ['=', '!=', '<>', '<', '<=', '>', '>='], true)) {
            return false;
        }

        return $this->contextOperandIsEvaluable($node->left, $innerScope)
            && $this->contextOperandIsEvaluable($node->right, $innerScope);
    }

    /**
     * @param array<int,string> $innerScope Table names/aliases local to the subquery
     */
    private function contextOperandIsEvaluable(\mini\Parsing\SQL\AST\ASTNode $node, array $innerScope): bool
    {
        if ($node instanceof LiteralNode || $node instanceof IdentifierNode) {
            return true;
        }

        // Any richer expression is handed to the plain evaluator, which knows
        // nothing about the outer row - safe only if it references no outer table
        return $this->nodeIsOuterFree($node, $innerScope);
    }

    /**
     * Is every qualified column reference under $node resolvable inside the
     * subquery's own scope?
     *
     * Anything evaluated without an outer-row context map - the fast path's
     * SELECT-list projection, a plain expression operand - is only safe when
     * this returns true. An outer reference in such a position resolves against
     * the inner table instead, producing a silently wrong value.
     *
     * @param mixed $node ASTNode, or an array of them (e.g. a SELECT list)
     * @param array<int,string> $innerScope Table names/aliases local to the subquery
     */
    private function nodeIsOuterFree(mixed $node, array $innerScope): bool
    {
        $scope = [];
        $qualifiers = [];
        $this->collectScopeAndQualifiers($node, $scope, $qualifiers);

        foreach ($qualifiers as $qualifier) {
            if (!in_array($qualifier, $innerScope, true) && !in_array($qualifier, $scope, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Replace every outer (correlated) column reference in an AST with the
     * corresponding value from the outer row, turning a correlated subquery
     * into a plain one that the standard executor can run.
     *
     * The AST must already be a private clone - nodes are rewritten in place.
     *
     * @param array<int,string> $scope Lowercased table names/aliases in scope here
     */
    private function substituteOuterReferences(mixed $node, array $scope, object $outerRow): mixed
    {
        if (is_array($node)) {
            foreach ($node as $key => $child) {
                $node[$key] = $this->substituteOuterReferences($child, $scope, $outerRow);
            }
            return $node;
        }

        if (!$node instanceof \mini\Parsing\SQL\AST\ASTNode) {
            return $node;
        }

        if ($node instanceof IdentifierNode) {
            if (!$node->isQualified() || $node->isWildcard()) {
                return $node;
            }
            $qualifier = strtolower($node->getQualifier()[0] ?? '');
            if ($qualifier === '' || in_array($qualifier, $scope, true)) {
                return $node;
            }
            $full = $node->getFullName();
            $bare = $node->getName();
            $value = $outerRow->$full ?? $outerRow->$bare ?? null;
            return match (true) {
                $value === null => new LiteralNode(null, 'null'),
                is_bool($value) => new LiteralNode($value, 'boolean'),
                is_int($value), is_float($value) => new LiteralNode($value, 'number'),
                default => new LiteralNode((string) $value, 'string'),
            };
        }

        $childScope = $scope;
        if ($node instanceof SelectStatement) {
            if ($node->fromAlias !== null) {
                $childScope[] = strtolower($node->fromAlias);
            } elseif ($node->from instanceof IdentifierNode) {
                $childScope[] = strtolower($node->from->getFullName());
            }
            foreach ($node->joins as $join) {
                if ($join->alias !== null) {
                    $childScope[] = strtolower($join->alias);
                } elseif ($join->table instanceof IdentifierNode) {
                    $childScope[] = strtolower($join->table->getFullName());
                }
            }
        } elseif ($node instanceof WithStatement) {
            // A CTE name is in scope for the whole WITH, so `c.x` is a local
            // reference here - not an outer one to be substituted away.
            foreach ($node->ctes as $cte) {
                $childScope[] = strtolower($cte['name']);
            }
        }

        foreach (get_object_vars($node) as $name => $value) {
            // A table position holding an IdentifierNode names a table, not a
            // column - skip it. Holding a SubqueryNode it is a derived table,
            // a whole query whose own column references still need resolving.
            if ($node instanceof SelectStatement && $name === 'from'
                && !$value instanceof SubqueryNode) {
                continue;
            }
            if ($node instanceof \mini\Parsing\SQL\AST\JoinNode && $name === 'table'
                && !$value instanceof SubqueryNode) {
                continue;
            }
            $node->$name = $this->substituteOuterReferences($value, $childScope, $outerRow);
        }

        return $node;
    }

    /**
     * Execute aggregate query on pre-filtered rows
     */
    private function executeAggregateOnRows(SelectStatement $ast, array $rows): iterable
    {
        $aggregateInfos = $this->collectAggregateInfos($ast->columns);
        $nonAggregateColumns = $this->collectNonAggregateColumns($ast->columns);

        // Step phase
        $lastRow = null;
        foreach ($rows as $row) {
            $lastRow = $row;
            $this->stepAggregates($aggregateInfos, $row);
        }

        // Build result
        yield $this->buildAggregateResultRow($aggregateInfos, $nonAggregateColumns, $lastRow);
    }

    /**
     * Extract simple column names from SELECT columns
     *
     * Returns array of column names if all columns are simple identifiers,
     * or null if any column is an expression or wildcard.
     *
     * @return string[]|null
     */
    private function extractColumnNames(array $columns): ?array
    {
        // Wildcard: SELECT * - return null to skip projection
        if (count($columns) === 1) {
            $col = $columns[0];
            if ($col instanceof ColumnNode && $col->expression instanceof IdentifierNode) {
                if ($col->expression->isWildcard()) {
                    return null;
                }
            }
        }

        $names = [];
        foreach ($columns as $col) {
            if (!$col instanceof ColumnNode) {
                continue;
            }

            // Must be simple identifier (not expression)
            // For EXISTS with SELECT 1, we just skip projection
            if (!$col->expression instanceof IdentifierNode) {
                return null;
            }

            if ($col->expression->isWildcard()) {
                return null; // table.* - return all columns
            }

            // Use full qualified name for aliased tables (e.g., 'o.user_id' after JOIN)
            // Use base name for unqualified columns (e.g., 'name' without table prefix)
            $names[] = $col->expression->isQualified()
                ? $col->expression->getFullName()
                : $col->expression->getName();
        }

        return $names;
    }

    /**
     * Apply EXISTS operation to table
     *
     * For non-correlated EXISTS: evaluate once, return all or no rows
     * For correlated EXISTS: evaluate per row using Table binding
     */
    private function applyExistsToTable(TableInterface $table, ExistsOperation $node): TableInterface
    {
        $subqueryAst = $node->subquery->query;

        if (!$this->subqueryIsCorrelated($node->subquery)) {
            // Non-correlated: evaluate once, then keep every row or none.
            // Checked before touching $subqueryAst->from, which may be a
            // derived table and has no name to look up. executeUnionBranchAsTable()
            // also accepts a UNION/INTERSECT/EXCEPT body.
            $exists = $this->executeUnionBranchAsTable($subqueryAst)->exists();

            if ($node->negated) {
                $exists = !$exists;
            }

            return $exists ? $table : EmptyTable::from($table);
        }

        // The specialised correlated-EXISTS strategies below all assume a single
        // FROM table and a flat WHERE: they drop JOIN/GROUP BY/HAVING/LIMIT and
        // mistake a joined table's alias for an outer reference. Anything richer
        // goes through the generic per-row executor. A set operation or a WITH
        // body has no `from`/`where` at all, so it must be routed off before
        // existsNeedsGenericEvaluation() is asked about it.
        if (!$subqueryAst instanceof SelectStatement) {
            return $this->applyCorrelatedExistsGeneric($table, $node, $subqueryAst);
        }

        if ($this->existsNeedsGenericEvaluation($subqueryAst)) {
            return $this->applyCorrelatedExistsGeneric($table, $node, $subqueryAst);
        }

        $subqueryTableName = $subqueryAst->from->getFullName();
        $subqueryTableAlias = $subqueryAst->fromAlias ?? $subqueryTableName;

        // Find outer references in the subquery (check both table name and alias)
        $outerRefs = $this->findOuterReferences($subqueryAst, $subqueryTableName, $subqueryTableAlias);

        if (empty($outerRefs)) {
            // subqueryIsCorrelated() already proved there IS a correlation, so an
            // empty result means the reference sits in a node shape
            // collectOuterReferences() does not walk (NOT, CASE, a function call).
            // Treating it as non-correlated would drop the predicate entirely.
            return $this->applyCorrelatedExistsGeneric($table, $node, $subqueryAst);
        }

        // Try to use ExistsTable for equi-join correlations (much faster)
        $existsTableResult = $this->tryApplyExistsWithExistsTable($table, $node, $subqueryAst, $outerRefs);
        if ($existsTableResult !== null) {
            return $existsTableResult;
        }

        // A correlated OR cannot be expressed as a conjunction of binds. Use the
        // row-by-row context evaluator when it can express the predicate, and
        // the general executor otherwise.
        if ($this->hasOrWithOuterReferences($subqueryAst->where, $outerRefs)) {
            return $this->contextEvaluatorCanExpress($subqueryAst->where, $this->innerScopeOf($subqueryAst))
                ? $this->applyCorrelatedExistsRowByRow($table, $node, $subqueryAst, $outerRefs)
                : $this->applyCorrelatedExistsGeneric($table, $node, $subqueryAst);
        }

        // The template strategy can only express a handful of predicate shapes and
        // silently DROPS everything else (NOT, <>, IN, function calls, reversed
        // operands), which turns a filtered EXISTS into an unfiltered one. Only
        // use it when every predicate survives the translation.
        if (!$this->correlatedTemplateCanExpress($subqueryAst, $outerRefs)) {
            return $this->applyCorrelatedExistsGeneric($table, $node, $subqueryAst);
        }

        // Build template and evaluate per row (more efficient for AND-only cases)
        $template = $this->buildCorrelatedTemplate($subqueryAst, $outerRefs);

        // Find primary key column for later filtering (optional optimization)
        $columns = $table->getColumns();
        $pkColumn = null;
        foreach ($columns as $colName => $colDef) {
            if ($colDef->index === \mini\Table\Types\IndexType::Primary) {
                $pkColumn = $colName;
                break;
            }
        }

        // Filter rows where EXISTS evaluates to desired result
        $matchingPkValues = [];
        $matchingRows = [];
        foreach ($table as $row) {
            // Bind outer values
            $bindings = [];
            foreach ($outerRefs as $ref) {
                $outerColumn = $ref['column'];
                $paramName = ':outer_' . $ref['table'] . '_' . $outerColumn;
                // Use qualified column name (e.g., 'u.id') when table is aliased
                $qualifiedCol = $ref['table'] . '.' . $outerColumn;
                $bindings[$paramName] = $row->$qualifiedCol ?? $row->$outerColumn ?? null;
            }

            $boundTable = $template->bind($bindings);
            $exists = $boundTable->exists();

            if ($node->negated) {
                $exists = !$exists;
            }

            if ($exists) {
                if ($pkColumn !== null) {
                    $matchingPkValues[] = $row->$pkColumn;
                } else {
                    $matchingRows[] = $row;
                }
            }
        }

        // Build result - use PK optimization if available, otherwise build from rows
        if ($pkColumn !== null) {
            if (empty($matchingPkValues)) {
                return $table->except($table);
            }
            return $table->in($pkColumn, new \mini\Table\Utility\Set($pkColumn, $matchingPkValues));
        } else {
            // No PK - build result from collected rows
            if (empty($matchingRows)) {
                return $table->except($table);
            }
            $result = new \mini\Table\InMemoryTable(...array_values($columns));
            foreach ($matchingRows as $row) {
                $result->insert((array) $row);
            }
            return $result;
        }
    }

    /**
     * Can every predicate of this correlated subquery be translated into the
     * bind-based template built by {@see buildCorrelatedTemplate()}?
     *
     * The translator only understands `innerCol OP outerRef` and
     * `innerCol OP literal` joined by AND. Anything else it drops on the floor,
     * so the caller must fall back to a general evaluator instead.
     */
    private function correlatedTemplateCanExpress(SelectStatement $ast, array $outerRefs): bool
    {
        if ($ast->where === null) {
            return false;
        }

        $predicates = [];
        $this->flattenAndPredicates($ast->where, $predicates);

        foreach ($predicates as $pred) {
            if (!$pred instanceof BinaryOperation
                || !in_array($pred->operator, ['=', '<', '<=', '>', '>='], true)
                || !$pred->left instanceof IdentifierNode
            ) {
                return false;
            }

            if ($pred->right instanceof LiteralNode) {
                continue;
            }

            if (!$pred->right instanceof IdentifierNode) {
                return false;
            }

            $qualifier = $pred->right->getQualifier()[0] ?? null;
            $matched = false;
            foreach ($outerRefs as $ref) {
                if ($qualifier !== null
                    && strtolower($qualifier) === strtolower($ref['table'])
                    && $pred->right->getName() === $ref['column']
                ) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * Does this correlated EXISTS subquery need the generic per-row executor?
     *
     * True whenever the subquery uses a clause the specialised strategies drop
     * (JOIN, GROUP BY, HAVING, DISTINCT, LIMIT, OFFSET, derived FROM) or nests
     * another subquery, whose aliases their outer-reference detection misreads
     * as correlations.
     */
    private function existsNeedsGenericEvaluation(SelectStatement $ast): bool
    {
        return !empty($ast->joins)
            || !empty($ast->groupBy)
            || $ast->having !== null
            || $ast->distinct
            || $ast->limit !== null
            || $ast->offset !== null
            || $ast->from instanceof SubqueryNode
            || ($ast->where !== null && $this->astContainsSubquery($ast->where));
    }

    /**
     * Does this AST fragment contain a nested subquery or EXISTS?
     */
    private function astContainsSubquery(mixed $node): bool
    {
        if (is_array($node)) {
            foreach ($node as $child) {
                if ($this->astContainsSubquery($child)) {
                    return true;
                }
            }
            return false;
        }

        if (!$node instanceof \mini\Parsing\SQL\AST\ASTNode) {
            return false;
        }

        if ($node instanceof SubqueryNode || $node instanceof ExistsOperation) {
            return true;
        }

        foreach (get_object_vars($node) as $value) {
            if ($this->astContainsSubquery($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate a correlated EXISTS one outer row at a time.
     *
     * Substitutes the outer row's values into a clone of the subquery and runs
     * it through the normal executor, so every SQL clause behaves exactly as it
     * would in a standalone query. Slower than the specialised strategies, but
     * correct for the shapes they cannot express.
     */
    private function applyCorrelatedExistsGeneric(
        TableInterface $table,
        ExistsOperation $node,
        \mini\Parsing\SQL\AST\ASTNode $subqueryAst
    ): TableInterface {
        $result = new \mini\Table\InMemoryTable(...array_values($table->getColumns()));

        foreach ($table as $row) {
            $resolved = $subqueryAst->deepClone();
            $this->substituteOuterReferences($resolved, [], $row);

            $exists = false;
            foreach ($this->executeSubqueryBody($resolved) as $_) {
                $exists = true;
                break;
            }

            if ($node->negated) {
                $exists = !$exists;
            }

            if ($exists) {
                $result->insert((array) $row);
            }
        }

        return $result;
    }

    /**
     * Is this subquery correlated with an enclosing query?
     *
     * A subquery is correlated when it references a table (or alias) that it
     * does not itself introduce. Correlated subqueries MUST be evaluated once
     * per outer row; they can never be hoisted out and evaluated a single time.
     *
     * The check is deliberately conservative: any qualified identifier whose
     * qualifier is not in scope anywhere inside the subquery counts as a
     * correlation. Over-reporting only costs a slower execution path;
     * under-reporting produces silently wrong results.
     */
    private function subqueryIsCorrelated(SubqueryNode $subquery): bool
    {
        return $this->selectIsCorrelated($subquery->query);
    }

    /**
     * Does this query reference a table it does not itself introduce?
     *
     * @see subqueryIsCorrelated()
     */
    private function selectIsCorrelated(\mini\Parsing\SQL\AST\ASTNode $query): bool
    {
        $scope = [];
        $qualifiers = [];
        $this->collectScopeAndQualifiers($query, $scope, $qualifiers);

        foreach ($qualifiers as $qualifier) {
            if (!in_array($qualifier, $scope, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Walk an AST collecting table scopes (FROM/JOIN names and aliases) and
     * the qualifiers used by every qualified column reference.
     *
     * @param array<int,string> $scope      Lowercased table names/aliases in scope
     * @param array<int,string> $qualifiers Lowercased qualifiers seen on column refs
     */
    private function collectScopeAndQualifiers(mixed $node, array &$scope, array &$qualifiers): void
    {
        if (is_array($node)) {
            foreach ($node as $child) {
                $this->collectScopeAndQualifiers($child, $scope, $qualifiers);
            }
            return;
        }

        if (!$node instanceof \mini\Parsing\SQL\AST\ASTNode) {
            return;
        }

        if ($node instanceof SelectStatement) {
            // SQL:2003 - once a table is aliased, only the alias is in scope.
            if ($node->fromAlias !== null) {
                $scope[] = strtolower($node->fromAlias);
            } elseif ($node->from instanceof IdentifierNode) {
                $scope[] = strtolower($node->from->getFullName());
            }
            foreach ($node->joins as $join) {
                if ($join->alias !== null) {
                    $scope[] = strtolower($join->alias);
                } elseif ($join->table instanceof IdentifierNode) {
                    $scope[] = strtolower($join->table->getFullName());
                }
            }
        } elseif ($node instanceof WithStatement) {
            foreach ($node->ctes as $cte) {
                $scope[] = strtolower($cte['name']);
            }
        } elseif ($node instanceof IdentifierNode) {
            if ($node->isQualified()) {
                $qualifier = $node->getQualifier()[0] ?? null;
                if ($qualifier !== null) {
                    $qualifiers[] = strtolower($qualifier);
                }
            }
            return;
        }

        foreach (get_object_vars($node) as $name => $value) {
            // Skip a table position only when it names a table. A derived table
            // (SubqueryNode) must be walked: `(SELECT COUNT(*) FROM (SELECT *
            // FROM orders o WHERE o.user_id = u.id) d)` hides its only
            // correlation there, and skipping it reports the subquery as
            // uncorrelated - evaluated once, same answer for every outer row.
            if ($node instanceof SelectStatement && $name === 'from'
                && !$value instanceof SubqueryNode) {
                continue;
            }
            if ($node instanceof \mini\Parsing\SQL\AST\JoinNode && $name === 'table'
                && !$value instanceof SubqueryNode) {
                continue;
            }
            $this->collectScopeAndQualifiers($value, $scope, $qualifiers);
        }
    }

    /**
     * Find outer references in a subquery WHERE clause
     *
     * Returns array of ['table' => 'tableName', 'column' => 'columnName']
     * for each reference to a table not in the subquery's FROM
     */
    private function findOuterReferences(SelectStatement $ast, string $subqueryTable, string $subqueryAlias): array
    {
        $outerRefs = [];

        if ($ast->where === null) {
            return $outerRefs;
        }

        // When a table is aliased (FROM t1 AS x), only the alias is valid for inner refs.
        // The original table name becomes an outer reference (e.g., t1.b refers to outer t1).
        if ($subqueryAlias !== $subqueryTable) {
            $innerTables = [strtolower($subqueryAlias)];
        } else {
            $innerTables = [strtolower($subqueryTable)];
        }
        $this->collectOuterReferences($ast->where, $innerTables, $outerRefs);

        return $outerRefs;
    }

    /**
     * Recursively collect outer references from AST node
     *
     * @param array $innerTables Lowercase names/aliases of inner (subquery) tables
     */
    private function collectOuterReferences(\mini\Parsing\SQL\AST\ASTNode $node, array $innerTables, array &$refs): void
    {
        if ($node instanceof IdentifierNode) {
            if ($node->isQualified()) {
                $qualifier = $node->getQualifier()[0] ?? null;
                if ($qualifier !== null && !in_array(strtolower($qualifier), $innerTables, true)) {
                    // This references an outer table
                    $refs[] = [
                        'table' => $qualifier,
                        'column' => $node->getName(),
                    ];
                }
            }
            return;
        }

        if ($node instanceof BinaryOperation) {
            $this->collectOuterReferences($node->left, $innerTables, $refs);
            $this->collectOuterReferences($node->right, $innerTables, $refs);
            return;
        }

        if ($node instanceof InOperation) {
            $this->collectOuterReferences($node->left, $innerTables, $refs);
            if (!$node->isSubquery()) {
                foreach ($node->values as $v) {
                    $this->collectOuterReferences($v, $innerTables, $refs);
                }
            }
            return;
        }

        if ($node instanceof \mini\Parsing\SQL\AST\IsNullOperation) {
            $this->collectOuterReferences($node->expression, $innerTables, $refs);
            return;
        }

        if ($node instanceof \mini\Parsing\SQL\AST\LikeOperation) {
            $this->collectOuterReferences($node->left, $innerTables, $refs);
            $this->collectOuterReferences($node->pattern, $innerTables, $refs);
            return;
        }

        if ($node instanceof \mini\Parsing\SQL\AST\BetweenOperation) {
            $this->collectOuterReferences($node->expression, $innerTables, $refs);
            $this->collectOuterReferences($node->low, $innerTables, $refs);
            $this->collectOuterReferences($node->high, $innerTables, $refs);
            return;
        }
    }

    /**
     * Check if WHERE clause contains OR with outer references
     */
    private function hasOrWithOuterReferences(?\mini\Parsing\SQL\AST\ASTNode $node, array $outerRefs): bool
    {
        if ($node === null || empty($outerRefs)) {
            return false;
        }

        if ($node instanceof BinaryOperation && strtoupper($node->operator) === 'OR') {
            // Check if either side references outer tables
            foreach ($outerRefs as $ref) {
                if ($this->nodeReferencesTable($node, $ref['table'])) {
                    return true;
                }
            }
        }

        // Recurse into AND
        if ($node instanceof BinaryOperation && strtoupper($node->operator) === 'AND') {
            return $this->hasOrWithOuterReferences($node->left, $outerRefs)
                || $this->hasOrWithOuterReferences($node->right, $outerRefs);
        }

        return false;
    }

    /**
     * Check if AST node references a specific table
     */
    private function nodeReferencesTable(\mini\Parsing\SQL\AST\ASTNode $node, string $tableName): bool
    {
        if ($node instanceof IdentifierNode) {
            $qualifier = $node->getQualifier()[0] ?? null;
            return $qualifier !== null && strtolower($qualifier) === strtolower($tableName);
        }

        if ($node instanceof BinaryOperation) {
            return $this->nodeReferencesTable($node->left, $tableName)
                || $this->nodeReferencesTable($node->right, $tableName);
        }

        return false;
    }

    /**
     * Try to apply correlated EXISTS using ExistsTable (hash-based, much faster)
     *
     * Works when correlation is simple equi-join(s) and other predicates are non-correlated.
     * Returns null if ExistsTable approach is not applicable.
     */
    private function tryApplyExistsWithExistsTable(
        TableInterface $table,
        ExistsOperation $node,
        SelectStatement $subqueryAst,
        array $outerRefs
    ): ?TableInterface {
        // Only handle simple single-table subqueries for now
        if (!empty($subqueryAst->joins)) {
            return null;
        }

        // Extract correlations and non-correlated predicates from WHERE
        $correlations = [];
        $nonCorrelatedPredicates = [];

        if ($subqueryAst->where === null) {
            return null; // No WHERE clause
        }

        // Flatten AND predicates
        $predicates = [];
        $this->flattenAndPredicates($subqueryAst->where, $predicates);

        $subqueryTableName = $subqueryAst->from->getFullName();
        $subqueryAlias = $subqueryAst->fromAlias ?? $subqueryTableName;

        foreach ($predicates as $pred) {
            // Check if this is an equi-join correlation (inner.col = outer.col)
            if ($pred instanceof BinaryOperation && $pred->operator === '=') {
                $left = $pred->left;
                $right = $pred->right;

                // Check for column = column pattern
                if ($left instanceof IdentifierNode && $right instanceof IdentifierNode) {
                    $leftInfo = $this->parseColumnReference($left, $subqueryAlias);
                    $rightInfo = $this->parseColumnReference($right, $subqueryAlias);

                    // One side should be inner table, other should be outer reference
                    if ($leftInfo && $rightInfo) {
                        $innerCol = null;
                        $outerCol = null;

                        if ($leftInfo['isInner'] && !$rightInfo['isInner']) {
                            $innerCol = $leftInfo['qualified'];
                            $outerCol = $rightInfo['qualified'];
                        } elseif (!$leftInfo['isInner'] && $rightInfo['isInner']) {
                            $innerCol = $rightInfo['qualified'];
                            $outerCol = $leftInfo['qualified'];
                        }

                        if ($innerCol !== null && $outerCol !== null) {
                            $correlations[] = [$outerCol, $innerCol];
                            continue;
                        }
                    }
                }
            }

            // Check if predicate references outer table
            $refsOuter = false;
            foreach ($outerRefs as $ref) {
                if ($this->predicateReferencesColumn($pred, $ref['table'], $ref['column'])) {
                    $refsOuter = true;
                    break;
                }
            }

            if ($refsOuter) {
                // Non-equi-join outer reference - can't use ExistsTable
                return null;
            }

            $nonCorrelatedPredicates[] = $pred;
        }

        if (empty($correlations)) {
            return null; // No equi-join correlations found
        }

        // Build inner table with non-correlated predicates
        $innerTable = $this->getTable($subqueryTableName)->withAlias($subqueryAlias);
        foreach ($nonCorrelatedPredicates as $pred) {
            $innerTable = $this->applyWhereToTableInterface($innerTable, $pred);
        }

        // Map correlation column names to actual column names in the tables
        $outerCols = $table->getColumns();
        $innerCols = $innerTable->getColumns();
        $mappedCorrelations = [];

        foreach ($correlations as [$outerCol, $innerCol]) {
            // Find actual outer column name
            $actualOuterCol = $this->findMatchingColumn($outerCol, $outerCols);
            $actualInnerCol = $this->findMatchingColumn($innerCol, $innerCols);

            if ($actualOuterCol === null || $actualInnerCol === null) {
                return null;
            }

            $mappedCorrelations[] = [$actualOuterCol, $actualInnerCol];
        }

        // Create ExistsTable
        return new \mini\Table\Wrappers\ExistsTable($table, $innerTable, $mappedCorrelations, $node->negated);
    }

    /**
     * Find a column in the table's columns, handling qualified/unqualified names
     *
     * @param string $colName Column name to find (may be qualified like "t1.id" or unqualified like "id")
     * @param array<string, ColumnDef> $columns Table columns
     * @return string|null Actual column name in the table, or null if not found
     */
    private function findMatchingColumn(string $colName, array $columns): ?string
    {
        // Exact match
        if (isset($columns[$colName])) {
            return $colName;
        }

        // If colName is qualified (t1.id), try unqualified (id)
        if (str_contains($colName, '.')) {
            $unqualified = explode('.', $colName)[1];
            if (isset($columns[$unqualified])) {
                return $unqualified;
            }
        }

        // If colName is unqualified, look for qualified match (e.g., "id" matches "t1.id")
        foreach ($columns as $name => $_) {
            if (str_ends_with($name, '.' . $colName)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Parse a column reference to determine if it's inner or outer table
     */
    private function parseColumnReference(IdentifierNode $node, string $innerAlias): ?array
    {
        $name = $node->getName();
        $qualifier = $node->isQualified() ? ($node->getQualifier()[0] ?? null) : null;

        if ($qualifier !== null) {
            $qualified = $qualifier . '.' . $name;
            $isInner = ($qualifier === $innerAlias);
            return ['qualified' => $qualified, 'isInner' => $isInner];
        }

        // Unqualified - assume inner if it exists in inner table
        return ['qualified' => $name, 'isInner' => true];
    }

    /**
     * Check if a predicate references a specific column
     */
    private function predicateReferencesColumn(ASTNode $node, string $table, string $column): bool
    {
        if ($node instanceof IdentifierNode) {
            $name = $node->getName();
            $qualifier = $node->isQualified() ? ($node->getQualifier()[0] ?? null) : null;
            return ($qualifier === $table && $name === $column) ||
                   ($qualifier === null && $name === $column);
        }

        if ($node instanceof BinaryOperation) {
            return $this->predicateReferencesColumn($node->left, $table, $column) ||
                   $this->predicateReferencesColumn($node->right, $table, $column);
        }

        if ($node instanceof UnaryOperation) {
            return $this->predicateReferencesColumn($node->expression, $table, $column);
        }

        return false;
    }

    /**
     * Apply correlated EXISTS using row-by-row evaluation
     *
     * Used when WHERE contains OR with outer references, which can't use
     * the template approach. Less efficient but handles all cases correctly.
     */
    private function applyCorrelatedExistsRowByRow(
        TableInterface $table,
        ExistsOperation $node,
        SelectStatement $subqueryAst,
        array $outerRefs
    ): TableInterface {
        $matchingIds = [];

        foreach ($table as $rowId => $row) {
            // Build outer context for this row
            $outerContext = [];
            foreach ($outerRefs as $ref) {
                $key = $ref['table'] . '.' . $ref['column'];
                // Use qualified column name (e.g., 'u.id') when table is aliased
                $qualifiedCol = $ref['table'] . '.' . $ref['column'];
                $outerContext[$key] = $row->$qualifiedCol ?? $row->{$ref['column']} ?? null;
            }

            // Evaluate EXISTS for this outer row
            $exists = $this->evaluateCorrelatedExists($subqueryAst, $outerContext);

            if ($node->negated) {
                $exists = !$exists;
            }

            if ($exists) {
                $matchingIds[] = $rowId;
            }
        }

        // Build result from matching row IDs
        if (empty($matchingIds)) {
            return $table->except($table);
        }

        // Use in() with the matching IDs - need to get primary key column
        $columns = $table->getColumns();
        $pkColumn = null;
        foreach ($columns as $colName => $colDef) {
            if ($colDef->index === \mini\Table\Types\IndexType::Primary) {
                $pkColumn = $colName;
                break;
            }
        }

        if ($pkColumn !== null) {
            return $table->in($pkColumn, new \mini\Table\Utility\Set($pkColumn, $matchingIds));
        }

        // Fallback: union individual rows (less efficient)
        $result = null;
        foreach ($matchingIds as $id) {
            $rowTable = $table->eq(array_key_first($columns), $id);
            $result = $result === null ? $rowTable : $result->union($rowTable);
        }
        return $result ?? $table->except($table);
    }

    /**
     * Evaluate correlated EXISTS by iterating subquery table and testing WHERE
     */
    private function evaluateCorrelatedExists(SelectStatement $ast, array $outerContext): bool
    {
        $tableName = $ast->from->getFullName();
        $subqueryTable = $this->getTable($tableName);

        foreach ($subqueryTable as $row) {
            // Test WHERE clause with both inner row and outer context
            if ($ast->where === null || $this->evaluateWhereWithContext($ast->where, $row, $outerContext)) {
                return true; // Found a match
            }
        }

        return false;
    }

    /**
     * Evaluate WHERE expression with row data and outer context
     */
    private function evaluateWhereWithContext(\mini\Parsing\SQL\AST\ASTNode $node, object $row, array $outerContext): bool
    {
        if ($node instanceof BinaryOperation) {
            $op = strtoupper($node->operator);

            if ($op === 'AND') {
                return $this->evaluateWhereWithContext($node->left, $row, $outerContext)
                    && $this->evaluateWhereWithContext($node->right, $row, $outerContext);
            }

            if ($op === 'OR') {
                return $this->evaluateWhereWithContext($node->left, $row, $outerContext)
                    || $this->evaluateWhereWithContext($node->right, $row, $outerContext);
            }

            // Comparison operator
            $leftVal = $this->evaluateExprWithContext($node->left, $row, $outerContext);
            $rightVal = $this->evaluateExprWithContext($node->right, $row, $outerContext);

            // SQL: comparisons with NULL return UNKNOWN (false in WHERE context)
            if ($leftVal === null || $rightVal === null) {
                return false;
            }

            return match ($op) {
                '=' => $leftVal == $rightVal,
                '!=', '<>' => $leftVal != $rightVal,
                '<' => $leftVal < $rightVal,
                '<=' => $leftVal <= $rightVal,
                '>' => $leftVal > $rightVal,
                '>=' => $leftVal >= $rightVal,
                default => false,
            };
        }

        return true; // Unknown node type - assume true
    }

    /**
     * Evaluate expression value with row data and outer context
     */
    private function evaluateExprWithContext(\mini\Parsing\SQL\AST\ASTNode $node, object $row, array $outerContext): mixed
    {
        if ($node instanceof LiteralNode) {
            return $node->value;
        }

        if ($node instanceof IdentifierNode) {
            // Check if qualified (e.g., u.id or o.user_id)
            if ($node->isQualified()) {
                $qualifier = $node->getQualifier()[0] ?? null;
                $colName = $node->getName();
                $key = $qualifier . '.' . $colName;

                // Check outer context first (isset is fast but returns false for NULL values)
                if (isset($outerContext[$key]) || array_key_exists($key, $outerContext)) {
                    return $outerContext[$key];
                }
            }

            // Fall back to row column
            $colName = $node->getName();
            return $row->$colName ?? null;
        }

        // For other expressions, use the standard evaluator
        return $this->evaluator->evaluate($node, $row);
    }

    /**
     * Build a Table template with binds for outer references
     */
    private function buildCorrelatedTemplate(SelectStatement $ast, array $outerRefs): BindableTable
    {
        $tableName = $ast->from->getFullName();
        $table = BindableTable::from($this->getTable($tableName));

        // For each outer reference, add a bind
        // We need to modify the WHERE to use binds instead of outer refs
        // For simplicity, we'll build the table with bind predicates

        // Apply non-correlated parts of WHERE first, then add binds for correlated parts
        if ($ast->where !== null) {
            $table = $this->applyWhereWithBinds($table, $ast->where, $outerRefs);
        }

        // Apply ORDER BY, OFFSET, LIMIT
        if ($ast->orderBy) {
            $parts = [];
            foreach ($ast->orderBy as $item) {
                if ($item['column'] instanceof IdentifierNode) {
                    $parts[] = $item['column']->getName() . ' ' . strtoupper($item['direction'] ?? 'ASC');
                }
            }
            if ($parts) {
                $table = $table->order(implode(', ', $parts));
            }
        }

        if ($ast->offset !== null) {
            $table = $table->offset((int)$this->evaluator->evaluate($ast->offset, null));
        }

        if ($ast->limit !== null) {
            $table = $table->limit((int)$this->evaluator->evaluate($ast->limit, null));
        }

        // Project to requested columns
        $columnNames = $this->extractColumnNames($ast->columns);
        if ($columnNames !== null) {
            $table = $table->columns(...$columnNames);
        }

        // Apply DISTINCT (after column projection, per SQL semantics)
        if ($ast->distinct) {
            $table = $table->distinct();
        }

        return $table;
    }

    /**
     * Apply WHERE clause, converting outer references to binds
     */
    private function applyWhereWithBinds(BindableTable $table, \mini\Parsing\SQL\AST\ASTNode $node, array $outerRefs): BindableTable
    {
        // Handle AND - apply both sides
        if ($node instanceof BinaryOperation && strtoupper($node->operator) === 'AND') {
            $table = $this->applyWhereWithBinds($table, $node->left, $outerRefs);
            return $this->applyWhereWithBinds($table, $node->right, $outerRefs);
        }

        // Simple comparison with potential outer reference
        if ($node instanceof BinaryOperation && in_array($node->operator, ['=', '<', '<=', '>', '>='])) {
            // Check if right side is an outer reference
            if ($node->left instanceof IdentifierNode && $node->right instanceof IdentifierNode) {
                $rightQualifier = $node->right->getQualifier()[0] ?? null;

                foreach ($outerRefs as $ref) {
                    if ($rightQualifier !== null &&
                        strtolower($rightQualifier) === strtolower($ref['table']) &&
                        $node->right->getName() === $ref['column']) {
                        // This is a correlated comparison: col = outer.col
                        $column = $node->left->getName();
                        $paramName = ':outer_' . $ref['table'] . '_' . $ref['column'];

                        return match ($node->operator) {
                            '=' => $table->eqBind($column, $paramName),
                            '<' => $table->ltBind($column, $paramName),
                            '<=' => $table->lteBind($column, $paramName),
                            '>' => $table->gtBind($column, $paramName),
                            '>=' => $table->gteBind($column, $paramName),
                        };
                    }
                }
            }

            // Not correlated - apply normally
            if ($node->left instanceof IdentifierNode && $node->right instanceof \mini\Parsing\SQL\AST\LiteralNode) {
                $column = $node->left->getName();
                $value = $this->evaluator->evaluate($node->right, null);
                return match ($node->operator) {
                    '=' => $value === null ? BindableTable::from(EmptyTable::from($table)) : $table->eq($column, $value),
                    '<' => $table->lt($column, $value),
                    '<=' => $table->lte($column, $value),
                    '>' => $table->gt($column, $value),
                    '>=' => $table->gte($column, $value),
                    default => $table,
                };
            }
        }

        // For other cases, just return table unchanged (simplified)
        return $table;
    }

    /**
     * Project a row to only the requested columns
     */
    private function projectRow(object $row, array $columns): object
    {
        // Check for SELECT * (wildcard)
        if (count($columns) === 1) {
            $col = $columns[0];
            if ($col instanceof ColumnNode && $col->expression instanceof IdentifierNode) {
                if ($col->expression->isWildcard()) {
                    return $row; // Return all columns
                }
            }
        }

        $result = new \stdClass();

        foreach ($columns as $col) {
            if (!$col instanceof ColumnNode) {
                continue;
            }

            // Determine output column name
            $name = $col->alias;
            if ($name === null && $col->expression instanceof IdentifierNode) {
                $name = $col->expression->getName();
            }
            if ($name === null) {
                $name = 'col_' . spl_object_id($col);
            }

            // Handle table.* (select all columns from a table)
            if ($col->expression instanceof IdentifierNode && $col->expression->isWildcard()) {
                // For Phase 1 (single table), just copy all properties
                foreach ($row as $prop => $val) {
                    $result->$prop = $val;
                }
                continue;
            }

            // Evaluate the expression
            $result->$name = $this->evaluator->evaluate($col->expression, $row);
        }

        return $result;
    }

    private function executeInsert(InsertStatement $ast): int
    {
        $tableName = $ast->table->getFullName();
        $table = $this->getTable($tableName);

        if ($table === null) {
            throw new \RuntimeException("Table not found: $tableName");
        }

        if (!$table instanceof MutableTableInterface) {
            throw new \RuntimeException("Table '$tableName' does not support INSERT");
        }

        // Handle INSERT INTO ... SELECT ... syntax
        if ($ast->select !== null) {
            return $this->executeInsertSelect($ast, $table);
        }

        // Use provided column names, or fall back to table's column order
        if (!empty($ast->columns)) {
            $columnNames = array_map(fn($col) => $col->getName(), $ast->columns);
        } else {
            $columnNames = array_keys($table->getColumns());
        }

        // Find primary key column for REPLACE
        $pkColumn = null;
        if ($ast->replace) {
            $pkColumn = $this->findPrimaryKeyColumn($table);
        }

        $lastId = 0;

        foreach ($ast->values as $valueRow) {
            $row = [];
            foreach ($valueRow as $i => $valueNode) {
                $colName = $columnNames[$i] ?? "col_$i";
                $row[$colName] = $this->evaluator->evaluate($valueNode, null);
            }

            // REPLACE: delete existing row with same PK first
            if ($ast->replace && $pkColumn !== null && isset($row[$pkColumn])) {
                $this->deleteExistingRow($table, $pkColumn, $row[$pkColumn]);
            }

            $lastId = $table->insert($row);
        }

        // Track for lastInsertId()
        $this->lastInsertId = (string) $lastId;

        return $lastId;
    }

    /**
     * Execute INSERT INTO ... SELECT ... statement
     */
    private function executeInsertSelect(InsertStatement $ast, MutableTableInterface $table): int
    {
        // Halloween protection: materialize the source before inserting.
        // Use provided column names, or fall back to table's column order
        if (!empty($ast->columns)) {
            $columnNames = array_map(fn($col) => $col->getName(), $ast->columns);
        } else {
            $columnNames = array_keys($table->getColumns());
        }

        // Find primary key column for REPLACE
        $pkColumn = null;
        if ($ast->replace) {
            $pkColumn = $this->findPrimaryKeyColumn($table);
        }

        // Writes are logged during the read and applied after it finishes -
        // see PendingWrites. Applying during the scan would feed the new rows
        // back into it and `INSERT INTO t SELECT ... FROM t` would never
        // terminate.
        $writes = new PendingWrites('INSERT ... SELECT', $this->maxMaterializedRows);
        $inserted = 0;

        foreach ($this->executeSelect($ast->select) as $row) {
            $rowArray = [];
            $i = 0;
            foreach ((array)$row as $value) {
                $colName = $columnNames[$i] ?? "col_$i";
                $rowArray[$colName] = $value;
                $i++;
            }

            // REPLACE: the delete is logged ahead of its insert and applied in
            // that order, so the row is replaced rather than duplicated.
            if ($ast->replace && $pkColumn !== null && isset($rowArray[$pkColumn])) {
                $writes->delete($pkColumn, $rowArray[$pkColumn]);
            }

            $writes->insert($rowArray);
            $inserted++;
        }

        // Read is over - now it is safe to write
        $writes->apply($table);

        $this->lastInsertId = (string) $writes->lastInsertId();

        // Rows inserted, not operations applied (a REPLACE logs a delete too)
        return $inserted;
    }

    /**
     * Find the primary key column name for a table
     */
    private function findPrimaryKeyColumn(MutableTableInterface $table): ?string
    {
        foreach ($table->getColumns() as $name => $col) {
            if ($col->index === IndexType::Primary) {
                return $name;
            }
        }
        return null;
    }

    /**
     * Delete a row by primary key value (for REPLACE)
     */
    private function deleteExistingRow(MutableTableInterface $table, string $pkColumn, mixed $pkValue): void
    {
        $query = $table->eq($pkColumn, $pkValue);
        $table->delete($query);
    }

    private function executeUpdate(UpdateStatement $ast): int
    {
        $tableName = $ast->table->getFullName();
        $table = $this->getTable($tableName);

        if ($table === null) {
            throw new \RuntimeException("Table not found: $tableName");
        }

        if (!$table instanceof MutableTableInterface) {
            throw new \RuntimeException("Table '$tableName' does not support UPDATE");
        }

        // Merge updatable scope for model-registered tables.
        // Shallow-clone $ast to avoid mutating the shared SQL parse cache.
        $updatableWhere = $this->getScopeWhere(strtolower($tableName), 'updatable');
        if ($updatableWhere !== null) {
            $ast = clone $ast;
            $ast->where = $ast->where !== null
                ? new BinaryOperation($ast->where, 'AND', $updatableWhere)
                : $updatableWhere;
        }

        // Check if any SET expression needs row context (e.g., SET x = x + 2)
        $needsRowContext = false;
        foreach ($ast->updates as $update) {
            if ($this->expressionNeedsRowContext($update['value'])) {
                $needsRowContext = true;
                break;
            }
        }

        // Apply WHERE filter
        $query = $this->applyWhereToTable($table, $ast->where);

        if ($needsRowContext) {
            // Row-by-row update: iterate over matching rows and compute expressions per-row
            return $this->executeUpdateWithRowContext($table, $query, $ast->updates);
        }

        // Static update: evaluate expressions once without row context
        $changes = [];
        foreach ($ast->updates as $update) {
            $colName = $update['column']->getName();
            $changes[$colName] = $this->evaluator->evaluate($update['value'], null);
        }

        return $table->update($query, $changes);
    }

    /**
     * Check if an expression contains column references (needs row context to evaluate)
     */
    private function expressionNeedsRowContext(ASTNode $node): bool
    {
        // IdentifierNode is a column reference (e.g., 'x' in SET x = x + 2)
        if ($node instanceof IdentifierNode) {
            return true;
        }

        // ColumnNode wraps IdentifierNode for qualified columns (e.g., 't.x')
        if ($node instanceof ColumnNode) {
            return true;
        }

        // BinaryOperation: check both operands
        if ($node instanceof BinaryOperation) {
            return $this->expressionNeedsRowContext($node->left)
                || $this->expressionNeedsRowContext($node->right);
        }

        // UnaryOperation: check operand
        if ($node instanceof UnaryOperation) {
            return $this->expressionNeedsRowContext($node->expression);
        }

        // FunctionCall: check arguments
        if ($node instanceof FunctionCall) {
            foreach ($node->arguments as $arg) {
                if ($this->expressionNeedsRowContext($arg)) {
                    return true;
                }
            }
            return false;
        }

        // Literals don't need row context
        return false;
    }

    /**
     * Execute UPDATE with row-by-row evaluation for self-referencing expressions
     */
    private function executeUpdateWithRowContext(MutableTableInterface $table, TableInterface $query, array $updates): int
    {
        $pkColumn = null;

        // Find primary key column for targeted updates
        foreach ($table->getColumns() as $name => $col) {
            if ($col->index === IndexType::Primary) {
                $pkColumn = $name;
                break;
            }
        }

        // Log every update during the scan and apply them afterwards - writing
        // mid-scan would let an updated row change the rows still to be read.
        $writes = new PendingWrites('UPDATE', $this->maxMaterializedRows);

        // Rows without a primary key are addressed by rowid, which only the
        // built-in tables expose. Fail before doing any work rather than
        // part-way through applying.
        if ($pkColumn === null && !($table instanceof InMemoryTable || $table instanceof ArrayTable)) {
            throw new \RuntimeException(
                'UPDATE with self-referencing expressions requires a primary key, InMemoryTable, or ArrayTable'
            );
        }

        foreach ($query as $rowid => $row) {
            $changes = [];
            foreach ($updates as $update) {
                $colName = $update['column']->getName();
                $changes[$colName] = $this->evaluator->evaluate($update['value'], $row);
            }

            if ($pkColumn !== null) {
                $pkValue = $row->{$pkColumn} ?? null;
                if ($pkValue !== null) {
                    $writes->update($pkColumn, $pkValue, $changes);
                }
            } else {
                // InMemoryTable and ArrayTable accept eq() on the rowid they yield as key
                $writes->update('_rowid_', $rowid, $changes);
            }
        }

        return $writes->apply($table);
    }

    private function executeDelete(DeleteStatement $ast): int
    {
        $tableName = $ast->table->getFullName();
        $table = $this->getTable($tableName);

        if ($table === null) {
            throw new \RuntimeException("Table not found: $tableName");
        }

        if (!$table instanceof MutableTableInterface) {
            throw new \RuntimeException("Table '$tableName' does not support DELETE");
        }

        // Merge deletable scope for model-registered tables.
        // Shallow-clone $ast to avoid mutating the shared SQL parse cache.
        $deletableWhere = $this->getScopeWhere(strtolower($tableName), 'deletable');
        if ($deletableWhere !== null) {
            $ast = clone $ast;
            $ast->where = $ast->where !== null
                ? new BinaryOperation($ast->where, 'AND', $deletableWhere)
                : $deletableWhere;
        }

        $query = $this->applyWhereToTable($table, $ast->where);
        return $table->delete($query);
    }

    /**
     * Execute CREATE TABLE - creates a table with the given schema
     *
     * TEMPORARY tables require a session context. Use $vdb->session()->exec() for temp tables.
     */
    private function executeCreateTable(CreateTableStatement $ast): int
    {
        // TEMPORARY tables require a session
        if ($ast->temporary) {
            throw new \RuntimeException(
                "CREATE TEMPORARY TABLE requires a session. Use \$vdb->session()->exec() instead."
            );
        }

        $tableName = $ast->table->getName();

        // Check IF NOT EXISTS
        if ($this->tableExists($tableName)) {
            if ($ast->ifNotExists) {
                return 0;
            }
            throw new \RuntimeException("Table already exists: $tableName");
        }

        // Convert AST column definitions to ColumnDef objects
        $columnDefs = [];
        foreach ($ast->columns as $col) {
            $columnDefs[] = $this->astColumnToColumnDef($col, $ast->constraints);
        }

        // Create and register the table
        $tableClass = $this->tableClass;
        $table = new $tableClass(...$columnDefs);
        $this->registerTable($tableName, $table);

        return 0;
    }

    /**
     * Execute DROP TABLE - removes a registered table
     */
    private function executeDropTable(DropTableStatement $ast): int
    {
        $tableName = $ast->table->getName();

        if (!$this->tableExists($tableName)) {
            if ($ast->ifExists) {
                return 0;
            }
            throw new \RuntimeException("Table not found: $tableName");
        }

        unset($this->tables[strtolower($tableName)]);
        return 0;
    }

    /**
     * Convert AST ColumnDefinition to ColumnDef
     *
     * @param ColumnDefinition $col AST column definition
     * @param array $constraints Table-level constraints for primary key detection
     */
    private function astColumnToColumnDef(ColumnDefinition $col, array $constraints): ColumnDef
    {
        // Determine column type from SQL data type
        $type = $this->sqlTypeToColumnType($col->dataType);

        // Determine index type
        $indexType = IndexType::None;
        if ($col->primaryKey) {
            $indexType = IndexType::Primary;
        } elseif ($col->unique) {
            $indexType = IndexType::Unique;
        }

        // Check table-level constraints for PRIMARY KEY
        foreach ($constraints as $constraint) {
            if ($constraint->constraintType === 'PRIMARY KEY'
                && count($constraint->columns) === 1
                && $constraint->columns[0] === $col->name
            ) {
                $indexType = IndexType::Primary;
            }
        }

        // Type parameters (e.g., scale for DECIMAL)
        $typeParams = [];
        if ($col->scale !== null) {
            $typeParams['scale'] = $col->scale;
        }

        return new ColumnDef($col->name, $type, $indexType, $typeParams);
    }

    /**
     * Map SQL data type string to ColumnType enum
     */
    private function sqlTypeToColumnType(?string $sqlType): ColumnType
    {
        if ($sqlType === null) {
            return ColumnType::Text; // SQLite style - default to text
        }

        return match (strtoupper($sqlType)) {
            'INTEGER', 'INT', 'SMALLINT', 'TINYINT', 'BIGINT' => ColumnType::Int,
            'REAL', 'FLOAT', 'DOUBLE' => ColumnType::Float,
            'DECIMAL', 'NUMERIC' => ColumnType::Decimal,
            'TEXT', 'VARCHAR', 'CHAR', 'CLOB' => ColumnType::Text,
            'BLOB', 'BINARY', 'VARBINARY' => ColumnType::Binary,
            'DATE' => ColumnType::Date,
            'TIME' => ColumnType::Time,
            'DATETIME', 'TIMESTAMP' => ColumnType::DateTime,
            default => ColumnType::Text,
        };
    }

    /**
     * Apply a WHERE clause AST to a TableInterface using table methods
     */
    private function applyWhereToTableInterface(TableInterface $table, \mini\Parsing\SQL\AST\ASTNode $node): TableInterface
    {
        // Binary AND: flatten, push simple predicates first, then filter with complex ones
        if ($node instanceof BinaryOperation && strtoupper($node->operator) === 'AND') {
            // Flatten AND chain
            $predicates = [];
            $this->flattenAndPredicates($node, $predicates);

            // Separate into pushable and complex
            $pushable = [];
            $complex = [];
            foreach ($predicates as $pred) {
                if ($this->canPushPredicate($pred)) {
                    $pushable[] = $pred;
                } else {
                    $complex[] = $pred;
                }
            }

            // Apply pushable predicates first (reduces row count)
            foreach ($pushable as $pred) {
                $table = $this->applySinglePredicate($table, $pred);
            }

            // Then apply complex predicates on the reduced set
            // EXISTS gets special handling via applyExistsToTable (much faster)
            foreach ($complex as $pred) {
                if ($pred instanceof ExistsOperation) {
                    $table = $this->applyExistsToTable($table, $pred);
                } else {
                    $table = $this->filterByExpression($table, $pred);
                }
            }

            return $table;
        }

        // Binary OR: use table's or() method with predicates, or fall back to row-by-row
        if ($node instanceof BinaryOperation && strtoupper($node->operator) === 'OR') {
            try {
                $leftPredicate = $this->buildPredicateFromAst($node->left);
                $rightPredicate = $this->buildPredicateFromAst($node->right);
                return $table->or($leftPredicate, $rightPredicate);
            } catch (\RuntimeException $e) {
                // Can't convert to predicates - evaluate row-by-row
                return $this->filterByExpression($table, $node);
            }
        }

        // Simple comparison: column op value
        if ($node instanceof BinaryOperation) {
            $op = $node->operator;

            // Optimization: constant folding (both sides are literals)
            // WHERE 1 = 1 → always true, WHERE 1 = 0 → always false
            if ($node->left instanceof LiteralNode && $node->right instanceof LiteralNode
                && in_array($op, ['=', '!=', '<>', '<', '<=', '>', '>='], true)
            ) {
                $leftVal = $node->left->value;
                $rightVal = $node->right->value;

                // NULL comparisons are always UNKNOWN (no rows match)
                if ($leftVal === null || $rightVal === null) {
                    return EmptyTable::from($table);
                }

                $result = match ($op) {
                    '=' => $leftVal == $rightVal,
                    '!=', '<>' => $leftVal != $rightVal,
                    '<' => $leftVal < $rightVal,
                    '<=' => $leftVal <= $rightVal,
                    '>' => $leftVal > $rightVal,
                    '>=' => $leftVal >= $rightVal,
                    default => null,
                };

                if ($result === true) {
                    return $table; // No filter needed
                }
                if ($result === false) {
                    return EmptyTable::from($table);
                }
            }

            // Optimization: null propagation in arithmetic
            // WHERE col + NULL > 5 → always NULL → EmptyTable
            if ($this->expressionContainsNull($node->left) || $this->expressionContainsNull($node->right)) {
                return EmptyTable::from($table);
            }

            // Optimization: flip literal on left - 7 < col → col > 7
            if ($node->left instanceof LiteralNode && $node->right instanceof IdentifierNode
                && in_array($op, ['=', '!=', '<>', '<', '<=', '>', '>='], true)
            ) {
                $column = $this->resolveColumnForTable($this->buildQualifiedColumnName($node->right), $table);
                $value = $node->left->value;
                $flippedOp = $this->flipComparisonOp($op);

                // Every comparison against NULL is UNKNOWN, not just `= NULL`.
                if ($value === null) {
                    return EmptyTable::from($table);
                }

                return match ($flippedOp) {
                    '=' => $table->eq($column, $value),
                    '<' => $table->lt($column, $value),
                    '<=' => $table->lte($column, $value),
                    '>' => $table->gt($column, $value),
                    '>=' => $table->gte($column, $value),
                    '!=', '<>' => $this->filterByExpression($table, $node),
                    default => $this->filterByExpression($table, $node),
                };
            }

            // Check if we can push to table (column = value pattern)
            // Value can be literal, bound placeholder, or an UNCORRELATED subquery.
            // A correlated subquery yields a different value for every outer row,
            // so hoisting it out and evaluating it once silently answers a
            // different question ("WHERE total > (global avg)" instead of
            // "WHERE total > (avg for this row's user)").
            $isValueNode = fn($n) => $n instanceof LiteralNode
                || ($n instanceof PlaceholderNode && $n->isBound)
                || ($n instanceof SubqueryNode && !$this->subqueryIsCorrelated($n));

            $canPushToTable = $node->left instanceof IdentifierNode && $isValueNode($node->right);

            if ($canPushToTable) {
                $column = $this->resolveColumnForTable($this->buildQualifiedColumnName($node->left), $table);
                // Evaluate right side - this handles both literals and subqueries
                $value = $this->evaluator->evaluate($node->right, null);

                // SQL standard: *any* comparison against NULL is UNKNOWN, so no
                // rows match. This covers `col = NULL` and also a scalar
                // subquery that came back NULL - `col < (SELECT ...)` used to
                // reach Predicate::lt() and leak a PHP TypeError.
                // For IS NULL semantics, use the IsNullOperation branch instead
                if ($value === null) {
                    return EmptyTable::from($table);
                }

                return match ($op) {
                    '=' => $table->eq($column, $value),
                    '<' => $table->lt($column, $value),
                    '<=' => $table->lte($column, $value),
                    '>' => $table->gt($column, $value),
                    '>=' => $table->gte($column, $value),
                    // != and <> can't use except() - it would include NULL rows incorrectly
                    // NULL <> 100 is UNKNOWN (not TRUE), so row-by-row evaluation is needed
                    '!=', '<>' => $this->filterByExpression($table, $node),
                    default => throw new \RuntimeException("Unsupported operator: $op"),
                };
            }

            // Try to simplify arithmetic expressions: (col + 5) > 7  →  col > 2
            $simplified = $this->trySimplifyArithmeticComparison($node);
            if ($simplified !== null) {
                $column = $this->resolveColumnForTable($simplified['column'], $table);
                return match ($simplified['op']) {
                    '=' => $simplified['value'] === null
                        ? EmptyTable::from($table)
                        : $table->eq($column, $simplified['value']),
                    '<' => $table->lt($column, $simplified['value']),
                    '<=' => $table->lte($column, $simplified['value']),
                    '>' => $table->gt($column, $simplified['value']),
                    '>=' => $table->gte($column, $simplified['value']),
                    '!=', '<>' => $this->filterByExpression($table, $node),
                    default => $this->filterByExpression($table, $node),
                };
            }

            // Fall back to expression-based filtering (for CASE, expressions, etc.)
            return $this->filterByExpression($table, $node);
        }

        // LIKE operation
        if ($node instanceof \mini\Parsing\SQL\AST\LikeOperation) {
            // Only a bare column can be pushed down to the table; an expression
            // on the left (UPPER(email) LIKE ..., a || b LIKE ...) is evaluated
            // row by row, the same way IN handles a non-column left side.
            if (!$node->left instanceof IdentifierNode) {
                return $this->filterByExpression($table, $node);
            }
            // The pattern is hoisted out of the row loop, so it has to be
            // row-independent. `col LIKE other_col` is evaluated row by row.
            if (!$this->expressionIsConstant($node->pattern)) {
                return $this->filterByExpression($table, $node);
            }
            $column = $this->resolveColumnForTable($this->buildQualifiedColumnName($node->left), $table);
            $pattern = $this->evaluator->evaluate($node->pattern, null);
            // NULL pattern makes both LIKE and NOT LIKE UNKNOWN for every row
            if ($pattern === null) {
                return EmptyTable::from($table);
            }
            if (!$node->negated) {
                return $table->like($column, $pattern);
            }
            // NOT LIKE is UNKNOWN - not TRUE - when the column is NULL, so the
            // NULL rows belong to neither LIKE nor NOT LIKE. Excluding the
            // matches from the table would keep them, so evaluate row by row.
            return $this->filterByExpression($table, $node);
        }

        // IS NULL operation
        if ($node instanceof \mini\Parsing\SQL\AST\IsNullOperation) {
            // IS NULL applies to any expression, not just a column. Only a bare
            // column can be pushed down to the table; anything else
            // (COALESCE(a, b) IS NULL, (a || b) IS NOT NULL, ...) is evaluated
            // row by row.
            if (!$node->expression instanceof IdentifierNode) {
                return $this->filterByExpression($table, $node);
            }
            $column = $this->resolveColumnForTable($this->buildQualifiedColumnName($node->expression), $table);
            $nullRows = $table->eq($column, null);
            return $node->negated ? $table->except($nullRows) : $nullRows;
        }

        // IN operation
        if ($node instanceof InOperation) {
            // If left side is not a column, fall back to row-by-row evaluation
            if (!$node->left instanceof IdentifierNode) {
                return $this->filterByExpression($table, $node);
            }
            // A correlated IN-subquery produces a different set per outer row and
            // cannot be materialised once - evaluate it row by row instead.
            if ($node->isSubquery() && $this->subqueryIsCorrelated($node->values)) {
                return $this->filterByExpression($table, $node);
            }
            // A literal list is hoisted out of the row loop, so every element has
            // to be row-independent. `col IN (other_col, 1)` is done row by row.
            if (!$node->isSubquery()) {
                foreach ($node->values as $valueNode) {
                    if (!$this->expressionIsConstant($valueNode)) {
                        return $this->filterByExpression($table, $node);
                    }
                }
            }
            $column = $this->resolveColumnForTable($this->buildQualifiedColumnName($node->left), $table);

            if (!$node->negated) {
                if ($node->isSubquery()) {
                    $set = $this->executeSubqueryAsSet($node->values, $column);
                } else {
                    // NULLs are dropped: they can only make IN UNKNOWN, never
                    // TRUE, and Set would turn them into empty strings.
                    $values = [];
                    foreach ($node->values as $valueNode) {
                        $value = $this->evaluator->evaluate($valueNode, null);
                        if ($value !== null) {
                            $values[] = $value;
                        }
                    }
                    $set = new \mini\Table\Utility\Set($column, $values);
                }

                return $this->applyInWithIndexAwareness($table, $column, $set);
            }

            // NOT IN follows three-valued logic: it is UNKNOWN - not TRUE - when
            // the left operand is NULL, and UNKNOWN for every non-matching row
            // when the value list contains a NULL. Those rows belong to neither
            // IN nor NOT IN, but $table->except($matches) would keep them.
            //
            // The values are read directly rather than through Set, because Set
            // stores members as string array keys and so cannot represent NULL
            // at all - it would silently turn one into an empty string.
            $values = $node->isSubquery()
                ? $this->subqueryScalarValues($node->values)
                : array_map(fn($v) => $this->evaluator->evaluate($v, null), $node->values);

            $listValues = [];
            $listHasNull = false;
            foreach ($values as $value) {
                if ($value === null) {
                    $listHasNull = true;
                } else {
                    $listValues[] = $value;
                }
            }

            // Empty list: NOT IN () is TRUE for every row, NULL ones included.
            if ($listValues === [] && !$listHasNull) {
                return $table;
            }

            // A NULL anywhere in the list makes every non-matching row UNKNOWN,
            // and matching rows are FALSE - so nothing can be TRUE.
            if ($listHasNull) {
                return EmptyTable::from($table);
            }

            $result = new \mini\Table\InMemoryTable(...array_values($table->getColumns()));
            foreach ($table as $row) {
                $left = $row->$column ?? null;
                if ($left === null) {
                    continue; // UNKNOWN
                }
                foreach ($listValues as $value) {
                    if (ExpressionEvaluator::valuesEqual($left, $value)) {
                        continue 2; // FALSE
                    }
                }
                $result->insert((array)$row);
            }

            return $result;
        }

        // EXISTS operation
        if ($node instanceof ExistsOperation) {
            return $this->applyExistsToTable($table, $node);
        }

        // BETWEEN operation (NOT BETWEEN is rewritten by AstOptimizer to col < low OR col > high)
        if ($node instanceof \mini\Parsing\SQL\AST\BetweenOperation) {
            // Can only push to table if expression is a simple column and bounds are literals
            if (!$node->negated
                && $node->expression instanceof IdentifierNode
                && $node->low instanceof LiteralNode
                && $node->high instanceof LiteralNode
            ) {
                $column = $this->buildQualifiedColumnName($node->expression);
                $low = $this->evaluator->evaluate($node->low, null);
                $high = $this->evaluator->evaluate($node->high, null);
                // A NULL bound makes BETWEEN UNKNOWN for every row
                if ($low === null || $high === null) {
                    return EmptyTable::from($table);
                }
                return $table->gte($column, $low)->lte($column, $high);
            }
            // Fall back to row-by-row evaluation
            return $this->filterByExpression($table, $node);
        }

        // NOT expression: evaluate inner expression and exclude those rows
        if ($node instanceof UnaryOperation && strtoupper($node->operator) === 'NOT') {
            $matching = $this->applyWhereToTableInterface($table, $node->expression);
            return $table->except($matching);
        }

        // ALL/ANY quantified comparison
        if ($node instanceof \mini\Parsing\SQL\AST\QuantifiedComparisonNode) {
            return $this->applyQuantifiedComparison($table, $node);
        }

        // Literal value (e.g., from NOT IN () optimization returning 1)
        if ($node instanceof LiteralNode) {
            // Truthy literal: return all rows; falsy: return empty
            if ($node->value) {
                return $table;
            }
            // Return empty result - filter with always-false condition
            return $this->filterByExpression($table, $node);
        }

        throw new \RuntimeException("Unsupported WHERE expression: " . get_class($node));
    }

    /**
     * Check if a predicate can be pushed to the table interface
     *
     * Returns true for simple predicates: column op literal, IN, BETWEEN, IS NULL, LIKE
     */
    private function canPushPredicate(\mini\Parsing\SQL\AST\ASTNode $node): bool
    {
        // Simple comparisons: column op value or value op column
        if ($node instanceof BinaryOperation) {
            $op = strtoupper($node->operator);
            if (in_array($op, ['=', '!=', '<>', '<', '<=', '>', '>='], true)) {
                // Check for column op literal or literal op column
                $hasColumn = $node->left instanceof IdentifierNode || $node->right instanceof IdentifierNode;
                $hasLiteral = $node->left instanceof LiteralNode || $node->right instanceof LiteralNode
                    || ($node->left instanceof PlaceholderNode && $node->left->isBound)
                    || ($node->right instanceof PlaceholderNode && $node->right->isBound);
                return $hasColumn && $hasLiteral;
            }
            return false;
        }

        // IN with column and literal values (not subquery for simplicity)
        if ($node instanceof InOperation) {
            return $node->left instanceof IdentifierNode && !$node->isSubquery();
        }

        // BETWEEN with column and literal bounds
        if ($node instanceof \mini\Parsing\SQL\AST\BetweenOperation) {
            return $node->expression instanceof IdentifierNode
                && $node->low instanceof LiteralNode
                && $node->high instanceof LiteralNode;
        }

        // IS NULL / IS NOT NULL
        if ($node instanceof IsNullOperation) {
            return $node->expression instanceof IdentifierNode;
        }

        // LIKE with column and pattern
        if ($node instanceof LikeOperation) {
            return $node->left instanceof IdentifierNode
                && ($node->pattern instanceof LiteralNode || ($node->pattern instanceof PlaceholderNode && $node->pattern->isBound));
        }

        return false;
    }

    /**
     * Apply a single pushable predicate to the table
     *
     * This is a subset of applyWhereToTableInterface that only handles
     * predicates that canPushPredicate() returns true for.
     */
    private function applySinglePredicate(TableInterface $table, \mini\Parsing\SQL\AST\ASTNode $node): TableInterface
    {
        // Delegate to the full handler - it will handle these cases
        // We know it won't recurse into AND because canPushPredicate returns false for AND
        return $this->applyWhereToTableInterface($table, $node);
    }

    /**
     * Filter table rows by evaluating an expression against each row
     *
     * Used when WHERE contains expressions that can't be pushed to the table
     * (e.g., CASE expressions, complex arithmetic, etc.)
     */
    private function filterByExpression(TableInterface $table, \mini\Parsing\SQL\AST\ASTNode $condition): TableInterface
    {

        $columns = $table->getColumns();
        $filteredRows = [];

        foreach ($table as $row) {
            // Evaluate the condition against this row
            if ($this->evaluator->evaluateAsBool($condition, $row)) {
                $filteredRows[] = $row;
            }
        }

        // Build result table with same schema
        $result = new \mini\Table\InMemoryTable(...array_values($columns));
        foreach ($filteredRows as $row) {
            $result->insert((array)$row);
        }

        return $result;
    }

    /**
     * Apply IN filter with index-aware optimization
     *
     * If the outer table has an index on the IN column but the set doesn't,
     * we iterate the set values and probe the outer table using eq().
     * This leverages indexes on the outer table for O(k log n) instead of O(n)
     * where k is set size and n is outer table size.
     */
    private function applyInWithIndexAwareness(
        TableInterface $table,
        string $column,
        SetInterface $set
    ): TableInterface {
        // Check if outer table has index on the IN column
        $outerCols = $table->getColumns();
        $outerHasIndex = isset($outerCols[$column])
            && $outerCols[$column]->index !== \mini\Table\Types\IndexType::None;

        // Check if set has useful index on its first column
        $setCols = $set->getColumns();
        $setColNames = array_keys($setCols);
        $setFirstCol = $setColNames[0] ?? null;
        $setHasIndex = $setFirstCol !== null
            && $setCols[$setFirstCol]->index !== \mini\Table\Types\IndexType::None;

        // Optimization: iterate set and probe indexed outer table
        if ($outerHasIndex && !$setHasIndex) {
            // Collect matching rows by probing outer table for each set value
            // Use content-based deduplication since row IDs may not be unique across eq() calls
            $results = [];
            $seenValues = [];
            $seenRows = [];

            foreach ($set as $setRow) {
                $value = $setRow->$setFirstCol ?? null;

                // Skip duplicate values in set
                $key = serialize($value);
                if (isset($seenValues[$key])) {
                    continue;
                }
                $seenValues[$key] = true;

                // Probe outer table using index
                foreach ($table->eq($column, $value) as $row) {
                    // Deduplicate by row content (handles join tables with regenerated IDs)
                    $rowKey = serialize($row);
                    if (!isset($seenRows[$rowKey])) {
                        $seenRows[$rowKey] = true;
                        $results[] = $row;
                    }
                }
            }

            // Return as GeneratorTable to preserve immutability
            return new \mini\Table\GeneratorTable(
                function () use ($results) {
                    foreach ($results as $id => $row) {
                        yield $id => $row;
                    }
                },
                ...array_values($outerCols)
            );
        }

        // Default: use standard in() which iterates outer and checks set membership
        return $table->in($column, $set);
    }

    /**
     * Apply ALL/ANY quantified comparison
     *
     * - ALL: row matches if comparison is true for ALL values in subquery
     * - ANY: row matches if comparison is true for at least one value in subquery
     *
     * Empty subquery:
     * - ALL: returns true (vacuous truth)
     * - ANY: returns false (no match possible)
     */
    private function applyQuantifiedComparison(
        TableInterface $table,
        \mini\Parsing\SQL\AST\QuantifiedComparisonNode $node
    ): TableInterface {
        // Execute subquery to get comparison values
        $subqueryRows = iterator_to_array($this->executeSelect($node->subquery->query));

        // Extract first column values from subquery
        $subqueryValues = [];
        foreach ($subqueryRows as $row) {
            $props = get_object_vars($row);
            $subqueryValues[] = reset($props); // First column value
        }

        // Handle empty subquery
        if (empty($subqueryValues)) {
            if ($node->quantifier === 'ALL') {
                // ALL with empty set is vacuously true - return all rows
                return $table;
            } else {
                // ANY with empty set has no match - return empty
                return EmptyTable::from($table);
            }
        }

        // Filter rows by quantified comparison
        $filteredRows = [];
        $columns = null;

        foreach ($table as $row) {
            if ($columns === null) {
                $columns = $table->getColumns();
            }

            // Evaluate left side for this row
            $leftValue = $this->evaluator->evaluate($node->left, $row);

            // Check against all subquery values based on quantifier
            $matches = $node->quantifier === 'ALL'
                ? $this->compareAll($leftValue, $node->operator, $subqueryValues)
                : $this->compareAny($leftValue, $node->operator, $subqueryValues);

            if ($matches) {
                $filteredRows[] = $row;
            }
        }

        // Build result table
        $result = new \mini\Table\InMemoryTable(...array_values($columns ?? []));
        foreach ($filteredRows as $row) {
            $result->insert((array)$row);
        }

        return $result;
    }

    /**
     * Check if comparison is true for ALL values (ALL quantifier)
     */
    private function compareAll(mixed $left, string $op, array $values): bool
    {
        foreach ($values as $right) {
            if (!$this->compare($left, $op, $right)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if comparison is true for at least one value (ANY quantifier)
     */
    private function compareAny(mixed $left, string $op, array $values): bool
    {
        foreach ($values as $right) {
            if ($this->compare($left, $op, $right)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Perform a single comparison
     */
    private function compare(mixed $left, string $op, mixed $right): bool
    {
        return match ($op) {
            '=' => $left == $right,
            '!=' , '<>' => $left != $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            default => throw new \RuntimeException("Unsupported comparison operator: $op"),
        };
    }

    /**
     * Try to simplify arithmetic comparison to pushable form
     *
     * Handles patterns like:
     * - (col + 5) > 7  →  col > 2
     * - (col - 3) >= 10  →  col >= 13
     * - (5 + col) < 10  →  col < 5
     * - (10 - col) > 3  →  col < 7  (operator flips!)
     * - (col * 2) > 10  →  col > 5
     * - (col / 2) >= 5  →  col >= 10
     *
     * @return array{column: string, op: string, value: mixed}|null
     */
    private function trySimplifyArithmeticComparison(BinaryOperation $node): ?array
    {
        $cmpOp = $node->operator;
        if (!in_array($cmpOp, ['=', '!=', '<>', '<', '<=', '>', '>='], true)) {
            return null;
        }

        // Right side must be a constant
        if (!$node->right instanceof LiteralNode) {
            return null;
        }
        $rightValue = $node->right->value;

        // Left side must be arithmetic: (col OP const) or (const OP col)
        if (!$node->left instanceof BinaryOperation) {
            return null;
        }
        $arith = $node->left;
        $arithOp = $arith->operator;

        if (!in_array($arithOp, ['+', '-', '*', '/'], true)) {
            return null;
        }

        // Pattern 1: col OP const (e.g., col + 5)
        if ($arith->left instanceof IdentifierNode && $arith->right instanceof LiteralNode) {
            $column = $this->buildQualifiedColumnName($arith->left);
            $constValue = $arith->right->value;

            return $this->solveForColumn($column, $arithOp, $constValue, $cmpOp, $rightValue, false);
        }

        // Pattern 2: const OP col (e.g., 5 + col, 10 - col)
        if ($arith->left instanceof LiteralNode && $arith->right instanceof IdentifierNode) {
            $column = $this->buildQualifiedColumnName($arith->right);
            $constValue = $arith->left->value;

            return $this->solveForColumn($column, $arithOp, $constValue, $cmpOp, $rightValue, true);
        }

        return null;
    }

    /**
     * Solve for column in arithmetic comparison
     *
     * @param string $column Column name
     * @param string $arithOp Arithmetic operator (+, -, *, /)
     * @param mixed $constValue Constant in arithmetic expression
     * @param string $cmpOp Comparison operator
     * @param mixed $rightValue Right side of comparison
     * @param bool $constOnLeft Whether constant is on left (e.g., 5 - col)
     * @return array{column: string, op: string, value: mixed}|null
     */
    private function solveForColumn(
        string $column,
        string $arithOp,
        mixed $constValue,
        string $cmpOp,
        mixed $rightValue,
        bool $constOnLeft
    ): ?array {
        // Only handle numeric operations
        if (!is_numeric($constValue) || !is_numeric($rightValue)) {
            return null;
        }

        $solvedValue = null;
        $solvedOp = $cmpOp;

        if ($constOnLeft) {
            // const OP col CMP right  →  solve for col
            switch ($arithOp) {
                case '+':
                    // const + col CMP right  →  col CMP (right - const)
                    $solvedValue = $rightValue - $constValue;
                    break;
                case '-':
                    // const - col CMP right  →  -col CMP (right - const)  →  col FLIP(CMP) (const - right)
                    $solvedValue = $constValue - $rightValue;
                    $solvedOp = $this->flipComparisonOp($cmpOp);
                    break;
                case '*':
                    if ($constValue == 0) return null;
                    // const * col CMP right  →  col CMP (right / const)
                    $solvedValue = $rightValue / $constValue;
                    if ($constValue < 0) {
                        $solvedOp = $this->flipComparisonOp($cmpOp);
                    }
                    break;
                case '/':
                    // const / col CMP right - too complex, skip
                    return null;
            }
        } else {
            // col OP const CMP right  →  solve for col
            switch ($arithOp) {
                case '+':
                    // col + const CMP right  →  col CMP (right - const)
                    $solvedValue = $rightValue - $constValue;
                    break;
                case '-':
                    // col - const CMP right  →  col CMP (right + const)
                    $solvedValue = $rightValue + $constValue;
                    break;
                case '*':
                    if ($constValue == 0) return null;
                    // col * const CMP right  →  col CMP (right / const)
                    $solvedValue = $rightValue / $constValue;
                    if ($constValue < 0) {
                        $solvedOp = $this->flipComparisonOp($cmpOp);
                    }
                    break;
                case '/':
                    if ($constValue == 0) return null;
                    // col / const CMP right  →  col CMP (right * const)
                    $solvedValue = $rightValue * $constValue;
                    if ($constValue < 0) {
                        $solvedOp = $this->flipComparisonOp($cmpOp);
                    }
                    break;
            }
        }

        if ($solvedValue === null) {
            return null;
        }

        // Normalize to integer if it's a whole number
        if (is_float($solvedValue) && floor($solvedValue) == $solvedValue) {
            $solvedValue = (int) $solvedValue;
        }

        return [
            'column' => $column,
            'op' => $solvedOp,
            'value' => $solvedValue,
        ];
    }

    /**
     * Flip comparison operator (for negative multiplier or subtraction from constant)
     */
    private function flipComparisonOp(string $op): string
    {
        return match ($op) {
            '<' => '>',
            '<=' => '>=',
            '>' => '<',
            '>=' => '<=',
            '=', '!=', '<>' => $op, // equality doesn't flip
            default => $op,
        };
    }

    /**
     * Check if an arithmetic expression contains NULL literal
     *
     * NULL propagates through arithmetic: col + NULL, 1 * NULL, etc. are all NULL.
     * This means any comparison with such expression is UNKNOWN → no rows match.
     */
    private function expressionContainsNull(\mini\Parsing\SQL\AST\ASTNode $node): bool
    {
        // Direct NULL literal
        if ($node instanceof LiteralNode && $node->value === null) {
            return true;
        }

        // Arithmetic with NULL propagates
        if ($node instanceof BinaryOperation && in_array($node->operator, ['+', '-', '*', '/', '%'], true)) {
            return $this->expressionContainsNull($node->left)
                || $this->expressionContainsNull($node->right);
        }

        // Unary minus on NULL
        if ($node instanceof UnaryOperation && $node->operator === '-') {
            return $this->expressionContainsNull($node->expression);
        }

        return false;
    }

    /**
     * Can this expression be evaluated once, without a row?
     *
     * Pushdown paths that hoist a sub-expression out of the row loop (the LIKE
     * pattern, an IN value list) evaluate it with a null row. A column reference
     * there throws "Cannot evaluate column reference without row context", so
     * those callers use this to fall back to row-by-row evaluation instead.
     */
    private function expressionIsConstant(\mini\Parsing\SQL\AST\ASTNode $node): bool
    {
        if ($node instanceof LiteralNode) {
            return true;
        }

        if ($node instanceof PlaceholderNode) {
            return $node->isBound;
        }

        if ($node instanceof BinaryOperation) {
            return $this->expressionIsConstant($node->left)
                && $this->expressionIsConstant($node->right);
        }

        if ($node instanceof UnaryOperation) {
            return $this->expressionIsConstant($node->expression);
        }

        if ($node instanceof \mini\Parsing\SQL\AST\FunctionCallNode) {
            foreach ($node->arguments as $argument) {
                if (!$this->expressionIsConstant($argument)) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * Build a Predicate from an AST node (for OR clauses)
     */
    private function buildPredicateFromAst(\mini\Parsing\SQL\AST\ASTNode $node): \mini\Table\Predicate
    {
        // Binary AND: chain conditions
        if ($node instanceof BinaryOperation && strtoupper($node->operator) === 'AND') {
            $left = $this->buildPredicateFromAst($node->left);
            // For AND, we need to chain conditions on the same predicate
            return $this->appendPredicateFromAst($left, $node->right);
        }

        // Simple comparison: column op value
        if ($node instanceof BinaryOperation) {
            $op = $node->operator;

            if (!$node->left instanceof IdentifierNode) {
                throw new \RuntimeException("Left side of comparison must be a column");
            }
            if (!$node->right instanceof LiteralNode) {
                throw new \RuntimeException("Right side of comparison must be a literal value");
            }

            $column = $this->buildQualifiedColumnName($node->left);
            $value = $this->evaluator->evaluate($node->right, null);

            // SQL standard: *any* comparison against NULL evaluates to UNKNOWN,
            // not just `col = NULL`. Without this, `col < NULL` reached
            // Predicate::lt() and leaked a PHP TypeError.
            //
            // Decline the pushdown rather than returning Predicate::never():
            // never() only short-circuits Predicate::test(), and TableInterface
            // ::or() consults the condition list instead, so a never() that then
            // gets a condition chained onto it (`col = NULL AND col > 1`) loses
            // its "matches nothing" flag and silently widens the result. The
            // caller catches this and evaluates the OR row by row.
            if ($value === null) {
                throw new \RuntimeException("Comparison against NULL cannot be expressed as a predicate");
            }

            $p = new \mini\Table\Predicate();
            return match ($op) {
                '=' => $p->eq($column, $value),
                '<' => $p->lt($column, $value),
                '<=' => $p->lte($column, $value),
                '>' => $p->gt($column, $value),
                '>=' => $p->gte($column, $value),
                '!=', '<>' => throw new \RuntimeException("!= and <> not yet supported in OR predicates"),
                default => throw new \RuntimeException("Unsupported operator in predicate: $op"),
            };
        }

        // LIKE operation
        if ($node instanceof \mini\Parsing\SQL\AST\LikeOperation) {
            if (!$node->left instanceof IdentifierNode) {
                throw new \RuntimeException("Left side of LIKE must be a column");
            }
            if ($node->negated) {
                throw new \RuntimeException("NOT LIKE not yet supported in OR predicates");
            }
            $column = $this->buildQualifiedColumnName($node->left);
            $pattern = $this->evaluator->evaluate($node->pattern, null);
            return (new \mini\Table\Predicate())->like($column, $pattern);
        }

        // IS NULL operation
        if ($node instanceof \mini\Parsing\SQL\AST\IsNullOperation) {
            if (!$node->expression instanceof IdentifierNode) {
                throw new \RuntimeException("IS NULL expression must be a column");
            }
            if ($node->negated) {
                throw new \RuntimeException("IS NOT NULL not yet supported in OR predicates");
            }
            $column = $this->buildQualifiedColumnName($node->expression);
            return (new \mini\Table\Predicate())->eq($column, null);
        }

        throw new \RuntimeException("Unsupported expression in OR predicate: " . get_class($node));
    }

    /**
     * Append conditions from AST node to existing Predicate
     */
    private function appendPredicateFromAst(\mini\Table\Predicate $predicate, \mini\Parsing\SQL\AST\ASTNode $node): \mini\Table\Predicate
    {
        // Binary AND: chain conditions
        if ($node instanceof BinaryOperation && strtoupper($node->operator) === 'AND') {
            $predicate = $this->appendPredicateFromAst($predicate, $node->left);
            return $this->appendPredicateFromAst($predicate, $node->right);
        }

        // Simple comparison
        if ($node instanceof BinaryOperation) {
            $op = $node->operator;

            if (!$node->left instanceof IdentifierNode) {
                throw new \RuntimeException("Left side of comparison must be a column");
            }
            if (!$node->right instanceof LiteralNode) {
                throw new \RuntimeException("Right side of comparison must be a literal value");
            }

            $column = $this->buildQualifiedColumnName($node->left);
            $value = $this->evaluator->evaluate($node->right, null);

            // Any comparison against NULL is UNKNOWN. Decline the pushdown - see
            // buildPredicateFromAst() for why never() is not usable here.
            if ($value === null) {
                throw new \RuntimeException("Comparison against NULL cannot be expressed as a predicate");
            }

            return match ($op) {
                '=' => $predicate->eq($column, $value),
                '<' => $predicate->lt($column, $value),
                '<=' => $predicate->lte($column, $value),
                '>' => $predicate->gt($column, $value),
                '>=' => $predicate->gte($column, $value),
                '!=', '<>' => throw new \RuntimeException("!= and <> not yet supported in OR predicates"),
                default => throw new \RuntimeException("Unsupported operator in predicate: $op"),
            };
        }

        // LIKE operation
        if ($node instanceof \mini\Parsing\SQL\AST\LikeOperation) {
            if (!$node->left instanceof IdentifierNode) {
                throw new \RuntimeException("Left side of LIKE must be a column");
            }
            if ($node->negated) {
                throw new \RuntimeException("NOT LIKE not yet supported in OR predicates");
            }
            $column = $this->buildQualifiedColumnName($node->left);
            $pattern = $this->evaluator->evaluate($node->pattern, null);
            return $predicate->like($column, $pattern);
        }

        // IS NULL operation
        if ($node instanceof \mini\Parsing\SQL\AST\IsNullOperation) {
            if (!$node->expression instanceof IdentifierNode) {
                throw new \RuntimeException("IS NULL expression must be a column");
            }
            if ($node->negated) {
                throw new \RuntimeException("IS NOT NULL not yet supported in OR predicates");
            }
            $column = $this->buildQualifiedColumnName($node->expression);
            return $predicate->eq($column, null);
        }

        throw new \RuntimeException("Unsupported expression in OR predicate: " . get_class($node));
    }

    /**
     * Apply ORDER BY clause using table's order() method
     */
    private function applyOrderBy(TableInterface $table, array $orderBy): TableInterface
    {
        // Column set used to validate the ORDER BY references. A PartialQuery
        // resolves its schema by executing a query against its own database,
        // which for a PartialQuery registered as a table in that same database
        // recurses (a pre-existing defect, also reachable through WHERE), so
        // skip validation rather than force the schema fetch. An empty column
        // set means "schema not known", which is also not validatable.
        $available = $table instanceof PartialQuery ? null : ($table->getColumns() ?: null);

        $parts = [];
        foreach ($orderBy as $item) {
            $colExpr = $item['column'];
            $direction = strtoupper($item['direction'] ?? 'ASC');

            if (!$colExpr instanceof IdentifierNode) {
                throw new \RuntimeException("ORDER BY expression must be a column");
            }

            $name = $this->buildQualifiedColumnName($colExpr);

            if ($available === null) {
                $parts[] = $name . ' ' . $direction;
                continue;
            }

            $resolved = $this->resolveColumnForTable($name, $table);

            // Fail fast: an ORDER BY naming a column the table does not expose
            // used to be handed to order() anyway, where it silently no-opped
            // (or, over a set operation, partially pushed into one branch and
            // scrambled the output). Sorting by something that does not exist
            // is always a bug in the query.
            if (!isset($available[$resolved])) {
                throw new \RuntimeException(
                    "ORDER BY references unknown column: {$name} (available: "
                    . implode(', ', array_keys($available)) . ")"
                );
            }

            $parts[] = $resolved . ' ' . $direction;
        }

        return $table->order(implode(', ', $parts));
    }

    /**
     * Check if ORDER BY contains expressions or aliases that need evaluation
     *
     * Returns true if any ORDER BY item:
     * - Is not a simple IdentifierNode (e.g., expressions like price * stock)
     * - References a SELECT alias instead of a table column
     *
     * @param array $orderBy ORDER BY items from AST
     * @param array $selectColumns SELECT column nodes (for alias resolution)
     */
    private function orderByNeedsExpressionEval(array $orderBy, array $selectColumns): bool
    {
        // Build alias set from SELECT columns
        $aliases = [];
        foreach ($selectColumns as $col) {
            if ($col instanceof ColumnNode && $col->alias !== null) {
                $aliases[$col->alias] = true;
            }
        }

        foreach ($orderBy as $item) {
            $colExpr = $item['column'];

            // Not an identifier = expression (needs eval)
            if (!$colExpr instanceof IdentifierNode) {
                return true;
            }

            // References a SELECT alias (needs eval)
            $name = $colExpr->getName();
            if (isset($aliases[$name])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Execute SELECT with expression-based ORDER BY
     *
     * Used when ORDER BY references aliases or expressions that can't be
     * delegated to the table backend. Projects rows first, then sorts.
     */
    private function executeSelectWithExpressionOrderBy(SelectStatement $ast, TableInterface $table): iterable
    {
        // Build alias→expression map from SELECT columns
        $aliasToExpr = [];
        foreach ($ast->columns as $col) {
            if ($col instanceof ColumnNode && $col->alias !== null) {
                $aliasToExpr[$col->alias] = $col->expression;
            }
        }

        // Determine which ORDER BY items need original row context
        // (expressions that reference columns not in SELECT aliases)
        $needsOriginalRow = false;
        foreach ($ast->orderBy as $item) {
            $colExpr = $item['column'];
            if (!$colExpr instanceof IdentifierNode) {
                $needsOriginalRow = true;
                break;
            }
            $name = $colExpr->getName();
            if (!isset($aliasToExpr[$name])) {
                // Not an alias - might be a table column
                $needsOriginalRow = true;
                break;
            }
        }

        // Collect rows - keep original row if needed for ORDER BY expressions
        $results = [];
        if ($ast->distinct) {
            $seen = new \mini\Table\Index\TreapIndex();
            foreach ($table as $row) {
                $projected = $this->projectRow($row, $ast->columns);
                $key = serialize($projected);
                if (!$seen->has($key)) {
                    $seen->insert($key, 0);
                    $results[] = $needsOriginalRow
                        ? ['projected' => $projected, 'original' => $row]
                        : $projected;
                }
            }
        } else {
            foreach ($table as $row) {
                $projected = $this->projectRow($row, $ast->columns);
                $results[] = $needsOriginalRow
                    ? ['projected' => $projected, 'original' => $row]
                    : $projected;
            }
        }

        // Sort results
        if ($needsOriginalRow) {
            $results = $this->sortResultsWithOriginal($results, $ast->orderBy, $aliasToExpr);
            // Extract just the projected rows
            $results = array_map(fn($r) => $r['projected'], $results);
        } else {
            $results = $this->sortResults($results, $ast->orderBy);
        }

        // Apply OFFSET
        $offset = 0;
        if ($ast->offset !== null) {
            $offset = (int)$this->evaluator->evaluate($ast->offset, null);
        }

        // Apply LIMIT
        $limit = null;
        if ($ast->limit !== null) {
            $limit = (int)$this->evaluator->evaluate($ast->limit, null);
        }

        // Yield results with offset/limit
        $count = 0;
        foreach ($results as $i => $result) {
            if ($i < $offset) {
                continue;
            }
            if ($limit !== null && $count >= $limit) {
                break;
            }
            yield $result;
            $count++;
        }
    }

    /**
     * Apply a WHERE clause AST to build a query for mutations
     */
    private function applyWhereToTable(TableInterface $table, ?\mini\Parsing\SQL\AST\ASTNode $where): TableInterface
    {
        if ($where === null) {
            return $table;
        }

        // Optimize WHERE clause (rewrite negations for correct NULL semantics)
        $where = $this->optimizer->optimize($where);

        return $this->applyWhereToTableInterface($table, $where);
    }

    /**
     * Execute SELECT without FROM (scalar expressions)
     */
    private function executeScalarSelect(SelectStatement $ast): iterable
    {
        $row = new \stdClass();

        foreach ($ast->columns as $col) {
            // Evaluate expression (no row context)
            $value = $this->evaluator->evaluate($col->expression, null);

            // Column name: alias, or expression text
            $name = $col->alias ?? $this->expressionToColumnName($col->expression);
            $row->$name = $value;
        }

        yield $row;
    }

    /**
     * Build SingleRowTable from SELECT without FROM
     */
    private function buildScalarTable(SelectStatement $ast): SingleRowTable
    {
        $values = [];

        foreach ($ast->columns as $col) {
            $value = $this->evaluator->evaluate($col->expression, null);
            $name = $col->alias ?? $this->expressionToColumnName($col->expression);
            $values[$name] = $value;
        }

        return new SingleRowTable($values);
    }

    /**
     * Convert expression AST to column name string
     */
    private function expressionToColumnName(\mini\Parsing\SQL\AST\ASTNode $expr): string
    {
        if ($expr instanceof LiteralNode) {
            // Use raw value as column name (matches SQLite behavior)
            return (string) $expr->value;
        }

        if ($expr instanceof IdentifierNode) {
            return $expr->getName();
        }

        if ($expr instanceof BinaryOperation) {
            $left = $this->expressionToColumnName($expr->left);
            $right = $this->expressionToColumnName($expr->right);
            return $left . $expr->operator . $right;
        }

        if ($expr instanceof FunctionCallNode) {
            return $expr->name . '(...)';
        }

        // Fallback
        return '?';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // JOIN support
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Apply a JOIN clause to a table
     *
     * @param TableInterface $left The left table (already aliased)
     * @param \mini\Parsing\SQL\AST\JoinNode $join The JOIN AST node
     * @return TableInterface The joined table
     */
    private function applyJoin(TableInterface $left, \mini\Parsing\SQL\AST\JoinNode $join): TableInterface
    {
        // Handle derived table in JOIN
        if ($join->table instanceof SubqueryNode) {
            $rightTable = $this->executeDerivedTable($join->table, $join->alias);
            $rightAlias = $join->alias;
        } else {
            $rightTableName = $join->table->getFullName();
            $rightTable = $this->getTable($rightTableName);

            if ($rightTable === null) {
                throw new \RuntimeException("Table not found: $rightTableName");
            }

            // Apply alias to right table
            $rightAlias = $join->alias ?? $rightTableName;
        }

        $rightTable = $rightTable->withAlias($rightAlias);

        // CROSS JOIN: no condition needed
        if (strtoupper($join->joinType) === 'CROSS') {
            return new CrossJoinTable($left, $rightTable);
        }

        // Build predicate from ON condition
        if ($join->condition === null) {
            throw new \RuntimeException(strtoupper($join->joinType) . " JOIN requires ON condition");
        }

        $bindPredicate = $this->buildJoinPredicate($join->condition, $rightAlias);
        $leftWithBind = $left->withProperty('__bind__', $bindPredicate);

        return match (strtoupper($join->joinType)) {
            'INNER', 'JOIN' => new InnerJoinTable($leftWithBind, $rightTable),
            'LEFT', 'LEFT OUTER' => new LeftJoinTable($leftWithBind, $rightTable),
            'RIGHT', 'RIGHT OUTER' => new RightJoinTable($leftWithBind, $rightTable),
            'FULL', 'FULL OUTER' => new FullJoinTable($leftWithBind, $rightTable),
            default => throw new \RuntimeException("Unsupported join type: {$join->joinType}"),
        };
    }

    /**
     * Build a Predicate with bind parameters from a JOIN ON condition
     *
     * Converts ON conditions like `u.id = o.user_id` into a Predicate with
     * eqBind('u.id', ':o.user_id') where the bind parameter references the
     * right table column.
     *
     * @param \mini\Parsing\SQL\AST\ASTNode $condition The ON condition AST
     * @param string $rightAlias The right table alias
     * @return Predicate
     */
    private function buildJoinPredicate(\mini\Parsing\SQL\AST\ASTNode $condition, string $rightAlias): Predicate
    {
        $predicate = new Predicate();
        return $this->appendJoinConditions($predicate, $condition, $rightAlias);
    }

    /**
     * Append join conditions to a predicate
     */
    private function appendJoinConditions(
        Predicate $predicate,
        \mini\Parsing\SQL\AST\ASTNode $node,
        string $rightAlias
    ): Predicate {
        // Handle AND: both sides are conditions
        if ($node instanceof BinaryOperation && strtoupper($node->operator) === 'AND') {
            $predicate = $this->appendJoinConditions($predicate, $node->left, $rightAlias);
            return $this->appendJoinConditions($predicate, $node->right, $rightAlias);
        }

        // Handle simple comparison: left = right
        if ($node instanceof BinaryOperation) {
            $op = $node->operator;

            if (!$node->left instanceof IdentifierNode || !$node->right instanceof IdentifierNode) {
                throw new \RuntimeException("JOIN ON condition must compare columns (e.g., u.id = o.user_id)");
            }

            $leftCol = $this->buildQualifiedColumnName($node->left);
            $rightCol = $this->buildQualifiedColumnName($node->right);

            // Determine which side references the right table
            $leftQualifier = $node->left->getQualifier()[0] ?? null;
            $rightQualifier = $node->right->getQualifier()[0] ?? null;

            $leftIsRight = $leftQualifier !== null && strtolower($leftQualifier) === strtolower($rightAlias);
            $rightIsRight = $rightQualifier !== null && strtolower($rightQualifier) === strtolower($rightAlias);

            // Build bind: the right-table column becomes the bind parameter
            if ($rightIsRight && !$leftIsRight) {
                // Normal case: left.col = right.col → eqBind(left.col, :right.col)
                $bindParam = ':' . $rightCol;
                return match ($op) {
                    '=' => $predicate->eqBind($leftCol, $bindParam),
                    '<' => $predicate->ltBind($leftCol, $bindParam),
                    '<=' => $predicate->lteBind($leftCol, $bindParam),
                    '>' => $predicate->gtBind($leftCol, $bindParam),
                    '>=' => $predicate->gteBind($leftCol, $bindParam),
                    default => throw new \RuntimeException("Unsupported JOIN operator: $op"),
                };
            } elseif ($leftIsRight && !$rightIsRight) {
                // Swapped case: right.col = left.col → eqBind(left.col, :right.col)
                $bindParam = ':' . $leftCol;
                // Swap the comparison direction
                return match ($op) {
                    '=' => $predicate->eqBind($rightCol, $bindParam),
                    '<' => $predicate->gtBind($rightCol, $bindParam),
                    '<=' => $predicate->gteBind($rightCol, $bindParam),
                    '>' => $predicate->ltBind($rightCol, $bindParam),
                    '>=' => $predicate->lteBind($rightCol, $bindParam),
                    default => throw new \RuntimeException("Unsupported JOIN operator: $op"),
                };
            } else {
                throw new \RuntimeException(
                    "JOIN ON condition must compare left and right tables (found: $leftCol vs $rightCol)"
                );
            }
        }

        throw new \RuntimeException("Unsupported JOIN ON expression: " . get_class($node));
    }

    /**
     * Build qualified column name from identifier node
     */
    private function buildQualifiedColumnName(IdentifierNode $node): string
    {
        if ($node->isQualified()) {
            $qualifier = $node->getQualifier()[0] ?? '';
            return $qualifier . '.' . $node->getName();
        }
        return $node->getName();
    }

    /**
     * Resolve a column name against a table's actual columns
     *
     * Handles cases where query uses qualified name (t1.id) but table has unqualified (id),
     * or vice versa.
     */
    private function resolveColumnForTable(string $colName, TableInterface $table): string
    {
        $columns = $table->getColumns();

        // Exact match
        if (isset($columns[$colName])) {
            return $colName;
        }

        // If colName is qualified (t1.id), try unqualified (id)
        if (str_contains($colName, '.')) {
            $unqualified = explode('.', $colName, 2)[1];
            if (isset($columns[$unqualified])) {
                return $unqualified;
            }
        }

        // If colName is unqualified, look for qualified matches (e.g., "id" matches "t1.id").
        // More than one match means the reference is ambiguous across the joined
        // tables. Silently picking the first one made the answer depend on the
        // order of tables in FROM, so reject it instead of guessing.
        $matches = [];
        foreach ($columns as $name => $_) {
            if (str_ends_with($name, '.' . $colName)) {
                $matches[] = $name;
            }
        }
        if (count($matches) > 1) {
            throw new \RuntimeException(
                "Ambiguous column name: {$colName} (matches " . implode(', ', $matches) . ")"
            );
        }
        if (count($matches) === 1) {
            return $matches[0];
        }

        // No match found - return original (will likely cause an error downstream)
        return $colName;
    }
}
