<?php

namespace mini\Database;

use mini\Database\Virtual\{VirtualTable, OrderInfo, WhereEvaluator, LazySubquery};
use mini\Parsing\SQL\{SqlParser, SqlSyntaxException, AstParameterBinder};
use mini\Parsing\SQL\AST\{
    ASTNode,
    SelectStatement,
    InsertStatement,
    UpdateStatement,
    DeleteStatement,
    IdentifierNode,
    LiteralNode,
    PlaceholderNode,
    SubqueryNode,
    ColumnNode
};

/**
 * Virtual Database - SQL interface to non-SQL data sources
 *
 * Parses SQL queries and delegates to registered virtual tables.
 * Provides smart execution:
 * - Streams results when possible (ORDER BY matches backend ordering)
 * - Materializes and sorts when necessary
 * - Always re-applies WHERE filtering
 * - Handles LIMIT/OFFSET efficiently
 *
 * Example usage:
 * ```php
 * $vdb = new VirtualDatabase();
 *
 * $vdb->registerTable('countries', new VirtualTable(
 *     selectFn: function(SelectStatement $ast): iterable {
 *         // Optional: yield ordering info
 *         yield new OrderInfo(column: 'code', desc: false);
 *
 *         // Yield rows
 *         foreach ($this->getCountries() as $row) {
 *             yield $row;
 *         }
 *     }
 * ));
 *
 * // Use like normal database
 * $results = vdb()->query("SELECT * FROM countries WHERE continent = ?", ['Europe']);
 * ```
 */
class VirtualDatabase implements DatabaseInterface
{
    /** @var array<string, VirtualTable> */
    private array $tables = [];

    private SqlParser $parser;

    public function __construct()
    {
        $this->parser = new SqlParser();
    }

    /**
     * Register a virtual table
     */
    public function registerTable(string $name, VirtualTable $table): void
    {
        $this->tables[$name] = $table;
    }

    /**
     * Execute a SELECT query and return results as ResultSet
     */
    public function query(string $sql, array $params = []): ResultSetInterface
    {
        try {
            $ast = $this->parser->parse($sql);
        } catch (SqlSyntaxException $e) {
            throw new \RuntimeException("SQL parse error: " . $e->getMessage(), 0, $e);
        }

        if (!($ast instanceof SelectStatement)) {
            throw new \RuntimeException("Only SELECT queries supported in VirtualDatabase::query()");
        }

        return new ResultSet($this->executeSelect($ast, $params));
    }

    /**
     * Create a PartialQuery for composable query building
     */
    public function partialQuery(string $table, ?string $sql = null, array $params = []): PartialQuery
    {
        return PartialQuery::from($this, $table, $sql, $params);
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        foreach ($this->query($sql, $params) as $row) {
            return $row;
        }
        return null;
    }

    public function queryField(string $sql, array $params = []): mixed
    {
        $row = $this->queryOne($sql, $params);
        return $row ? array_values($row)[0] : null;
    }

    public function queryColumn(string $sql, array $params = []): array
    {
        $column = [];
        foreach ($this->query($sql, $params) as $row) {
            $column[] = array_values($row)[0];
        }
        return $column;
    }

    public function exec(string $sql, array $params = []): int
    {
        try {
            $ast = $this->parser->parse($sql);
        } catch (SqlSyntaxException $e) {
            throw new \RuntimeException("SQL parse error: " . $e->getMessage(), 0, $e);
        }

        if ($ast instanceof InsertStatement) {
            return $this->executeInsert($ast, $params);
        }

        if ($ast instanceof UpdateStatement) {
            return $this->executeUpdate($ast, $params);
        }

        if ($ast instanceof DeleteStatement) {
            return $this->executeDelete($ast, $params);
        }

        throw new \RuntimeException("Unsupported statement type: " . get_class($ast));
    }

    public function lastInsertId(): ?string
    {
        // Virtual tables don't track last insert ID globally
        return null;
    }

    public function tableExists(string $tableName): bool
    {
        return isset($this->tables[$tableName]);
    }

    public function transaction(\Closure $task): mixed
    {
        // Virtual database doesn't support transactions
        throw new \RuntimeException("Transactions not supported in VirtualDatabase");
    }

