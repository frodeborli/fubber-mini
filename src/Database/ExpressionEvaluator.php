<?php

namespace mini\Database;

use mini\Parsing\SQL\AST\{
    ASTNode,
    LiteralNode,
    IdentifierNode,
    PlaceholderNode,
    BinaryOperation,
    UnaryOperation,
    FunctionCallNode,
    InOperation,
    IsNullOperation,
    LikeOperation,
    BetweenOperation
};

/**
 * Evaluates SQL AST expressions against a row context
 *
 * Used by VirtualDatabase to evaluate WHERE conditions, SELECT expressions, etc.
 */
class ExpressionEvaluator
{
    /**
     * Evaluate an expression node against a row
     *
     * @param ASTNode $node The expression to evaluate
     * @param object|null $row The current row (for column references)
     * @param array $context Additional context (aliases, functions, etc.)
     * @return mixed The evaluated value
     */
    public function evaluate(ASTNode $node, ?object $row = null, array $context = []): mixed
    {
        // Literals
        if ($node instanceof LiteralNode) {
            return $this->evaluateLiteral($node);
        }

        // Column references
        if ($node instanceof IdentifierNode) {
            return $this->evaluateIdentifier($node, $row, $context);
        }

        // Binary operations (+, -, *, /, =, <, >, AND, OR, etc.)
        if ($node instanceof BinaryOperation) {
            return $this->evaluateBinaryOp($node, $row, $context);
        }

        // Unary operations (NOT, -)
        if ($node instanceof UnaryOperation) {
            return $this->evaluateUnaryOp($node, $row, $context);
        }

        // Function calls
        if ($node instanceof FunctionCallNode) {
            return $this->evaluateFunction($node, $row, $context);
        }

        // IN operation
        if ($node instanceof InOperation) {
            return $this->evaluateIn($node, $row, $context);
        }

        // IS NULL / IS NOT NULL
        if ($node instanceof IsNullOperation) {
            return $this->evaluateIsNull($node, $row, $context);
        }

        // LIKE operation
        if ($node instanceof LikeOperation) {
            return $this->evaluateLike($node, $row, $context);
        }

        // BETWEEN operation
        if ($node instanceof BetweenOperation) {
            return $this->evaluateBetween($node, $row, $context);
        }

        throw new \RuntimeException("Cannot evaluate expression type: " . get_class($node));
    }

    /**
     * Evaluate expression as boolean (for WHERE, HAVING, ON conditions)
     */
    public function evaluateAsBool(ASTNode $node, ?object $row = null, array $context = []): bool
    {
        $value = $this->evaluate($node, $row, $context);

        // SQL truthiness: NULL is not true, 0 is not true, empty string is true
        if ($value === null) {
            return false;
        }

        return (bool) $value;
    }

    private function evaluateLiteral(LiteralNode $node): mixed
    {
        if ($node->valueType === 'null') {
            return null;
        }

        if ($node->valueType === 'boolean') {
            return $node->value;
        }

        if ($node->valueType === 'number') {
            $val = $node->value;
            return str_contains((string)$val, '.') ? (float)$val : (int)$val;
        }

        // String
        return $node->value;
    }

    private function evaluateIdentifier(IdentifierNode $node, ?object $row, array $context): mixed
    {
        if ($row === null) {
            throw new \RuntimeException("Cannot evaluate column reference without row context: " . $node->getFullName());
        }

        // For now, use the final part of the identifier (column name)
        // In Phase 2 with JOINs, we'll need to handle table.column resolution
        $columnName = $node->getName();

        // Check if it's a wildcard (shouldn't happen in expression context)
        if ($columnName === '*') {
            throw new \RuntimeException("Wildcard (*) not allowed in expression context");
        }

        // Try the simple column name first
        if (property_exists($row, $columnName)) {
            return $row->$columnName;
        }

        // Try the full qualified name (for JOINs later)
        $fullName = $node->getFullName();
        if (property_exists($row, $fullName)) {
            return $row->$fullName;
        }

        throw new \RuntimeException("Column not found: $columnName");
    }

    private function evaluateBinaryOp(BinaryOperation $node, ?object $row, array $context): mixed
    {
        $op = strtoupper($node->operator);

        // Short-circuit evaluation for AND/OR
        if ($op === 'AND') {
            $left = $this->evaluateAsBool($node->left, $row, $context);
            if (!$left) return false;
            return $this->evaluateAsBool($node->right, $row, $context);
        }

        if ($op === 'OR') {
            $left = $this->evaluateAsBool($node->left, $row, $context);
            if ($left) return true;
            return $this->evaluateAsBool($node->right, $row, $context);
        }

        // Evaluate both sides
        $left = $this->evaluate($node->left, $row, $context);
        $right = $this->evaluate($node->right, $row, $context);

        // NULL handling: most operations return NULL if either operand is NULL
        if ($left === null || $right === null) {
            // Only = and != have special NULL handling
            if ($op === '=' || $op === '!=') {
                if ($left === null && $right === null) {
                    return $op === '=';
                }
                return $op === '!=' ? ($left !== $right) : false;
            }
            return null;
        }

        return match ($op) {
            // Comparison
            '=' => $left == $right,
            '!=', '<>' => $left != $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
            '>' => $left > $right,
            '>=' => $left >= $right,

            // Arithmetic
            '+' => $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            '/' => $right != 0 ? $left / $right : null,

            // String concatenation (|| in standard SQL)
            '||' => $left . $right,

            default => throw new \RuntimeException("Unsupported operator: $op"),
        };
    }

