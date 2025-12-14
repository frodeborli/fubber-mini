<?php

namespace mini\Database;

use mini\Parsing\SQL\SqlParser;
use mini\Parsing\SQL\AstParameterBinder;
use mini\Parsing\SQL\AST\{
    SelectStatement,
    InsertStatement,
    UpdateStatement,
    DeleteStatement,
    ColumnNode,
    IdentifierNode,
    LiteralNode,
    BinaryOperation,
    LikeOperation,
    IsNullOperation,
    InOperation,
    BetweenOperation
};
use mini\Table\{TableInterface, MutableTableInterface};

/**
 * Virtual database that executes SQL against registered TableInterface instances
 *
 * Phase 1: Single-table operations
 * - SELECT with WHERE, ORDER BY, LIMIT, column projection
 * - INSERT, UPDATE, DELETE on MutableTableInterface
 *
 * Future phases will add JOINs, aggregates, DISTINCT, subqueries.
 *
 * Usage:
 * ```php
 * $vdb = new VirtualDatabase();
 * $vdb->registerTable('users', $usersTable);
 * $vdb->registerTable('orders', $ordersTable);
 *
 * // SELECT queries return an iterable of stdClass
 * foreach ($vdb->query('SELECT name, email FROM users WHERE status = ?', ['active']) as $row) {
 *     echo $row->name;
 * }
 *
 * // INSERT/UPDATE/DELETE return affected row count
 * $affected = $vdb->exec('DELETE FROM users WHERE id = ?', [123]);
 * ```
 */
class VirtualDatabase
{
    /** @var array<string, TableInterface> */
    private array $tables = [];

    private ExpressionEvaluator $evaluator;

    public function __construct()
    {
        $this->evaluator = new ExpressionEvaluator();
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
     * Execute a SELECT query
     *
     * @param string $sql SQL query
     * @param array $params Bound parameters
     * @return iterable<object> Rows as stdClass objects
     */
    public function query(string $sql, array $params = []): iterable
    {
        $ast = $this->parseAndBind($sql, $params);

        if (!$ast instanceof SelectStatement) {
            throw new \RuntimeException("query() only accepts SELECT statements");
        }

        return $this->executeSelect($ast);
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

    private function executeSelect(SelectStatement $ast): iterable
    {
        // Get the source table
        if ($ast->from === null) {
            throw new \RuntimeException("SELECT without FROM not supported");
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

        // Project columns
        foreach ($table as $row) {
            yield $this->projectRow($row, $ast->columns);
        }
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
            $p = \mini\Table\Predicate::from($table);
            $left = $this->applyWhereToTableInterface($p, $node->left);
            $right = $this->applyWhereToTableInterface($p, $node->right);
            return $table->or($left, $right);
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
        if ($node instanceof \mini\Parsing\SQL\AST\InOperation) {
            if (!$node->left instanceof IdentifierNode) {
                throw new \RuntimeException("Left side of IN must be a column");
            }
            $column = $node->left->getName();
            $values = [];
            foreach ($node->values as $valueNode) {
                $values[] = $this->evaluator->evaluate($valueNode, null);
            }
            $set = new \mini\Table\Set($column, $values);
            $result = $table->in($column, $set);
            return $node->negated ? $table->except($result) : $result;
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

        throw new \RuntimeException("Unsupported WHERE expression: " . get_class($node));
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
}