    public function getDialect(): SqlDialect
    {
        return SqlDialect::Virtual;
    }

    public function quote(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }

    public function quoteIdentifier(string $identifier): string
    {
        // Handle dotted identifiers (e.g., "table.column")
        if (str_contains($identifier, '.')) {
            return implode('.', array_map(fn($part) => $this->quoteIdentifier($part), explode('.', $identifier)));
        }

        // Use standard double quotes for generic SQL
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function delete(PartialQuery $query): int
    {
        // Build DELETE SQL from PartialQuery
        $where = $query->getWhere();
        $sql = "DELETE FROM {$query->getTable()}";
        if (!empty($where['sql'])) {
            $sql .= " WHERE " . $where['sql'];
        }
        $limit = $query->getLimit();
        if ($limit !== null) {
            $sql .= " LIMIT " . $limit;
        }

        return $this->exec($sql, $where['params']);
    }

    public function update(PartialQuery $query, string|array $set, array $params = []): int
    {
        // Build UPDATE SQL from PartialQuery
        $where = $query->getWhere();

        if (is_array($set)) {
            $setParts = [];
            $setParams = [];
            foreach ($set as $col => $val) {
                $setParts[] = "$col = ?";
                $setParams[] = $val;
            }
            $setClause = implode(', ', $setParts);
            $params = array_merge($setParams, $where['params']);
        } else {
            $setClause = $set;
            $params = array_merge($params, $where['params']);
        }

        $sql = "UPDATE {$query->getTable()} SET $setClause";
        if (!empty($where['sql'])) {
            $sql .= " WHERE " . $where['sql'];
        }

        return $this->exec($sql, $params);
    }

    public function insert(string $table, array $data): string
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";

        $this->exec($sql, array_values($data));
        return $this->lastInsertId() ?? '';
    }

    public function upsert(string $table, array $data, string ...$conflictColumns): int
    {
        throw new \RuntimeException("UPSERT not supported in VirtualDatabase");
    }

    // --- Private execution methods ---

    private function getTable(string $name): VirtualTable
    {
        if (!isset($this->tables[$name])) {
            throw new \RuntimeException("Virtual table '$name' not registered");
        }
        return $this->tables[$name];
    }

    /**
     * Create a subquery resolver closure for WhereEvaluator
     *
     * Returns a closure that creates LazySubquery instances.
     * The LazySubquery will execute the subquery on demand and cache results.
     *
     * @param array $params Parent query parameters (not used for subqueries which have their own scope)
     * @return \Closure fn(SubqueryNode): LazySubquery
     */
    private function createSubqueryResolver(array $params): \Closure
    {
        return function (SubqueryNode $subqueryNode): LazySubquery {
            // Extract column name from SELECT clause
            $column = $this->extractSubqueryColumn($subqueryNode->query);

            // Create executor that will run the subquery
            $executor = function (SelectStatement $query): iterable {
                return $this->executeSelect($query, []);
            };

            return new LazySubquery($subqueryNode->query, $executor, $column);
        };
    }

    /**
     * Extract the column name to use from a subquery's SELECT clause
     *
     * For "SELECT user_id FROM orders", returns "user_id"
     * For "SELECT *", returns "*" (will use first column)
     */
    private function extractSubqueryColumn(SelectStatement $query): string
    {
        if (empty($query->columns)) {
            return '*';
        }

        $firstColumn = $query->columns[0];
        if ($firstColumn instanceof ColumnNode) {
            $expr = $firstColumn->expression;
            if ($expr instanceof IdentifierNode) {
                // Handle dotted identifiers (table.column)
                $parts = explode('.', $expr->name);
                return end($parts);
            }
        }

        return '*';
    }

    private function executeSelect(SelectStatement $ast, array $params): iterable
    {
        $tableName = $ast->from->name;
        $table = $this->getTable($tableName);

        // Bind parameters to AST - replace placeholders with literal values
        // This makes it easier for virtual tables to inspect WHERE conditions
        $binder = new AstParameterBinder($params);
        $boundAst = $binder->bind($ast);

        // Get rows from table (may include OrderInfo as first yield)
        // Virtual table receives AST with placeholders already replaced
        $iter = $table->select($boundAst);

        // Detect optional OrderInfo and validate row IDs
        $orderInfo = null;
        $dataIter = $this->extractOrderInfo($iter, $orderInfo, $tableName);

        // Execute with engine logic
        return $this->selectWithEngineLogic($boundAst, $dataIter, $orderInfo, $params);
    }