    private function evaluateUnaryOp(UnaryOperation $node, ?object $row, array $context): mixed
    {
        $op = strtoupper($node->operator);
        $value = $this->evaluate($node->expression, $row, $context);

        return match ($op) {
            'NOT' => $value === null ? null : !$this->evaluateAsBool($node->expression, $row, $context),
            '-' => $value === null ? null : -$value,
            '+' => $value,
            default => throw new \RuntimeException("Unsupported unary operator: $op"),
        };
    }

    private function evaluateFunction(FunctionCallNode $node, ?object $row, array $context): mixed
    {
        $name = strtoupper($node->name);
        $args = array_map(fn($arg) => $this->evaluate($arg, $row, $context), $node->arguments);

        // Built-in scalar functions
        return match ($name) {
            // String functions
            'UPPER' => isset($args[0]) ? strtoupper((string)$args[0]) : null,
            'LOWER' => isset($args[0]) ? strtolower((string)$args[0]) : null,
            'LENGTH', 'LEN' => isset($args[0]) ? strlen((string)$args[0]) : null,
            'TRIM' => isset($args[0]) ? trim((string)$args[0]) : null,
            'LTRIM' => isset($args[0]) ? ltrim((string)$args[0]) : null,
            'RTRIM' => isset($args[0]) ? rtrim((string)$args[0]) : null,
            'SUBSTR', 'SUBSTRING' => $this->fnSubstr($args),
            'CONCAT' => implode('', array_map(fn($a) => (string)($a ?? ''), $args)),
            'REPLACE' => isset($args[0], $args[1], $args[2])
                ? str_replace((string)$args[1], (string)$args[2], (string)$args[0])
                : null,

            // Numeric functions
            'ABS' => isset($args[0]) ? abs($args[0]) : null,
            'ROUND' => isset($args[0]) ? round($args[0], $args[1] ?? 0) : null,
            'FLOOR' => isset($args[0]) ? floor($args[0]) : null,
            'CEIL', 'CEILING' => isset($args[0]) ? ceil($args[0]) : null,

            // NULL handling
            'COALESCE' => $this->fnCoalesce($args),
            'NULLIF' => isset($args[0], $args[1]) && $args[0] == $args[1] ? null : ($args[0] ?? null),
            'IFNULL', 'NVL' => $args[0] ?? $args[1] ?? null,

            // Type conversion
            'CAST' => $args[0] ?? null, // Simplified - just returns the value

            default => throw new \RuntimeException("Unknown function: $name"),
        };
    }

    private function fnSubstr(array $args): ?string
    {
        if (!isset($args[0])) return null;
        $str = (string)$args[0];
        $start = (int)($args[1] ?? 1) - 1; // SQL is 1-indexed
        $length = isset($args[2]) ? (int)$args[2] : null;

        return $length !== null
            ? substr($str, $start, $length)
            : substr($str, $start);
    }

    private function fnCoalesce(array $args): mixed
    {
        foreach ($args as $arg) {
            if ($arg !== null) {
                return $arg;
            }
        }
        return null;
    }

    private function evaluateIn(InOperation $node, ?object $row, array $context): bool
    {
        if ($node->isSubquery()) {
            throw new \RuntimeException("IN with subquery not yet supported");
        }

        $left = $this->evaluate($node->left, $row, $context);

        if ($left === null) {
            return false;
        }

        foreach ($node->values as $valueNode) {
            $value = $this->evaluate($valueNode, $row, $context);
            if ($left == $value) {
                return !$node->negated;
            }
        }

        return $node->negated;
    }

    private function evaluateIsNull(IsNullOperation $node, ?object $row, array $context): bool
    {
        $value = $this->evaluate($node->expression, $row, $context);
        $isNull = $value === null;

        return $node->negated ? !$isNull : $isNull;
    }

    private function evaluateLike(LikeOperation $node, ?object $row, array $context): bool
    {
        $value = $this->evaluate($node->left, $row, $context);
        $pattern = $this->evaluate($node->pattern, $row, $context);

        if ($value === null || $pattern === null) {
            return false;
        }

        // Convert SQL LIKE pattern to regex
        // % = .* (any characters)
        // _ = . (single character)
        $regex = '/^' . str_replace(
            ['%', '_', '/'],
            ['.*', '.', '\\/'],
            preg_quote((string)$pattern, '/')
        ) . '$/i';

        // Undo the escaping for % and _
        $regex = str_replace(['\\%', '\\_'], ['%', '_'], $regex);
        $regex = str_replace(['%', '_'], ['.*', '.'], $regex);

        $matches = (bool)preg_match($regex, (string)$value);

        return $node->negated ? !$matches : $matches;
    }

    private function evaluateBetween(BetweenOperation $node, ?object $row, array $context): bool
    {
        $value = $this->evaluate($node->expression, $row, $context);
        $low = $this->evaluate($node->low, $row, $context);
        $high = $this->evaluate($node->high, $row, $context);

        if ($value === null || $low === null || $high === null) {
            return false;
        }

        $inRange = $value >= $low && $value <= $high;

        return $node->negated ? !$inRange : $inRange;
    }
}
