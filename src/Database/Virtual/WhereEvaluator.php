<?php

namespace mini\Database\Virtual;

use mini\Parsing\SQL\AST\{
    ASTNode,
    BinaryOperation,
    UnaryOperation,
    InOperation,
    IsNullOperation,
    LikeOperation,
    LiteralNode,
    IdentifierNode,
    PlaceholderNode,
    FunctionCallNode,
    SubqueryNode
};

/**
 * Evaluates WHERE clause AST against row data
 *
 * Handles:
 * - Binary operations (=, >, <, >=, <=, !=, AND, OR)
 * - IN / NOT IN operations (value lists and subqueries)
 * - IS NULL / IS NOT NULL
 * - LIKE / NOT LIKE (SQL wildcards: % and _)
 * - Unary operations (-, NOT)
 * - Placeholders (? and :name)
 * - Subqueries (via lazy evaluation through ValueInterface)
 *
 * Uses PHP's native comparison operators.
 */
class WhereEvaluator
{
    private array $params;
    private int $positionalIndex = 0;

    /** @var \Closure|null fn(SubqueryNode): ValueInterface */
    private ?\Closure $subqueryResolver;

    /**
     * @param array $params Bound parameters (positional or named)
     * @param \Closure|null $subqueryResolver fn(SubqueryNode): ValueInterface - creates lazy subquery values
     */
    public function __construct(array $params = [], ?\Closure $subqueryResolver = null)
    {
        $this->params = $params;
        $this->subqueryResolver = $subqueryResolver;
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

        if ($node instanceof IsNullOperation) {
            return $this->evaluateIsNull($node, $row);
        }

        if ($node instanceof LikeOperation) {
            return $this->evaluateLike($node, $row);
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

        // Comparison operators - get scalar values
        $left = $this->getScalarValue($node->left, $row);
        $right = $this->getScalarValue($node->right, $row);

        return match($operator) {
            '=' => $left == $right,  // Loose comparison like SQL
            '!=' => $left != $right,
            '>' => $left > $right,
            '<' => $left < $right,
            '>=' => $left >= $right,
            '<=' => $left <= $right,
            default => throw new \RuntimeException("Unsupported operator: $operator")
        };
    }

    private function evaluateInOp(InOperation $node, array $row): bool
    {
        $left = $this->getScalarValue($node->left, $row);

        // Get ValueInterface for the IN values
        $valueSet = $this->getValueSet($node, $row);

        $result = $valueSet->contains($left);
        return $node->negated ? !$result : $result;
    }

    /**
     * Get a ValueInterface for the IN operation's values
     */
    private function getValueSet(InOperation $node, array $row): ValueInterface
    {
        // Subquery - create lazy subquery value
        if ($node->isSubquery()) {
            if ($this->subqueryResolver === null) {
                throw new \RuntimeException("Subqueries in IN clauses require a subquery resolver");
            }
            return ($this->subqueryResolver)($node->values);
        }

        // Value list - collect into array and wrap
        $values = [];
        foreach ($node->values as $valueNode) {
            $values[] = $this->getScalarValue($valueNode, $row);
        }

        return new ValueList($values);
    }

    private function evaluateIsNull(IsNullOperation $node, array $row): bool
    {
        $value = $this->getScalarValue($node->expression, $row);
        $isNull = $value === null;
        return $node->negated ? !$isNull : $isNull;
    }

    private function evaluateLike(LikeOperation $node, array $row): bool
    {
        $value = $this->getScalarValue($node->left, $row);
        $pattern = $this->getScalarValue($node->pattern, $row);

        // Convert SQL LIKE pattern to regex
        // % matches any sequence of characters, _ matches any single character
        $regex = '/^' . preg_replace_callback(
            '/([%_])|([^%_]+)/',
            fn($m) => $m[1] ? ($m[1] === '%' ? '.*' : '.') : preg_quote($m[2], '/'),
            $pattern
        ) . '$/i';

        $matches = (bool) preg_match($regex, (string) $value);
        return $node->negated ? !$matches : $matches;
    }

    private function evaluateUnaryOp(UnaryOperation $node, array $row): mixed
    {
        $value = $this->getScalarValue($node->expression, $row);

        return match($node->operator) {
            '-' => -$value,
            'NOT' => !$value,
            default => throw new \RuntimeException("Unsupported unary operator: {$node->operator}")
        };
    }

    /**
     * Get a scalar PHP value from an AST node
     */
    private function getScalarValue(ASTNode $node, array $row): mixed
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
            if ($node->valueType === 'null') {
                return null;
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
            $left = $this->getScalarValue($node->left, $row);
            $right = $this->getScalarValue($node->right, $row);

            return match($node->operator) {
                '+' => $left + $right,
                '-' => $left - $right,
                '*' => $left * $right,
                '/' => $right != 0 ? $left / $right : null,
                default => null
            };
        }

        if ($node instanceof FunctionCallNode) {
            throw new \RuntimeException("Function calls in WHERE not yet supported: " . $node->name);
        }

        if ($node instanceof SubqueryNode) {
            // Scalar subquery context - must return exactly one value
            if ($this->subqueryResolver === null) {
                throw new \RuntimeException("Subqueries require a subquery resolver");
            }
            $valueInterface = ($this->subqueryResolver)($node);
            return $valueInterface->getValue();
        }

        throw new \RuntimeException("Cannot get value from " . get_class($node));
    }
}
