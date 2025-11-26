<?php

namespace mini\Database\Virtual;

use mini\Parsing\SQL\AST\{
    ASTNode,
    BinaryOperation,
    UnaryOperation,
    InOperation,
    LiteralNode,
    IdentifierNode,
    PlaceholderNode,
    FunctionCallNode,
    SelectStatement
};

/**
 * Evaluates WHERE clause AST against row data
 *
 * Handles:
 * - Binary operations (=, >, <, >=, <=, !=, AND, OR)
 * - IN operations (with lists or subqueries)
 * - Unary operations (-, NOT)
 * - Placeholders (? and :name)
 * - NULL comparisons
 * - Collation-aware comparisons
 */
class WhereEvaluator
{
    private array $params;
    private int $positionalIndex = 0;
    private \Collator $collator;

    public function __construct(array $params = [], ?\Collator $collator = null)
    {
        $this->params = $params;
        $this->collator = $collator ?? Collation::binary();
    }

    /**
     * Check if row matches WHERE condition
     *
     * @param array $row Associative array of column => value
     * @param ASTNode|null $where WHERE clause AST
     * @return bool True if row matches (or no WHERE clause)
     */
    public function matches(array $row, ?ASTNode $where): bool
    {
        if ($where === null) {
            return true;
        }

        $this->positionalIndex = 0; // Reset for each row
        return $this->evaluate($where, $row);
    }

    private function evaluate(ASTNode $node, array $row): bool
    {
        if ($node instanceof BinaryOperation) {
            return $this->evaluateBinaryOp($node, $row);
        }

        if ($node instanceof InOperation) {
            return $this->evaluateInOp($node, $row);
        }

        if ($node instanceof UnaryOperation) {
            return $this->evaluateUnaryOp($node, $row);
        }

        throw new \RuntimeException("Cannot evaluate " . get_class($node) . " as boolean");
    }

    private function evaluateBinaryOp(BinaryOperation $node, array $row): bool
    {
        $operator = $node->operator;

        // Logical operators
        if ($operator === 'AND') {
            return $this->evaluate($node->left, $row) && $this->evaluate($node->right, $row);
        }

        if ($operator === 'OR') {
            return $this->evaluate($node->left, $row) || $this->evaluate($node->right, $row);
        }

        // Comparison operators - use collator for proper comparisons
        $left = $this->getValue($node->left, $row);
        $right = $this->getValue($node->right, $row);

        return match($operator) {
            '=' => Collation::equals($this->collator, $left, $right),
            '!=' => !Collation::equals($this->collator, $left, $right),
            '>' => Collation::compare($this->collator, $left, $right) > 0,
            '<' => Collation::compare($this->collator, $left, $right) < 0,
            '>=' => Collation::compare($this->collator, $left, $right) >= 0,
            '<=' => Collation::compare($this->collator, $left, $right) <= 0,
            default => throw new \RuntimeException("Unsupported operator: $operator")
        };
    }

    private function evaluateInOp(InOperation $node, array $row): bool
    {
        $left = $this->getValue($node->left, $row);

        if ($node->isSubquery) {
            // Subquery not yet implemented
            throw new \RuntimeException("IN subqueries not yet supported in virtual tables");
        }

        // IN with list of values
        $values = [];
        foreach ($node->values as $valueNode) {
            $values[] = $this->getValue($valueNode, $row);
        }

        return in_array($left, $values, false); // Loose comparison like SQL
    }

    private function evaluateUnaryOp(UnaryOperation $node, array $row): mixed
    {
        $value = $this->getValue($node->expression, $row);

        return match($node->operator) {
            '-' => -$value,
            'NOT' => !$value,
            default => throw new \RuntimeException("Unsupported unary operator: {$node->operator}")
        };
    }

    private function getValue(ASTNode $node, array $row): mixed
    {
        if ($node instanceof IdentifierNode) {
            // Handle dotted identifiers (table.column)
            $parts = explode('.', $node->name);
            $columnName = end($parts); // Use last part as column name

            return $row[$columnName] ?? null;
        }

        if ($node instanceof LiteralNode) {
            if ($node->valueType === 'number') {
                return str_contains($node->value, '.')
                    ? (float)$node->value
                    : (int)$node->value;
            }
            return $node->value; // string
        }

        if ($node instanceof PlaceholderNode) {
            if ($node->token === '?') {
                // Positional placeholder
                return $this->params[$this->positionalIndex++] ?? null;
            } else {
                // Named placeholder (:name)
                $name = ltrim($node->token, ':');
                return $this->params[$name] ?? null;
            }
        }

        if ($node instanceof UnaryOperation) {
            return $this->evaluateUnaryOp($node, $row);
        }

        if ($node instanceof BinaryOperation) {
            // For expressions like (col1 + col2)
            $left = $this->getValue($node->left, $row);
            $right = $this->getValue($node->right, $row);

            return match($node->operator) {
                '+' => $left + $right,
                '-' => $left - $right,
                '*' => $left * $right,
                '/' => $right != 0 ? $left / $right : null,
                default => null
            };
        }

        if ($node instanceof FunctionCallNode) {
            // Simple function support (can be extended)
            throw new \RuntimeException("Function calls in WHERE not yet supported: " . $node->name);
        }

        throw new \RuntimeException("Cannot get value from " . get_class($node));
    }
}
