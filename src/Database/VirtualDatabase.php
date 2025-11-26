<?php

namespace mini\Database;

use mini\Database\Virtual\{VirtualTable, OrderInfo, WhereEvaluator, Collation};
use mini\Parsing\SQL\{SqlParser, SqlSyntaxException};
use mini\Parsing\SQL\AST\{
    ASTNode,
    SelectStatement,
    InsertStatement,
    UpdateStatement,
    DeleteStatement,
    IdentifierNode,
    LiteralNode,
    PlaceholderNode
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
    use PartialQueryableTrait;

    /** @var array<string, VirtualTable> */
    private array $tables = [];

    private SqlParser $parser;
    private \Collator $defaultCollator;

    public function __construct(?\Collator $defaultCollator = null)
    {
        $this->parser = new SqlParser();
        $this->defaultCollator = $defaultCollator ?? Collation::binary();
    }

    /**
     * Register a virtual table
     */
    public function registerTable(string $name, VirtualTable $table): void
    {
        $this->tables[$name] = $table;
    }

    /**
     * Execute SQL query
     *
     * Parses SQL and delegates to appropriate virtual table.
     */
    public function query(string $sql, array $params = []): iterable
    {
        try {
            $ast = $this->parser->parse($sql);
        } catch (SqlSyntaxException $e) {
            throw new \RuntimeException("SQL parse error: " . $e->getMessage(), 0, $e);
        }

        if ($ast instanceof SelectStatement) {
            return $this->executeSelect($ast, $params);
        }

        throw new \RuntimeException("Only SELECT queries supported in VirtualDatabase::query()");
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
        return SqlDialect::Generic;
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

    public function delete(PartialQuery $query): int
    {
        // Build DELETE SQL from PartialQuery
        $where = $query->getWhere();
        $sql = "DELETE FROM {$query->getTable()}";
        if (!empty($where['sql'])) {
            $sql .= " WHERE " . $where['sql'];
        }
        $sql .= " LIMIT " . $query->getLimit();

        return $this->exec($sql, $where['params']);
    }

    public function update(PartialQuery $query, string|array $set): int
    {
        // Build UPDATE SQL from PartialQuery
        $where = $query->getWhere();

        if (is_array($set)) {
            $setParts = [];
            $params = [];
            foreach ($set as $col => $val) {
                $setParts[] = "$col = ?";
                $params[] = $val;
            }
            $setClause = implode(', ', $setParts);
            $params = array_merge($params, $where['params']);
        } else {
            $setClause = $set;
            $params = $where['params'];
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

    private function executeSelect(SelectStatement $ast, array $params): iterable
    {
        $tableName = $ast->from->name;
        $table = $this->getTable($tableName);

        // Bind parameters to AST - replace placeholders with literal values
        // This makes it easier for virtual tables to inspect WHERE conditions
        $binder = new Parsing\SQL\AstParameterBinder($params);
        $boundAst = $binder->bind($ast);

        // Get rows from table (may include OrderInfo as first yield)
        // Virtual table receives AST with placeholders already replaced
        $iter = $table->select($boundAst, $this->defaultCollator);

        // Detect optional OrderInfo and validate row IDs
        $orderInfo = null;
        $dataIter = $this->extractOrderInfo($iter, $orderInfo, $tableName);

        // Execute with engine logic (still need params for WhereEvaluator)
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

        // Required collator for this query (use default unless table has specific one)
        $requiredCollator = $this->defaultCollator;

        // Determine if we can stream (checks collation compatibility!)
        $canStream = $this->canStreamResults($hasOrder, $orderBy, $orderInfo, $requiredCollator);

        // For execution: use backend's collator if compatible, otherwise use required
        // If streaming, we already verified compatibility above
        $collator = $canStream && $orderInfo
            ? Collation::fromName($orderInfo->collation)
            : $requiredCollator;

        if ($canStream) {
            // Streaming mode - pull rows as needed
            yield from $this->streamWithFilterLimit(
                $rows,
                $where,
                $params,
                $offset,
                $limit,
                $orderInfo?->skipped ?? 0,
                $collator
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
                $orderInfo?->skipped ?? 0,
                $collator
            );
        }
    }

    private function canStreamResults(bool $hasOrder, array $orderBy, ?OrderInfo $orderInfo, \Collator $requiredCollator): bool
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

        // CRITICAL: Match collation - backend sorting is only valid if collation matches!
        // We can only trust the ordering if the backend used the same collation rules.
        //
        // Example: Backend sorted with BINARY (case-sensitive), but we need NOCASE (case-insensitive):
        //   Backend order: ['Alice', 'Bob', 'alice']
        //   NOCASE order:  ['Alice', 'alice', 'Bob']
        // These are DIFFERENT orderings - we MUST re-sort!
        //
        // Canonicalize both collation names for comparison (handles no_NO === nb_NO, etc.)
        $requiredCollationName = Collation::toName($requiredCollator);
        $backendCollationName = strtoupper($orderInfo->collation) === 'BINARY' || strtoupper($orderInfo->collation) === 'NOCASE'
            ? strtoupper($orderInfo->collation)
            : \Locale::canonicalize($orderInfo->collation);

        if ($backendCollationName !== $requiredCollationName) {
            return false; // Different collation - must re-sort
        }

        return true;
    }

    private function streamWithFilterLimit(
        iterable $rows,
        ?ASTNode $where,
        array $params,
        int $offset,
        ?int $limit,
        int $backendSkipped,
        \Collator $collator
    ): \Generator {
        $evaluator = new Virtual\WhereEvaluator($params, $collator);
        $skipped = 0;
        $emitted = 0;

        // Adjust for backend-applied offset
        $effectiveOffset = max(0, $offset - $backendSkipped);

        foreach ($rows as $row) {
            // Always re-apply WHERE using collator
            if (!$evaluator->matches($row, $where)) {
                continue;
            }

            // Apply offset
            if ($skipped < $effectiveOffset) {
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
        int $backendSkipped,
        \Collator $collator
    ): \Generator {
        $evaluator = new Virtual\WhereEvaluator($params, $collator);

        // Materialize all matching rows
        $buffer = [];
        foreach ($rows as $row) {
            if ($evaluator->matches($row, $where)) {
                $buffer[] = $row;
            }
        }

        // Sort if needed - use collator
        if (!empty($orderBy)) {
            $this->sortRows($buffer, $orderBy, $collator);
        }

        // Apply backend skip
        if ($backendSkipped > 0) {
            $buffer = array_slice($buffer, $backendSkipped);
        }

        // Apply offset
        if ($offset > 0) {
            $buffer = array_slice($buffer, $offset);
        }

        // Apply limit
        if ($limit !== null) {
            $buffer = array_slice($buffer, 0, $limit);
        }

        yield from $buffer;
    }

    private function sortRows(array &$rows, array $orderBy, \Collator $collator): void
    {
        usort($rows, function($a, $b) use ($orderBy, $collator) {
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

                // Use collator for comparison
                $cmp = Collation::compare($collator, $valA, $valB);
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
        $evaluator = new Virtual\WhereEvaluator($params, $this->defaultCollator);

        $affectedRows = 0;

        // Extract column names
        $columns = array_map(fn($col) => $col->name, $ast->columns);

        // Process each value row
        foreach ($ast->values as $valueRow) {
            $row = [];

            // Evaluate each value expression
            foreach ($valueRow as $i => $valueExpr) {
                $columnName = $columns[$i] ?? $i;

                // Use WhereEvaluator's getValue for consistency
                // We need to make getValue accessible, but for now, let's do direct evaluation
                $row[$columnName] = $this->evaluateExpression($valueExpr, $params);
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

        // Get collator for this table
        $collator = $table->getDefaultCollator();

        // Bind parameters to SELECT (for WHERE evaluation)
        $binder = new Parsing\SQL\AstParameterBinder($params);
        $boundSelect = $binder->bind($select);

        // Get all rows from table (we need row IDs)
        $iter = $table->select($boundSelect, $collator);

        // Extract OrderInfo if present and validate row IDs
        $orderInfo = null;
        $dataIter = $this->extractOrderInfo($iter, $orderInfo, $tableName);

        // Use OrderInfo collation if available
        if ($orderInfo && $orderInfo->collation) {
            $collator = Collation::fromName($orderInfo->collation);
        }

        // Evaluate WHERE to find matching row IDs
        $evaluator = new Virtual\WhereEvaluator($params, $collator);
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
        $changes = [];
        foreach ($ast->updates as $update) {
            $columnName = $update['column']->name;
            $changes[$columnName] = $this->evaluateExpression($update['value'], $params);
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

        // Get collator for this table
        $collator = $table->getDefaultCollator();

        // Bind parameters to SELECT (for WHERE evaluation)
        $binder = new Parsing\SQL\AstParameterBinder($params);
        $boundSelect = $binder->bind($select);

        // Get all rows from table (we need row IDs)
        $iter = $table->select($boundSelect, $collator);

        // Extract OrderInfo if present and validate row IDs
        $orderInfo = null;
        $dataIter = $this->extractOrderInfo($iter, $orderInfo, $tableName);

        // Use OrderInfo collation if available
        if ($orderInfo && $orderInfo->collation) {
            $collator = Collation::fromName($orderInfo->collation);
        }

        // Evaluate WHERE to find matching row IDs
        $evaluator = new Virtual\WhereEvaluator($params, $collator);
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
     */
    private function evaluateExpression(ASTNode $expr, array $params): mixed
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
                // Positional - need to track index
                static $index = 0;
                return $params[$index++] ?? null;
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