    /**
     * Extract optional OrderInfo from start of iterator and validate row IDs
     *
     * @param iterable $iter Original iterator
     * @param OrderInfo|null &$orderInfo Output parameter for OrderInfo
     * @param string $tableName Table name for error messages
     * @return \Generator Data rows only (rowId => row)
     * @throws Virtual\VirtualTableException if row IDs are missing or invalid
     */
    private function extractOrderInfo(iterable $iter, ?OrderInfo &$orderInfo, string $tableName): \Generator
    {
        $first = true;
        $seenIds = [];
        $rowNumber = 0;

        foreach ($iter as $item) {
            // Handle optional OrderInfo as first yield
            if ($first && $item instanceof OrderInfo) {
                $orderInfo = $item;
                $first = false;
                continue;
            }
            $first = false;

            // Validate: must yield Row instances
            if (!($item instanceof Virtual\Row)) {
                throw Virtual\VirtualTableException::notRowInstance($tableName, $rowNumber, $item);
            }

            // Validate: row ID must be unique
            $rowId = $item->id;
            if (isset($seenIds[$rowId])) {
                throw Virtual\VirtualTableException::duplicateRowId($tableName, $rowId);
            }
            $seenIds[$rowId] = true;

            $rowNumber++;
            // Yield rowId => columns array for compatibility with existing code
            yield $rowId => $item->columns;
        }
    }

    /**
     * Execute SELECT with smart streaming/materialization
     */
    private function selectWithEngineLogic(
        SelectStatement $ast,
        iterable $rows,
        ?OrderInfo $orderInfo,
        array $params
    ): iterable {
        $where = $ast->where;
        $orderBy = $ast->orderBy ?? [];
        $limit = $ast->limit;
        $offset = 0; // TODO: Add OFFSET support to parser

        $hasOrder = !empty($orderBy);

        // Determine if we can stream
        $canStream = $this->canStreamResults($hasOrder, $orderBy, $orderInfo);

        // If skipped is null, backend handled offset - don't apply it again
        $backendSkipped = $orderInfo?->skipped;

        if ($canStream) {
            // Streaming mode - pull rows as needed
            yield from $this->streamWithFilterLimit(
                $rows,
                $where,
                $params,
                $offset,
                $limit,
                $backendSkipped
            );
        } else {
            // Materialization mode - load all, sort, then output
            yield from $this->materializeAndSort(
                $rows,
                $where,
                $params,
                $orderBy,
                $offset,
                $limit,
                $backendSkipped
            );
        }
    }

    private function canStreamResults(bool $hasOrder, array $orderBy, ?OrderInfo $orderInfo): bool
    {
        if (!$hasOrder) {
            return true; // No ORDER BY - always stream
        }

        if ($orderInfo === null) {
            return false; // ORDER BY present but no backend ordering - must materialize
        }

        // Check if first ORDER BY matches backend ordering
        $firstOrder = $orderBy[0];
        $colExpr = $firstOrder['column'];

        if (!($colExpr instanceof IdentifierNode)) {
            return false; // Complex expression - can't match
        }

        // Match column name
        $colName = $colExpr->name;
        $parts = explode('.', $colName);
        $columnName = end($parts);

        if ($columnName !== $orderInfo->column) {
            return false; // Different column
        }

        // Match direction
        $isAsc = ($firstOrder['direction'] === 'ASC');
        $backendIsAsc = !$orderInfo->desc;

        if ($isAsc !== $backendIsAsc) {
            return false; // Different direction
        }

        return true;
    }

