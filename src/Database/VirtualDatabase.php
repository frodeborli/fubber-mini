<?php

namespace mini\Database;

use mini\Table\TableInterface;
use mini\Table\MutableTableInterface;
use mini\Table\Set;
use mini\Parsing\SQL\{SqlParser, SqlSyntaxException};
use mini\Parsing\SQL\AST\{
    ASTNode,
    SelectStatement,
    InsertStatement,
    UpdateStatement,
    DeleteStatement,
    IdentifierNode,
    LiteralNode,
    PlaceholderNode,
    BinaryOperation,
    UnaryOperation,
    InOperation,
    IsNullOperation,
    LikeOperation,
    ColumnNode
};

/**
 * Virtual Database - SQL interface to TableInterface implementations
 *
 * Parses SQL queries and translates them to TableInterface method calls.
 *
 * Example usage:
 * ```php
 * $vdb = new VirtualDatabase();
 *
 * // Register a PartialQuery as a virtual table
 * $vdb->registerTable('active_users', db()->from('users')->eq('active', 1));
 *
 * // Register a FilteredTable
 * $vdb->registerTable('countries', new FilteredTable(
 *     source: fn() => $this->readCsv('countries.csv')
 * ));
 *
 * // Query with SQL
 * foreach ($vdb->query("SELECT * FROM active_users WHERE age > ?", [25]) as $row) {
 *     echo $row['name'];
 * }
 * ```
 */
class VirtualDatabase implements DatabaseInterface
{
    /** @var array<string, TableInterface> */
    private array $tables = [];

    private SqlParser $parser;

    public function __construct()
    {
        $this->parser = new SqlParser();
    }

    /**
     * Register a virtual table
     */
    public function registerTable(string $name, TableInterface $table): void
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

    public function queryOne(string $sql, array $params = []): ?object
    {
        foreach ($this->query($sql, $params) as $row) {
            return $row;
        }
        return null;
    }

    public function queryField(string $sql, array $params = []): mixed
    {
        $row = $this->queryOne($sql, $params);
        if ($row === null) {
            return null;
        }
        $vars = get_object_vars($row);
        return $vars ? reset($vars) : null;
    }

