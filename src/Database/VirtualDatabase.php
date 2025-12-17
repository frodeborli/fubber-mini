<?php

namespace mini\Database;

use mini\Parsing\SQL\SqlParser;
use mini\Parsing\SQL\AstParameterBinder;
use mini\Parsing\SQL\AST\{
    SelectStatement,
    InsertStatement,
    UpdateStatement,
    DeleteStatement,
    UnionNode,
    ColumnNode,
    IdentifierNode,
    LiteralNode,
    BinaryOperation,
    UnaryOperation,
    LikeOperation,
    IsNullOperation,
    InOperation,
    BetweenOperation,
    ExistsOperation,
    SubqueryNode,
    FunctionCallNode
};
use mini\Table\{TableInterface, MutableTableInterface, SetInterface, SingleRowTable, Table, ConcatTable};

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

    public function __construct()
    {
        $this->evaluator = new ExpressionEvaluator();
        $this->registerBuiltinAggregates();
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
     * Register built-in SQL aggregate functions
     */
    private function registerBuiltinAggregates(): void
    {
        // COUNT(*) or COUNT(column)
        $this->createAggregate(
            'COUNT',
            function (&$context, $value = null) {
                $context = ($context ?? 0) + 1;
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
     * Execute a SELECT query
     *
     * @param string $sql SQL query
     * @param array $params Bound parameters
     * @return ResultSetInterface<object> Rows as stdClass objects
     */
    public function query(string $sql, array $params = []): ResultSetInterface
    {
        $ast = $this->parseAndBind($sql, $params);

        if ($ast instanceof UnionNode) {
            $table = $this->executeUnionAsTable($ast);
            return new ResultSet($table);
        }

        if (!$ast instanceof SelectStatement) {
            throw new \RuntimeException("query() only accepts SELECT statements");
        }

        return new ResultSet($this->executeSelect($ast));
    }

    /**
     * Execute an INSERT, UPDATE, or DELETE statement
     *
     * @param string $sql SQL statement
     * @param array $params Bound parameters
     * @return int Number of affected rows (or last insert ID for INSERT)
     */
    public function exec(string $sql, array $params = []): int
    {
        $ast = $this->parseAndBind($sql, $params);

        if ($ast instanceof InsertStatement) {
            return $this->executeInsert($ast);
        }

        if ($ast instanceof UpdateStatement) {
            return $this->executeUpdate($ast);
        }

        if ($ast instanceof DeleteStatement) {
            return $this->executeDelete($ast);
        }

        throw new \RuntimeException("exec() only accepts INSERT, UPDATE, or DELETE statements");
    }

    /**
     * {@inheritdoc}
     */
    public function queryOne(string $sql, array $params = []): ?object
    {
        return $this->query($sql, $params)->one();
    }

    /**
     * {@inheritdoc}
     */
    public function queryField(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->field();
    }

    /**
     * {@inheritdoc}
     */
    public function queryColumn(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->column();
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
    public function partialQuery(string $table, ?string $sql = null, array $params = []): PartialQuery
    {
        throw new \RuntimeException("partialQuery() not yet supported in VirtualDatabase");
    }

    /**
     * {@inheritdoc}
     */
    public function delete(PartialQuery $query): int
    {
        throw new \RuntimeException("delete() with PartialQuery not yet supported in VirtualDatabase");
    }

    /**
     * {@inheritdoc}
     */
    public function update(PartialQuery $query, string|array $set, array $params = []): int
    {
        throw new \RuntimeException("update() with PartialQuery not yet supported in VirtualDatabase");
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
    public function buildSchemaTable(string $tableName): void
    {
        // Create an InMemoryTable with schema structure
        $schemaTable = new \mini\Table\InMemoryTable(
            new \mini\Table\ColumnDef('table_name', \mini\Table\ColumnType::Text, \mini\Table\IndexType::Index),
            new \mini\Table\ColumnDef('name', \mini\Table\ColumnType::Text),
            new \mini\Table\ColumnDef('type', \mini\Table\ColumnType::Text),
            new \mini\Table\ColumnDef('data_type', \mini\Table\ColumnType::Text),
            new \mini\Table\ColumnDef('is_nullable', \mini\Table\ColumnType::Int),
            new \mini\Table\ColumnDef('default_value', \mini\Table\ColumnType::Text),
            new \mini\Table\ColumnDef('ordinal', \mini\Table\ColumnType::Int),
            new \mini\Table\ColumnDef('extra', \mini\Table\ColumnType::Text),
        );

        // Populate with schema info
        foreach ($this->tables as $tblName => $table) {
            // Skip the schema table itself
            if (strtolower($tblName) === strtolower($tableName)) {
                continue;
            }

            $ordinal = 0;
            foreach ($table->getColumns() as $colName => $colDef) {
                $ordinal++;
                $schemaTable->insert([
                    'table_name' => $tblName,
                    'name' => $colName,
                    'type' => 'column',
                    'data_type' => $colDef->type->name,
                    'is_nullable' => 1,
                    'default_value' => null,
                    'ordinal' => $ordinal,
                    'extra' => null,
                ]);

                // Add index info if column has an index
                if ($colDef->index !== \mini\Table\IndexType::None) {
                    $indexType = match ($colDef->index) {
                        \mini\Table\IndexType::Primary => 'primary',
                        \mini\Table\IndexType::Unique => 'unique',
                        default => 'index',
                    };
                    $schemaTable->insert([
                        'table_name' => $tblName,
                        'name' => $colName . '_idx',
                        'type' => $indexType,
                        'data_type' => null,
                        'is_nullable' => null,
                        'default_value' => null,
                        'ordinal' => null,
                        'extra' => $colName,
                    ]);
                }
            }
        }

        $this->registerTable($tableName, $schemaTable);
    }

    private function parseAndBind(string $sql, array $params): object
    {
        $parser = new SqlParser();
        $ast = $parser->parse($sql);

        if (!empty($params)) {
            $binder = new AstParameterBinder($params);
            $ast = $binder->bind($ast);
        }

        return $ast;
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

        $concat = new ConcatTable($left, $right);

        // UNION ALL: just concatenate
        // UNION: wrap with distinct() for deduplication
        return $ast->all ? $concat : $concat->distinct();
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

    private function executeSelect(SelectStatement $ast): iterable
    {
        // Get the source table
        if ($ast->from === null) {
            // SELECT without FROM - use SingleRowTable
            yield from $this->executeScalarSelect($ast);
            return;
        }

        $tableName = $ast->from->getFullName();
        $table = $this->getTable($tableName);

        if ($table === null) {
            throw new \RuntimeException("Table not found: $tableName");
        }

        // Phase 1: No JOIN support yet
        if (!empty($ast->joins)) {
            throw new \RuntimeException("JOINs not yet supported in Phase 1");
        }

        // Apply WHERE - delegate to table backend
        if ($ast->where !== null) {
            $table = $this->applyWhereToTableInterface($table, $ast->where);
        }

        // Check for aggregate functions - requires different execution path
        if ($this->hasAggregates($ast->columns)) {
            yield from $this->executeAggregateSelect($ast, $table);
            return;
        }

        // Apply ORDER BY - delegate to table backend
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
     * Execute an aggregate SELECT (e.g., SELECT COUNT(*), SUM(price) FROM orders)
     *
     * Without GROUP BY: returns a single row with aggregate results.
     * GROUP BY support to be added in future phase.
     */
    private function executeAggregateSelect(SelectStatement $ast, TableInterface $table): iterable
    {
        // Phase 1: GROUP BY not yet supported for aggregates
        if (!empty($ast->groupBy)) {
            throw new \RuntimeException("GROUP BY not yet supported");
        }

        // Initialize aggregate contexts for each aggregate column
        $aggregateInfos = $this->collectAggregateInfos($ast->columns);

        // Step phase: iterate through all rows
        foreach ($table as $row) {
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
                $step = $aggregateInfos[$i]['step'];
                $step($aggregateInfos[$i]['context'], ...$args);
            }
        }

        // Final phase: build result row
        $result = new \stdClass();
        for ($i = 0; $i < count($aggregateInfos); $i++) {
            $final = $aggregateInfos[$i]['final'];
            $value = $final($aggregateInfos[$i]['context']);
            $result->{$aggregateInfos[$i]['name']} = $value;
        }

        yield $result;
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

        if (!empty($ast->joins)) {
            throw new \RuntimeException("JOINs in subqueries not yet supported");
        }

        // Apply WHERE
        if ($ast->where !== null) {
            $table = $this->applyWhereToTableInterface($table, $ast->where);
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
        return new \mini\Table\ColumnMappedSet($table, $subqueryColumn, $expectedColumn);
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

            $names[] = $col->expression->getName();
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

        // Filter rows where EXISTS evaluates to desired result
        $matchingIds = [];
        foreach ($table as $rowId => $row) {
            // Bind outer values
            $bindings = [];
            foreach ($outerRefs as $ref) {
                $outerColumn = $ref['column'];
                $paramName = ':outer_' . $ref['table'] . '_' . $outerColumn;
                $bindings[$paramName] = $row->$outerColumn;
            }

            $boundTable = $template->bind($bindings);
            $exists = $boundTable->exists();

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
            if ($colDef->index === \mini\Table\IndexType::Primary) {
                $pkColumn = $colName;
                break;
            }
        }

        if ($pkColumn !== null) {
            return $table->in($pkColumn, new \mini\Table\Set($pkColumn, $matchingIds));
        }

        // Fallback: iterate and collect manually (less efficient)
        $result = null;
        foreach ($matchingIds as $id) {
            $rowTable = $table->eq(array_key_first($columns), $id); // Approximate
            $result = $result === null ? $rowTable : $result->union($rowTable);
        }
        return $result ?? $table->except($table);
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

        // Check against both table name and alias
        $innerTables = [strtolower($subqueryTable), strtolower($subqueryAlias)];
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
                $outerContext[$key] = $row->{$ref['column']} ?? null;
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
            if ($colDef->index === \mini\Table\IndexType::Primary) {
                $pkColumn = $colName;
                break;
            }
        }

        if ($pkColumn !== null) {
            return $table->in($pkColumn, new \mini\Table\Set($pkColumn, $matchingIds));
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

            return match ($op) {
                '=' => $leftVal === $rightVal || $leftVal == $rightVal,
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

                // Check outer context first
                if (isset($outerContext[$key])) {
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
    private function buildCorrelatedTemplate(SelectStatement $ast, array $outerRefs): Table
    {
        $tableName = $ast->from->getFullName();
        $table = Table::from($this->getTable($tableName));

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
    private function applyWhereWithBinds(Table $table, \mini\Parsing\SQL\AST\ASTNode $node, array $outerRefs): Table
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
                    '=' => $table->eq($column, $value),
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

        $columnNames = array_map(fn($col) => $col->getName(), $ast->columns);
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
        $workingTable = $this->applyWhereToTable($table, $ast->where);
        return $workingTable->update($changes);
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

        $workingTable = $this->applyWhereToTable($table, $ast->where);
        return $workingTable->delete();
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

        // Binary OR: use table's or() method with predicates
        if ($node instanceof BinaryOperation && strtoupper($node->operator) === 'OR') {
            $leftPredicate = $this->buildPredicateFromAst($node->left);
            $rightPredicate = $this->buildPredicateFromAst($node->right);
            return $table->or($leftPredicate, $rightPredicate);
        }

        // Simple comparison: column op value
        if ($node instanceof BinaryOperation) {
            $op = $node->operator;

            // Left must be column, right must be literal
            if (!$node->left instanceof IdentifierNode) {
                throw new \RuntimeException("Left side of comparison must be a column");
            }
            if (!$node->right instanceof LiteralNode) {
                throw new \RuntimeException("Right side of comparison must be a literal value");
            }

            $column = $node->left->getName();
            $value = $this->evaluator->evaluate($node->right, null);

            return match ($op) {
                '=' => $table->eq($column, $value),
                '<' => $table->lt($column, $value),
                '<=' => $table->lte($column, $value),
                '>' => $table->gt($column, $value),
                '>=' => $table->gte($column, $value),
                '!=', '<>' => $table->except($table->eq($column, $value)),
                default => throw new \RuntimeException("Unsupported operator: $op"),
            };
        }

        // LIKE operation
        if ($node instanceof \mini\Parsing\SQL\AST\LikeOperation) {
            if (!$node->left instanceof IdentifierNode) {
                throw new \RuntimeException("Left side of LIKE must be a column");
            }
            $column = $node->left->getName();
            $pattern = $this->evaluator->evaluate($node->pattern, null);
            $result = $table->like($column, $pattern);
            return $node->negated ? $table->except($result) : $result;
        }

        // IS NULL operation
        if ($node instanceof \mini\Parsing\SQL\AST\IsNullOperation) {
            if (!$node->expression instanceof IdentifierNode) {
                throw new \RuntimeException("IS NULL expression must be a column");
            }
            $column = $node->expression->getName();
            $nullRows = $table->eq($column, null);
            return $node->negated ? $table->except($nullRows) : $nullRows;
        }

        // IN operation
        if ($node instanceof InOperation) {
            if (!$node->left instanceof IdentifierNode) {
                throw new \RuntimeException("Left side of IN must be a column");
            }
            $column = $node->left->getName();

            if ($node->isSubquery()) {
                // Subquery: execute and pass as SetInterface
                $set = $this->executeSubqueryAsSet($node->values, $column);
            } else {
                // Literal list: build in-memory Set
                $values = [];
                foreach ($node->values as $valueNode) {
                    $values[] = $this->evaluator->evaluate($valueNode, null);
                }
                $set = new \mini\Table\Set($column, $values);
            }

            $result = $table->in($column, $set);
            return $node->negated ? $table->except($result) : $result;
        }

        // EXISTS operation
        if ($node instanceof ExistsOperation) {
            return $this->applyExistsToTable($table, $node);
        }

        // BETWEEN operation
        if ($node instanceof \mini\Parsing\SQL\AST\BetweenOperation) {
            if (!$node->expression instanceof IdentifierNode) {
                throw new \RuntimeException("BETWEEN expression must be a column");
            }
            $column = $node->expression->getName();
            $low = $this->evaluator->evaluate($node->low, null);
            $high = $this->evaluator->evaluate($node->high, null);
            $result = $table->gte($column, $low)->lte($column, $high);
            return $node->negated ? $table->except($result) : $result;
        }

        // NOT expression: evaluate inner expression and exclude those rows
        if ($node instanceof UnaryOperation && strtoupper($node->operator) === 'NOT') {
            $matching = $this->applyWhereToTableInterface($table, $node->expression);
            return $table->except($matching);
        }

        throw new \RuntimeException("Unsupported WHERE expression: " . get_class($node));
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

            $column = $node->left->getName();
            $value = $this->evaluator->evaluate($node->right, null);

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
            $column = $node->left->getName();
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
            $column = $node->expression->getName();
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

            $column = $node->left->getName();
            $value = $this->evaluator->evaluate($node->right, null);

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
            $column = $node->left->getName();
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
            $column = $node->expression->getName();
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

            $parts[] = $colExpr->getName() . ' ' . $direction;
        }

        return $table->order(implode(', ', $parts));
    }

    /**
     * Apply a WHERE clause AST to a MutableTableInterface
     */
    private function applyWhereToTable(MutableTableInterface $table, ?\mini\Parsing\SQL\AST\ASTNode $where): MutableTableInterface
    {
        if ($where === null) {
            return $table;
        }

        $result = $this->applyWhereToTableInterface($table, $where);

        if (!$result instanceof MutableTableInterface) {
            throw new \RuntimeException("Filtered table must remain MutableTableInterface");
        }

        return $result;
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
}