    private function streamWithFilterLimit(
        iterable $rows,
        ?ASTNode $where,
        array $params,
        int $offset,
        ?int $limit,
        ?int $backendSkipped
    ): \Generator {
        $skipped = 0;
        $emitted = 0;

        // backendSkipped !== null means backend handled WHERE (possibly with custom collation)
        // backendSkipped === null means we need to apply WHERE
        $applyWhere = $backendSkipped === null;
        $evaluator = $applyWhere
            ? new WhereEvaluator($params, $this->createSubqueryResolver($params))
            : null;

        // Calculate how many rows we need to skip
        if ($backendSkipped === null) {
            // Backend didn't handle offset - we skip all
            $toSkip = $offset;
        } else {
            // Backend skipped some rows - validate and adjust
            if ($backendSkipped > $offset) {
                throw new \RuntimeException(
                    "Virtual table skipped {$backendSkipped} rows but only {$offset} were requested. " .
                    "This is an implementation error in the virtual table."
                );
            }
            $toSkip = $offset - $backendSkipped;
        }

        foreach ($rows as $row) {
            // Apply WHERE only if backend didn't handle it
            if ($applyWhere && !$evaluator->matches($row, $where)) {
                continue;
            }

            // Skip rows if needed
            if ($skipped < $toSkip) {
                $skipped++;
                continue;
            }

            yield $row;
            $emitted++;

            // Early stop when limit reached
            if ($limit !== null && $emitted >= $limit) {
                break;
            }
        }
    }

    private function materializeAndSort(
        iterable $rows,
        ?ASTNode $where,
        array $params,
        array $orderBy,
        int $offset,
        ?int $limit,
        ?int $backendSkipped
    ): \Generator {
        // backendSkipped !== null means backend handled WHERE (possibly with custom collation)
        // backendSkipped === null means we need to apply WHERE
        $applyWhere = $backendSkipped === null;
        $evaluator = $applyWhere
            ? new WhereEvaluator($params, $this->createSubqueryResolver($params))
            : null;

        // Materialize all matching rows
        $buffer = [];
        foreach ($rows as $row) {
            if ($applyWhere && !$evaluator->matches($row, $where)) {
                continue;
            }
            $buffer[] = $row;
        }

        // Sort if needed
        if (!empty($orderBy)) {
            $this->sortRows($buffer, $orderBy);
        }

        // Calculate how many rows to skip
        if ($backendSkipped === null) {
            // Backend didn't handle offset - we skip all
            $toSkip = $offset;
        } else {
            // Backend skipped some rows - validate and adjust
            if ($backendSkipped > $offset) {
                throw new \RuntimeException(
                    "Virtual table skipped {$backendSkipped} rows but only {$offset} were requested. " .
                    "This is an implementation error in the virtual table."
                );
            }
            $toSkip = $offset - $backendSkipped;
        }

        // Apply offset
        if ($toSkip > 0) {
            $buffer = array_slice($buffer, $toSkip);
        }

        // Apply limit
        if ($limit !== null) {
            $buffer = array_slice($buffer, 0, $limit);
        }

        yield from $buffer;
    }

    private function sortRows(array &$rows, array $orderBy): void
    {
        usort($rows, function($a, $b) use ($orderBy) {
            foreach ($orderBy as $order) {
                $colExpr = $order['column'];
                $dir = $order['direction'];

                // Get column name
                if ($colExpr instanceof IdentifierNode) {
                    $parts = explode('.', $colExpr->name);
                    $col = end($parts);
                } else {
                    continue; // Skip complex expressions
                }

                $valA = $a[$col] ?? null;
                $valB = $b[$col] ?? null;

                // Simple comparison (no collation)
                $cmp = $valA <=> $valB;
                if ($cmp !== 0) {
                    return $dir === 'DESC' ? -$cmp : $cmp;
                }
            }
            return 0;
        });
    }

    // --- DML execution methods ---

    /**
     * Execute INSERT statement
     *
     * Extracts row data from AST and delegates to virtual table.
     */
    private function executeInsert(InsertStatement $ast, array $params): int
    {
        $table = $this->getTable($ast->table->name);

        $affectedRows = 0;

        // Extract column names
        $columns = array_map(fn($col) => $col->name, $ast->columns);

        // Process each value row
        $placeholderIndex = 0;
        foreach ($ast->values as $valueRow) {
            $row = [];

            // Evaluate each value expression
            foreach ($valueRow as $i => $valueExpr) {
                $columnName = $columns[$i] ?? $i;
                $row[$columnName] = $this->evaluateExpression($valueExpr, $params, $placeholderIndex);
            }

            // Insert the row
            $table->insert($row);
            $affectedRows++;
        }

        return $affectedRows;
    }