    public function queryColumn(string $sql, array $params = []): array
    {
        $column = [];
        foreach ($this->query($sql, $params) as $row) {
            $vars = get_object_vars($row);
            $column[] = $vars ? reset($vars) : null;
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
        return null;
    }

    public function tableExists(string $tableName): bool
    {
        return isset($this->tables[$tableName]);
    }

    public function transaction(\Closure $task): mixed
    {
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
        if (str_contains($identifier, '.')) {
            return implode('.', array_map(fn($part) => $this->quoteIdentifier($part), explode('.', $identifier)));
        }
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function delete(PartialQuery $query): int
    {
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

    private function getTable(string $name): TableInterface
    {
        if (!isset($this->tables[$name])) {
            throw new \RuntimeException("Virtual table '$name' not registered");
        }
        return $this->tables[$name];
    }

    /**
     * Execute SELECT by translating AST to TableInterface calls
     */
    private function executeSelect(SelectStatement $ast, array $params): iterable
    {
        $tableName = $ast->from->name;
        $table = $this->getTable($tableName);

        // Apply WHERE clause
        $paramIndex = 0;
        $table = $this->applyWhere($table, $ast->where, $params, $paramIndex);

        // Apply ORDER BY
        if (!empty($ast->orderBy)) {
            foreach ($ast->orderBy as $order) {
                $column = $this->getColumnName($order['column']);
                $dir = $order['direction'] ?? 'ASC';
                $table = $table->order("$column $dir");
            }
        }

        // Apply LIMIT
        if ($ast->limit !== null) {
            $table = $table->limit($ast->limit);
        }

        // Yield rows
        foreach ($table as $id => $row) {
            yield $id => $row;
        }
    }

    /**
     * Apply WHERE clause to table, returning filtered table
     */
    private function applyWhere(TableInterface $table, ?ASTNode $where, array $params, int &$paramIndex, TableInterface $baseTable = null): TableInterface
    {
        if ($where === null) {
            return $table;
        }

        // Keep reference to base table for negation operations
        $baseTable ??= $table;

        // Binary operations (=, <, >, AND, OR, etc.)
        if ($where instanceof BinaryOperation) {
            return $this->applyBinaryOp($table, $where, $params, $paramIndex, $baseTable);
        }

        // IS NULL / IS NOT NULL
        if ($where instanceof IsNullOperation) {
            $column = $this->getColumnName($where->expression);
            $result = $table->eq($column, null);
            if ($where->negated) {
                // IS NOT NULL = all rows except nulls
                return $table->except($result);
            }
            return $result;
        }

        // IN clause
        if ($where instanceof InOperation) {
            if ($where->isSubquery()) {
                throw new \RuntimeException("IN with subquery not yet supported in VirtualDatabase");
            }

            $column = $this->getColumnName($where->left);

            // Build a Set from the IN values
            $values = [];
            foreach ($where->values as $v) {
                $values[] = $this->evaluateExpression($v, $params, $paramIndex);
            }

            $result = $table->in($column, new Set($values));
            if ($where->negated) {
                // NOT IN = all rows except those in set
                return $table->except($result);
            }
            return $result;
        }

        // LIKE clause
        if ($where instanceof LikeOperation) {
            $column = $this->getColumnName($where->left);
            $pattern = $this->evaluateExpression($where->pattern, $params, $paramIndex);
            $result = $table->like($column, $pattern);
            if ($where->negated) {
                // NOT LIKE = all rows except matches
                return $table->except($result);
            }
            return $result;
        }

        // NOT expression
        if ($where instanceof UnaryOperation && strtoupper($where->operator) === 'NOT') {
            // NOT expr = baseTable except expr
            $inner = $this->applyWhere($table, $where->operand, $params, $paramIndex, $baseTable);
            return $table->except($inner);
        }

        throw new \RuntimeException("Unsupported WHERE clause type: " . get_class($where));
    }

    /**
     * Apply binary operation (=, <, >, <=, >=, AND, OR)
     */
    private function applyBinaryOp(TableInterface $table, BinaryOperation $node, array $params, int &$paramIndex, TableInterface $baseTable): TableInterface
    {
        $op = strtoupper($node->operator);

        // AND: chain filters (fast - filters progressively smaller set)
        if ($op === 'AND') {
            $table = $this->applyWhere($table, $node->left, $params, $paramIndex, $baseTable);
            return $this->applyWhere($table, $node->right, $params, $paramIndex, $baseTable);
        }

        // OR: union of both sides (uses TableInterface::union())
        if ($op === 'OR') {
            // Apply each side to the current table independently
            // Save paramIndex to replay for right side
            $leftParamIndex = $paramIndex;
            $left = $this->applyWhere($table, $node->left, $params, $paramIndex, $baseTable);
            $right = $this->applyWhere($table, $node->right, $params, $leftParamIndex, $baseTable);
            return $left->union($right);
        }

        // Comparison operators: column op value
        $column = $this->getColumnName($node->left);
        $value = $this->evaluateExpression($node->right, $params, $paramIndex);

        return match ($op) {
            '=' => $table->eq($column, $value),
            '<' => $table->lt($column, $value),
            '<=' => $table->lte($column, $value),
            '>' => $table->gt($column, $value),
            '>=' => $table->gte($column, $value),
            '!=', '<>' => $table->except($table->eq($column, $value)),
            'LIKE' => $table->like($column, $value),
            default => throw new \RuntimeException("Unsupported operator: $op"),
        };
    }

    /**
     * Evaluate expression to get a value (for comparisons)
     */
    private function evaluateExpression(ASTNode $expr, array $params, int &$paramIndex): mixed
    {
        if ($expr instanceof LiteralNode) {
            if ($expr->valueType === 'number') {
                return str_contains($expr->value, '.') ? (float)$expr->value : (int)$expr->value;
            }
            return $expr->value;
        }

        if ($expr instanceof PlaceholderNode) {
            if ($expr->token === '?') {
                return $params[$paramIndex++] ?? null;
            }
            $name = ltrim($expr->token, ':');
            return $params[$name] ?? null;
        }

        if ($expr instanceof IdentifierNode) {
            // Column reference without row context - shouldn't happen in value position
            throw new \RuntimeException("Cannot evaluate column reference without row context");
        }

        throw new \RuntimeException("Cannot evaluate expression type: " . get_class($expr));
    }

    /**
     * Get column name from an AST node
     */
    private function getColumnName(ASTNode $node): string
    {
        if ($node instanceof IdentifierNode) {
            $parts = explode('.', $node->name);
            return end($parts);
        }

        if ($node instanceof ColumnNode) {
            return $this->getColumnName($node->expression);
        }

        throw new \RuntimeException("Expected column reference, got: " . get_class($node));
    }

    // --- DML execution methods ---

    private function executeInsert(InsertStatement $ast, array $params): int
    {
        $tableName = $ast->table->name;
        $table = $this->getTable($tableName);

        if (!($table instanceof MutableTableInterface)) {
            throw new \RuntimeException("Table '$tableName' does not support INSERT");
        }

        $columns = array_map(fn($col) => $col->name, $ast->columns);
        $affectedRows = 0;
        $paramIndex = 0;

        foreach ($ast->values as $valueRow) {
            $row = [];
            foreach ($valueRow as $i => $valueExpr) {
                $columnName = $columns[$i] ?? $i;
                $row[$columnName] = $this->evaluateExpression($valueExpr, $params, $paramIndex);
            }
            $table->insert($row);
            $affectedRows++;
        }

        return $affectedRows;
    }

    private function executeUpdate(UpdateStatement $ast, array $params): int
    {
        $tableName = $ast->table->name;
        $table = $this->getTable($tableName);

        if (!($table instanceof MutableTableInterface)) {
            throw new \RuntimeException("Table '$tableName' does not support UPDATE");
        }

        // Apply WHERE to find matching rows
        $paramIndex = 0;

        // First, extract SET values (they come before WHERE params)
        $changes = [];
        foreach ($ast->updates as $update) {
            $columnName = $update['column']->name;
            $changes[$columnName] = $this->evaluateExpression($update['value'], $params, $paramIndex);
        }

        // Apply WHERE filter
        $filtered = $this->applyWhere($table, $ast->where, $params, $paramIndex);

        // Update matching rows
        return $filtered->update($changes);
    }

    private function executeDelete(DeleteStatement $ast, array $params): int
    {
        $tableName = $ast->table->name;
        $table = $this->getTable($tableName);

        if (!($table instanceof MutableTableInterface)) {
            throw new \RuntimeException("Table '$tableName' does not support DELETE");
        }

        // Apply WHERE filter
        $paramIndex = 0;
        $filtered = $this->applyWhere($table, $ast->where, $params, $paramIndex);

        // Delete matching rows
        return $filtered->delete();
    }
}
