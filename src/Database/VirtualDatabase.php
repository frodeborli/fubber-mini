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

    /** @var array<string, array{step: callable, final: callable, argCount: int}> */
    private array $aggregates = [];

    private ExpressionEvaluator $evaluator;

    /** Last insert ID from most recent INSERT */
    private ?string $lastInsertId = null;

    /** @var \WeakMap<Query, PartialQuery> Maps Query instances to their underlying PartialQuery */
    private \WeakMap $queryMap;

    /** Maximum query execution time in seconds (null = no limit) */
    private ?float $queryTimeout = null;

    /** Query start time for timeout tracking */
    private ?float $queryStartTime = null;

    public function __construct()
    {
        $this->evaluator = new ExpressionEvaluator();
        $this->evaluator->setSubqueryExecutor(fn($query, $outerRow) => $this->executeSubqueryWithContext($query, $outerRow));
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
     * Set maximum query execution time in seconds
     *
     * @param float|null $seconds Maximum time in seconds, null to disable
     */
    public function setQueryTimeout(?float $seconds): self
    {
        $this->queryTimeout = $seconds;
        return $this;
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
        if ($node instanceof FunctionCallNode) {
            if ($this->isAggregate($node->name)) {
                return true;
            }
            // Check arguments for nested aggregates
            foreach ($node->arguments as $arg) {
                if ($this->expressionHasAggregate($arg)) {
                    return true;
                }
            }
        }

        if ($node instanceof BinaryOperation) {
            return $this->expressionHasAggregate($node->left)
                || $this->expressionHasAggregate($node->right);
        }

        return false;
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
        if ($node instanceof WindowFunctionNode) {
            return true;
        }

        if ($node instanceof BinaryOperation) {
            return $this->expressionHasWindowFunction($node->left)
                || $this->expressionHasWindowFunction($node->right);
        }

        return false;
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
                    $context = ($context ?? 0) + $value;
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
                    $context['sum'] = ($context['sum'] ?? 0) + $value;
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
     * Register a table with a name
     */
    public function registerTable(string $name, TableInterface $table): self
    {
        $this->tables[strtolower($name)] = $table;
        return $this;
    }

    /**
     * Get a registered table by name
     */
    public function getTable(string $name): ?TableInterface
    {
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
     * Get a raw query executor closure for PartialQuery
     *
     * VirtualDatabase always needs AST to evaluate in-memory.
     * If AST is not provided, calls getAST() to force parsing.
     */
    private function rawExecutor(): \Closure
    {
        return function (PartialQuery $query, ?ASTNode $ast): \Traversable {
            // If AST not provided (unmodified query), get it from query
            // This forces parsing but VirtualDatabase always needs AST
            if ($ast === null) {
                $ast = $query->getAST();
            }

            if ($ast instanceof WithStatement) {
                return $this->wrapWithTimeout($this->executeWithStatement($ast));
            }

            if ($ast instanceof UnionNode) {
                $table = $this->executeUnionAsTable($ast);
                return $this->wrapWithTimeout(new ResultSet($table));
            }

            if (!$ast instanceof SelectStatement) {
                throw new \RuntimeException("query() only accepts SELECT statements");
            }

            return $this->wrapWithTimeout(new ResultSet($this->executeSelect($ast)));
        };
    }

    /**
     * Wrap an iterable with timeout checking
     */
    private function wrapWithTimeout(iterable $result): \Generator
    {
        $this->queryStartTime = microtime(true);
        $rowCount = 0;
        try {
            foreach ($result as $key => $row) {
                // Check timeout every 100 rows (avoid overhead on small queries)
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

        return match ($ast->operator) {
            'UNION' => $ast->all
                ? new ConcatTable($left, $right)
                : (new ConcatTable($left, $right))->distinct(),
            'INTERSECT' => $ast->all
                ? $this->intersectTables($left, $right)
                : $this->intersectTables($left, $right)->distinct(),
            'EXCEPT' => $ast->all
                ? $this->exceptTables($left, $right)
                : $this->exceptTables($left, $right)->distinct(),
            default => throw new \RuntimeException("Unknown set operator: {$ast->operator}"),
        };
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
            return $this->executeSelectAsTable($ast);
        }
        throw new \RuntimeException("Unexpected UNION branch type: " . get_class($ast));
    }

    /**
     * Execute a WITH statement (CTE)
     *
     * @param WithStatement $ast The WITH statement AST
     * @return ResultSetInterface The query result
     */
    private function executeWithStatement(WithStatement $ast): ResultSetInterface
    {
        // Track which CTEs we register so we can clean them up
        $registeredCtes = [];

        try {
            // Process each CTE definition
            foreach ($ast->ctes as $cte) {
                $cteName = strtolower($cte['name']);

                if ($ast->recursive && $this->isCteRecursive($cte, $cteName)) {
                    // Recursive CTE - requires iterative execution
                    $table = $this->executeRecursiveCte($cte, $cteName);
                } else {
                    // Non-recursive CTE - simple execution
                    $table = $this->executeCteQuery($cte['query']);
                }

                // Apply column aliasing if specified
                if ($cte['columns'] !== null) {
                    $table = $this->renameCteColumns($table, $cte['columns']);
                }

                // Register as a temporary table
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
            // Clean up CTE tables (optional - they'd be overwritten anyway)
            foreach ($registeredCtes as $cteName) {
                unset($this->tables[$cteName]);
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

        // Find the anchor (non-recursive) and recursive parts
        // For simplicity, assume left is anchor, right is recursive
        $anchorAst = $union->left;
        $recursiveAst = $union->right;

        // Execute anchor query (this is the base case)
        $anchorTable = $this->executeCteQuery($anchorAst);
        $anchorRows = array_values(iterator_to_array($anchorTable));

        if (empty($anchorRows)) {
            return $anchorTable;
        }

        // Get column names from anchor - these define the CTE's schema
        $anchorColumnNames = array_keys(get_object_vars($anchorRows[0]));

        // Build result table
        $resultRows = $anchorRows;
        $workingRows = $anchorRows;

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

            // For UNION (not UNION ALL), we should deduplicate
            // For now, just handle UNION ALL
            $resultRows = array_merge($resultRows, $newRows);
            $workingRows = $newRows;
        }

        // Remove temporary CTE table
        unset($this->tables[$cteName]);

        return $this->rowsToTable($resultRows);
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
        // Get rows from table
        $rows = iterator_to_array($table);

        if (empty($rows)) {
            // Create table with renamed columns
            $columns = [];
            foreach ($columnNames as $name) {
                $columns[] = new \mini\Table\ColumnDef($name, \mini\Table\Types\ColumnType::Text);
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

        // Create new columns with renamed names
        $columns = [];
        foreach ($columnNames as $name) {
            $columns[] = new \mini\Table\ColumnDef($name, \mini\Table\Types\ColumnType::Text);
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
        }

        // Process JOINs - only apply aliasing when there are joins
        if (!empty($ast->joins)) {
            $baseAlias = $ast->fromAlias ?? $tableName;
            $table = $table->withAlias($baseAlias);

            foreach ($ast->joins as $join) {
                $table = $this->applyJoin($table, $join);
            }
        }

        // Apply WHERE - delegate to table backend
        if ($ast->where !== null) {
            $table = $this->applyWhereToTableInterface($table, $ast->where);
        }

        // Check for window functions - requires materializing rows first
        if ($this->hasWindowFunctions($ast->columns)) {
            yield from $this->executeWindowSelect($ast, $table);
            return;
        }

        // Check for aggregate functions - requires different execution path
        if ($this->hasAggregates($ast->columns)) {
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
    private function executeWindowSelect(SelectStatement $ast, TableInterface $table): iterable
    {
        // 1. Materialize all rows - window functions need the full dataset
        $rows = [];
        foreach ($table as $row) {
            $rows[] = $row;
        }

        // 2. Collect window function info from columns
        $windowFuncs = $this->collectWindowFunctions($ast->columns);

        // 3. Compute window function values for each row
        // windowValues[rowIndex][windowFuncAlias] = value
        $windowValues = [];
        foreach ($rows as $idx => $row) {
            $windowValues[$idx] = [];
        }

        foreach ($windowFuncs as $alias => $wfn) {
            $values = $this->computeWindowFunction($wfn, $rows);
            foreach ($values as $idx => $val) {
                $windowValues[$idx][$alias] = $val;
            }
        }

        // 4. Apply ORDER BY if present (on the result rows, not the window ordering)
        if ($ast->orderBy) {
            // Sort the rows maintaining their window values
            $indices = array_keys($rows);
            usort($indices, function ($a, $b) use ($rows, $ast) {
                return $this->compareRowsForOrderBy($rows[$a], $rows[$b], $ast->orderBy);
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

        $count = 0;
        foreach ($rows as $idx => $row) {
            if ($idx < $offset) {
                continue;
            }
            if ($limit !== null && $count >= $limit) {
                break;
            }

            // 6. Project the row with window function values
            yield $this->projectRowWithWindowValues($row, $ast->columns, $windowValues[$idx]);
            $count++;
        }
    }

    /**
     * Collect window functions from SELECT columns
     * Returns: [alias => WindowFunctionNode, ...]
     */
    private function collectWindowFunctions(array $columns): array
    {
        $result = [];
        foreach ($columns as $col) {
            if (!$col instanceof ColumnNode) {
                continue;
            }
            $wfns = $this->extractWindowFunctions($col->expression);
            foreach ($wfns as $wfn) {
                // Use alias if available, otherwise generate one
                $alias = $col->alias ?? $this->generateWindowFuncAlias($wfn);
                $result[$alias] = $wfn;
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
        if ($node instanceof BinaryOperation) {
            return array_merge(
                $this->extractWindowFunctions($node->left),
                $this->extractWindowFunctions($node->right)
            );
        }
        return [];
    }

    /**
     * Generate a unique alias for a window function
     */
    private function generateWindowFuncAlias(WindowFunctionNode $wfn): string
    {
        static $counter = 0;
        return '__wfn_' . $wfn->function->name . '_' . (++$counter);
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
    private function projectRowWithWindowValues(object $row, array $columns, array $windowValues): object
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
        array $windowValues
    ): mixed {
        if ($expr instanceof WindowFunctionNode) {
            // Find the matching window value by alias or generate alias
            $alias = $this->generateWindowFuncAlias($expr);
            // Search for matching value in windowValues
            foreach ($windowValues as $key => $val) {
                // For now, return the first window value found (single window function per column)
                return $val;
            }
            return null;
        }

        // For non-window expressions, use the standard evaluator
        return $this->evaluator->evaluate($expr, $row);
    }

    /**
     * Compare two rows for ORDER BY (for result sorting)
     */
    private function compareRowsForOrderBy(object $a, object $b, array $orderBy): int
    {
        foreach ($orderBy as $spec) {
            $valA = $this->evaluator->evaluate($spec->expression, $a);
            $valB = $this->evaluator->evaluate($spec->expression, $b);
            $cmp = $valA <=> $valB;
            if ($cmp !== 0) {
                return strtoupper($spec->direction) === 'DESC' ? -$cmp : $cmp;
            }
        }
        return 0;
    }

    /**
     * Execute an aggregate SELECT (e.g., SELECT COUNT(*), SUM(price) FROM orders)
     *
     * Without GROUP BY: returns a single row with aggregate results.
     * With GROUP BY: returns one row per group with group columns + aggregate results.
     */
    private function executeAggregateSelect(SelectStatement $ast, TableInterface $table): iterable
    {
        // Collect aggregate function info
        $aggregateInfos = $this->collectAggregateInfos($ast->columns);

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
        // Step phase: iterate through all rows
        $lastRow = null;
        foreach ($table as $row) {
            $lastRow = $row;
            $this->stepAggregates($aggregateInfos, $row);
        }

        // Final phase: build result row
        $result = $this->buildAggregateResultRow($aggregateInfos, $nonAggregateColumns, $lastRow);
        yield $result;
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
        // Groups: key => ['aggregates' => [...], 'sampleRow' => object]
        $groups = [];

        // Step phase: iterate through all rows, grouping by key
        foreach ($table as $row) {
            // Compute group key from GROUP BY expressions
            $keyParts = [];
            foreach ($ast->groupBy as $groupExpr) {
                $keyParts[] = $this->evaluator->evaluate($groupExpr, $row);
            }
            $groupKey = serialize($keyParts);

            // Initialize group if new
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'keyValues' => $keyParts,
                    'sampleRow' => $row,
                    'aggregates' => $this->cloneAggregateInfos($aggregateInfos),
                ];
            }

            // Step aggregates for this group
            $this->stepAggregates($groups[$groupKey]['aggregates'], $row);
        }

        // Final phase: build result rows
        $results = [];
        foreach ($groups as $group) {
            $result = $this->buildAggregateResultRow(
                $group['aggregates'],
                $nonAggregateColumns,
                $group['sampleRow']
            );

            // Apply HAVING filter
            if ($ast->having !== null) {
                $passes = $this->evaluator->evaluate($ast->having, $result);
                if (!$passes) {
                    continue;
                }
            }

            $results[] = $result;
        }

        // Apply ORDER BY if present
        if ($ast->orderBy) {
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
     * Clone aggregate infos with fresh contexts for a new group
     */
    private function cloneAggregateInfos(array $aggregateInfos): array
    {
        $cloned = [];
        foreach ($aggregateInfos as $info) {
            $cloned[] = [
                'name' => $info['name'],
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
     * Build result row from finalized aggregates and non-aggregate columns
     */
    private function buildAggregateResultRow(
        array $aggregateInfos,
        array $nonAggregateColumns,
        ?object $sampleRow
    ): \stdClass {
        $result = new \stdClass();

        // Add non-aggregate columns (evaluated from sample row)
        foreach ($nonAggregateColumns as $colInfo) {
            $value = $sampleRow !== null
                ? $this->evaluator->evaluate($colInfo['expression'], $sampleRow)
                : null;
            $result->{$colInfo['name']} = $value;
        }

        // Add aggregate results
        for ($i = 0; $i < count($aggregateInfos); $i++) {
            $final = $aggregateInfos[$i]['final'];
            $value = $final($aggregateInfos[$i]['context']);
            $result->{$aggregateInfos[$i]['name']} = $value;
        }

        return $result;
    }

    /**
     * Collect non-aggregate columns from SELECT
     *
     * These are columns that reference GROUP BY expressions or constants.
     */
    private function collectNonAggregateColumns(array $columns): array
    {
        $nonAggregates = [];

        foreach ($columns as $col) {
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

            // Skip wildcards
            if ($col->expression instanceof IdentifierNode && $col->expression->isWildcard()) {
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
    private function collectAggregateInfos(array $columns): array
    {
        $infos = [];

        foreach ($columns as $col) {
            if (!$col instanceof ColumnNode) {
                continue;
            }

            if (!$col->expression instanceof FunctionCallNode) {
                continue;
            }

            $funcNode = $col->expression;
            $funcName = strtoupper($funcNode->name);

            if (!isset($this->aggregates[$funcName])) {
                continue;
            }

            $aggregate = $this->aggregates[$funcName];

            // Determine output column name
            $outputName = $col->alias;
            if ($outputName === null) {
                // Default: function call as name like "COUNT(*)" or "SUM(price)"
                $outputName = $funcNode->name . '(';
                $argNames = [];
                foreach ($funcNode->arguments as $arg) {
                    if ($arg instanceof IdentifierNode) {
                        $argNames[] = $arg->isWildcard() ? '*' : $arg->getName();
                    } else {
                        $argNames[] = '?';
                    }
                }
                $outputName .= implode(', ', $argNames) . ')';
            }

            $infos[] = [
                'name' => $outputName,
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

        $tableName = $ast->from->getFullName();
        $table = $this->getTable($tableName);

        if ($table === null) {
            throw new \RuntimeException("Table not found: $tableName");
        }

        // Check if we have CROSS JOINs that could benefit from predicate pushdown
        $hasCrossJoins = !empty($ast->joins) && $ast->where !== null;
        foreach ($ast->joins as $join) {
            if (strtoupper($join->joinType) !== 'CROSS') {
                $hasCrossJoins = false;
                break;
            }
        }

        if ($hasCrossJoins) {
            // Use optimized path with predicate pushdown for comma-joins
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
                $table = $this->applyWhereToTableInterface($table, $ast->where);
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

        // 2. Flatten WHERE into AND-connected predicates
        $predicates = [];
        $this->flattenAndPredicates($ast->where, $predicates);

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

        // 4. Build cross join with (possibly filtered) tables
        $result = null;
        foreach ($tables as $table) {
            if ($result === null) {
                $result = $table;
            } else {
                $result = new CrossJoinTable($result, $table);
            }
        }

        // 5. Apply remaining cross-table predicates
        if (!empty($remainingPredicates)) {
            $combinedPredicate = $this->rebuildAndExpression($remainingPredicates);
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
     * Recursively collect table references from an expression
     */
    private function collectTableReferences(ASTNode $node, array $knownTables, array &$tables): void
    {
        if ($node instanceof IdentifierNode) {
            $colName = $node->getName();
            // Check if column name starts with a known table alias
            foreach ($knownTables as $alias) {
                // Match "alias.col" or unqualified names like "d6" matching table "t6"
                if (str_starts_with($colName, $alias . '.')) {
                    $tables[] = $alias;
                    return;
                }
                // For unqualified columns, try to match by suffix (e.g., d6 -> t6)
                if (!str_contains($colName, '.') && strlen($colName) >= 2) {
                    $tableSuffix = substr($colName, -1);
                    if ($alias === 't' . $tableSuffix) {
                        $tables[] = $alias;
                        return;
                    }
                }
            }
        } elseif ($node instanceof BinaryOperation) {
            $this->collectTableReferences($node->left, $knownTables, $tables);
            $this->collectTableReferences($node->right, $knownTables, $tables);
        } elseif ($node instanceof UnaryOperation) {
            $this->collectTableReferences($node->expression, $knownTables, $tables);
        } elseif ($node instanceof InOperation) {
            $this->collectTableReferences($node->left, $knownTables, $tables);
            foreach ($node->values as $val) {
                $this->collectTableReferences($val, $knownTables, $tables);
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
        $table = $this->executeSelectAsTable($subquery->query);

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
     * Execute a subquery with outer row context for correlated subqueries
     *
     * Used by ExpressionEvaluator when evaluating scalar subqueries in SELECT or WHERE.
     *
     * @param SelectStatement $query The subquery to execute
     * @param object|null $outerRow The outer row for correlated subquery references
     * @return iterable Result rows
     */
    private function executeSubqueryWithContext(SelectStatement $query, ?object $outerRow): iterable
    {
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

        $tableName = $query->from->getFullName();
        $tableAlias = $query->fromAlias ?? $tableName;
        $table = $this->getTable($tableName);

        if ($table === null) {
            throw new \RuntimeException("Table not found: $tableName");
        }

        // Find outer references in the subquery
        $outerRefs = $query->where !== null
            ? $this->findOuterReferences($query, $tableName, $tableAlias)
            : [];

        if (empty($outerRefs)) {
            // No outer references - execute normally
            yield from $this->executeSelect($query);
            return;
        }

        // Correlated subquery - evaluate WHERE with outer context
        // Build outer context map from outer row
        $outerContext = [];
        foreach ($outerRefs as $ref) {
            $key = $ref['table'] . '.' . $ref['column'];
            $qualifiedCol = $ref['table'] . '.' . $ref['column'];
            $outerContext[$key] = $outerRow->$qualifiedCol ?? $outerRow->{$ref['column']} ?? null;
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
            // Execute aggregate over filtered rows
            yield from $this->executeAggregateOnRows($query, $filteredRows);
            return;
        }

        // Project and return
        foreach ($filteredRows as $row) {
            yield $this->projectRow($row, $query->columns);
        }
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
        $subqueryTableName = $subqueryAst->from->getFullName();
        $subqueryTableAlias = $subqueryAst->fromAlias ?? $subqueryTableName;

        // Find outer references in the subquery (check both table name and alias)
        $outerRefs = $this->findOuterReferences($subqueryAst, $subqueryTableName, $subqueryTableAlias);

        if (empty($outerRefs)) {
            // Non-correlated: evaluate once
            $subqueryTable = $this->executeSelectAsTable($subqueryAst);
            $exists = $subqueryTable->exists();

            if ($node->negated) {
                $exists = !$exists;
            }

            // If EXISTS true: return all rows. If false: return empty
            return $exists ? $table : $table->except($table);
        }

        // Check if WHERE contains OR with outer references
        // If so, use row-by-row evaluation instead of template approach
        $useRowByRowEval = $this->hasOrWithOuterReferences($subqueryAst->where, $outerRefs);

        if ($useRowByRowEval) {
            return $this->applyCorrelatedExistsRowByRow($table, $node, $subqueryAst, $outerRefs);
        }

        // Build template and evaluate per row (more efficient for AND-only cases)
        $template = $this->buildCorrelatedTemplate($subqueryAst, $outerRefs);

        // Find primary key column for later filtering
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

            if ($exists && $pkColumn !== null) {
                $matchingPkValues[] = $row->$pkColumn;
            }
        }

        // Build result from matching PK values
        if (empty($matchingPkValues) || $pkColumn === null) {
            return $table->except($table);
        }

        return $table->in($pkColumn, new \mini\Table\Utility\Set($pkColumn, $matchingPkValues));
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

        // Use provided column names, or fall back to table's column order
        if (!empty($ast->columns)) {
            $columnNames = array_map(fn($col) => $col->getName(), $ast->columns);
        } else {
            $columnNames = array_keys($table->getColumns());
        }
        $lastId = 0;

        foreach ($ast->values as $valueRow) {
            $row = [];
            foreach ($valueRow as $i => $valueNode) {
                $colName = $columnNames[$i] ?? "col_$i";
                $row[$colName] = $this->evaluator->evaluate($valueNode, null);
            }
            $lastId = $table->insert($row);
        }

        // Track for lastInsertId()
        $this->lastInsertId = (string) $lastId;

        return $lastId;
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

        // Build changes from SET clause
        $changes = [];
        foreach ($ast->updates as $update) {
            $colName = $update['column']->getName();
            $changes[$colName] = $this->evaluator->evaluate($update['value'], null);
        }

        // Apply WHERE filter and execute update
        $query = $this->applyWhereToTable($table, $ast->where);
        return $table->update($query, $changes);
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

        $query = $this->applyWhereToTable($table, $ast->where);
        return $table->delete($query);
    }

    /**
     * Execute CREATE TABLE - creates an InMemoryTable with the given schema
     */
    private function executeCreateTable(CreateTableStatement $ast): int
    {
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
        $table = new InMemoryTable(...$columnDefs);
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
        // Binary AND: apply both sides
        if ($node instanceof BinaryOperation && strtoupper($node->operator) === 'AND') {
            $table = $this->applyWhereToTableInterface($table, $node->left);
            return $this->applyWhereToTableInterface($table, $node->right);
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
                $column = $this->buildQualifiedColumnName($node->right);
                $value = $node->left->value;
                $flippedOp = $this->flipComparisonOp($op);

                return match ($flippedOp) {
                    '=' => $value === null ? EmptyTable::from($table) : $table->eq($column, $value),
                    '<' => $table->lt($column, $value),
                    '<=' => $table->lte($column, $value),
                    '>' => $table->gt($column, $value),
                    '>=' => $table->gte($column, $value),
                    '!=', '<>' => $table->except($table->eq($column, $value)),
                    default => $this->filterByExpression($table, $node),
                };
            }

            // Check if we can push to table (column = value pattern)
            // Value can be literal, bound placeholder, or subquery
            $isValueNode = fn($n) => $n instanceof LiteralNode
                || ($n instanceof PlaceholderNode && $n->isBound)
                || $n instanceof SubqueryNode;

            $canPushToTable = $node->left instanceof IdentifierNode && $isValueNode($node->right);

            if ($canPushToTable) {
                $column = $this->buildQualifiedColumnName($node->left);
                // Evaluate right side - this handles both literals and subqueries
                $value = $this->evaluator->evaluate($node->right, null);

                // SQL standard: col = NULL always returns no rows (NULL = NULL is UNKNOWN, not TRUE)
                // For IS NULL semantics, use the IsNullOperation branch instead
                return match ($op) {
                    '=' => $value === null ? EmptyTable::from($table) : $table->eq($column, $value),
                    '<' => $table->lt($column, $value),
                    '<=' => $table->lte($column, $value),
                    '>' => $table->gt($column, $value),
                    '>=' => $table->gte($column, $value),
                    '!=', '<>' => $table->except($table->eq($column, $value)),
                    default => throw new \RuntimeException("Unsupported operator: $op"),
                };
            }

            // Try to simplify arithmetic expressions: (col + 5) > 7  →  col > 2
            $simplified = $this->trySimplifyArithmeticComparison($node);
            if ($simplified !== null) {
                return match ($simplified['op']) {
                    '=' => $simplified['value'] === null
                        ? EmptyTable::from($table)
                        : $table->eq($simplified['column'], $simplified['value']),
                    '<' => $table->lt($simplified['column'], $simplified['value']),
                    '<=' => $table->lte($simplified['column'], $simplified['value']),
                    '>' => $table->gt($simplified['column'], $simplified['value']),
                    '>=' => $table->gte($simplified['column'], $simplified['value']),
                    '!=', '<>' => $table->except($table->eq($simplified['column'], $simplified['value'])),
                    default => $this->filterByExpression($table, $node),
                };
            }

            // Fall back to expression-based filtering (for CASE, expressions, etc.)
            return $this->filterByExpression($table, $node);
        }

        // LIKE operation
        if ($node instanceof \mini\Parsing\SQL\AST\LikeOperation) {
            if (!$node->left instanceof IdentifierNode) {
                throw new \RuntimeException("Left side of LIKE must be a column");
            }
            $column = $this->buildQualifiedColumnName($node->left);
            $pattern = $this->evaluator->evaluate($node->pattern, null);
            $result = $table->like($column, $pattern);
            return $node->negated ? $table->except($result) : $result;
        }

        // IS NULL operation
        if ($node instanceof \mini\Parsing\SQL\AST\IsNullOperation) {
            if (!$node->expression instanceof IdentifierNode) {
                throw new \RuntimeException("IS NULL expression must be a column");
            }
            $column = $this->buildQualifiedColumnName($node->expression);
            $nullRows = $table->eq($column, null);
            return $node->negated ? $table->except($nullRows) : $nullRows;
        }

        // IN operation
        if ($node instanceof InOperation) {
            if (!$node->left instanceof IdentifierNode) {
                throw new \RuntimeException("Left side of IN must be a column");
            }
            $column = $this->buildQualifiedColumnName($node->left);

            if ($node->isSubquery()) {
                // Subquery: execute and pass as SetInterface
                $set = $this->executeSubqueryAsSet($node->values, $column);
            } else {
                // Literal list: build in-memory Set
                $values = [];
                foreach ($node->values as $valueNode) {
                    $values[] = $this->evaluator->evaluate($valueNode, null);
                }
                $set = new \mini\Table\Utility\Set($column, $values);
            }

            $result = $this->applyInWithIndexAwareness($table, $column, $set);
            return $node->negated ? $table->except($result) : $result;
        }

        // EXISTS operation
        if ($node instanceof ExistsOperation) {
            return $this->applyExistsToTable($table, $node);
        }

        // BETWEEN operation
        if ($node instanceof \mini\Parsing\SQL\AST\BetweenOperation) {
            // Can only push to table if expression is a simple column and bounds are literals
            if ($node->expression instanceof IdentifierNode
                && $node->low instanceof LiteralNode
                && $node->high instanceof LiteralNode
            ) {
                $column = $this->buildQualifiedColumnName($node->expression);
                $low = $this->evaluator->evaluate($node->low, null);
                $high = $this->evaluator->evaluate($node->high, null);
                $result = $table->gte($column, $low)->lte($column, $high);
                return $node->negated ? $table->except($result) : $result;
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

        throw new \RuntimeException("Unsupported WHERE expression: " . get_class($node));
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

            // SQL standard: col = NULL always evaluates to UNKNOWN (no matches)
            if ($value === null && $op === '=') {
                return \mini\Table\Predicate::never();
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

            // SQL standard: col = NULL always evaluates to UNKNOWN (no matches)
            // Return Predicate::never() to indicate this branch can't match
            if ($value === null && $op === '=') {
                return \mini\Table\Predicate::never();
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
        $parts = [];
        foreach ($orderBy as $item) {
            $colExpr = $item['column'];
            $direction = strtoupper($item['direction'] ?? 'ASC');

            if (!$colExpr instanceof IdentifierNode) {
                throw new \RuntimeException("ORDER BY expression must be a column");
            }

            $parts[] = $this->buildQualifiedColumnName($colExpr) . ' ' . $direction;
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
}