    /**
     * Execute UPDATE statement
     *
     * Performs SELECT to find matching rows, then delegates UPDATE to virtual table.
     */
    private function executeUpdate(UpdateStatement $ast, array $params): int
    {
        $tableName = $ast->table->name;
        $table = $this->getTable($tableName);

        // Create a SELECT to find all matching rows
        $select = new SelectStatement();
        $select->from = $ast->table;
        $select->where = $ast->where;

        // Bind parameters to SELECT (for WHERE evaluation)
        $binder = new AstParameterBinder($params);
        $boundSelect = $binder->bind($select);

        // Get all rows from table (we need row IDs)
        $iter = $table->select($boundSelect);

        // Extract OrderInfo if present and validate row IDs
        $orderInfo = null;
        $dataIter = $this->extractOrderInfo($iter, $orderInfo, $tableName);

        // Evaluate WHERE to find matching row IDs
        $evaluator = new WhereEvaluator($params, $this->createSubqueryResolver($params));
        $matchingRowIds = [];

        foreach ($dataIter as $rowId => $row) {
            if ($evaluator->matches($row, $ast->where)) {
                $matchingRowIds[] = $rowId;
            }
        }

        if (empty($matchingRowIds)) {
            return 0;
        }

        // Extract changes from UPDATE SET clause
        $placeholderIndex = 0;
        $changes = [];
        foreach ($ast->updates as $update) {
            $columnName = $update['column']->name;
            $changes[$columnName] = $this->evaluateExpression($update['value'], $params, $placeholderIndex);
        }

        // Delegate to virtual table
        return $table->update($matchingRowIds, $changes);
    }

    /**
     * Execute DELETE statement
     *
     * Performs SELECT to find matching rows, then delegates DELETE to virtual table.
     */
    private function executeDelete(DeleteStatement $ast, array $params): int
    {
        $tableName = $ast->table->name;
        $table = $this->getTable($tableName);

        // Create a SELECT to find all matching rows
        $select = new SelectStatement();
        $select->from = $ast->table;
        $select->where = $ast->where;

        // Bind parameters to SELECT (for WHERE evaluation)
        $binder = new AstParameterBinder($params);
        $boundSelect = $binder->bind($select);

        // Get all rows from table (we need row IDs)
        $iter = $table->select($boundSelect);

        // Extract OrderInfo if present and validate row IDs
        $orderInfo = null;
        $dataIter = $this->extractOrderInfo($iter, $orderInfo, $tableName);

        // Evaluate WHERE to find matching row IDs
        $evaluator = new WhereEvaluator($params, $this->createSubqueryResolver($params));
        $matchingRowIds = [];

        foreach ($dataIter as $rowId => $row) {
            if ($evaluator->matches($row, $ast->where)) {
                $matchingRowIds[] = $rowId;
            }
        }

        if (empty($matchingRowIds)) {
            return 0;
        }

        // Delegate to virtual table
        return $table->delete($matchingRowIds);
    }

    /**
     * Evaluate an expression to a value (for INSERT/UPDATE)
     *
     * Handles literals, placeholders, and simple expressions.
     *
     * @param ASTNode $expr The expression to evaluate
     * @param array $params The bound parameters
     * @param int &$placeholderIndex Current positional placeholder index (modified)
     */
    private function evaluateExpression(ASTNode $expr, array $params, int &$placeholderIndex = 0): mixed
    {
        if ($expr instanceof LiteralNode) {
            if ($expr->valueType === 'number') {
                return str_contains($expr->value, '.')
                    ? (float)$expr->value
                    : (int)$expr->value;
            }
            return $expr->value;
        }

        if ($expr instanceof PlaceholderNode) {
            if ($expr->token === '?') {
                // Positional - track index via reference
                return $params[$placeholderIndex++] ?? null;
            } else {
                // Named placeholder
                $name = ltrim($expr->token, ':');
                return $params[$name] ?? null;
            }
        }

        // For other expressions, we'd need more evaluation logic
        // For now, throw an error
        throw new \RuntimeException("Cannot evaluate expression type: " . get_class($expr));
    }
}
