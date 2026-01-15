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
    BetweenOperation,
    CaseWhenNode,
    SubqueryNode,
    NiladicFunctionNode
};

/**
 * Evaluates SQL AST expressions against a row context
 *
 * Used by VirtualDatabase to evaluate WHERE conditions, SELECT expressions, etc.
 */
class ExpressionEvaluator
{
    /**
     * Callable that executes a subquery and returns result rows
     * Signature: fn(SelectStatement $query, ?object $outerRow): iterable
     *
     * @var callable|null
     */
    private $subqueryExecutor = null;

    /**
     * Set the subquery executor for handling scalar subqueries
     *
     * @param callable $executor fn(SelectStatement $query, ?object $outerRow): iterable
     */
    public function setSubqueryExecutor(callable $executor): void
    {
        $this->subqueryExecutor = $executor;
    }

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

        // Bound placeholders - return the bound value directly
        if ($node instanceof PlaceholderNode) {
            if (!$node->isBound) {
                throw new \RuntimeException(
                    'Cannot evaluate unbound placeholder. Params should be bound to AST before evaluation.'
                );
            }
            return $node->boundValue;
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

        // CASE WHEN expression
        if ($node instanceof CaseWhenNode) {
            return $this->evaluateCaseWhen($node, $row, $context);
        }

        // Scalar subquery
        if ($node instanceof SubqueryNode) {
            return $this->evaluateSubquery($node, $row, $context);
        }

        // Niladic functions (CURRENT_DATE, CURRENT_TIME, CURRENT_TIMESTAMP)
        if ($node instanceof NiladicFunctionNode) {
            return $this->evaluateNiladicFunction($node);
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
            '%' => $right != 0 ? $left % $right : null,

            // String concatenation (|| in standard SQL)
            '||' => (string)$left . (string)$right,

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
            'INSTR' => isset($args[0], $args[1])
                ? (($pos = strpos((string)$args[0], (string)$args[1])) !== false ? $pos + 1 : 0)
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

    /**
     * Evaluate CASE WHEN expression
     *
     * Two forms:
     * - Simple: CASE operand WHEN value THEN result... Returns result where operand = value
     * - Searched: CASE WHEN condition THEN result... Returns result where condition is true
     */
    private function evaluateCaseWhen(CaseWhenNode $node, ?object $row, array $context): mixed
    {
        // Simple CASE: compare operand to each WHEN value
        if ($node->operand !== null) {
            $operandValue = $this->evaluate($node->operand, $row, $context);

            foreach ($node->whenClauses as $clause) {
                $whenValue = $this->evaluate($clause['when'], $row, $context);
                if ($operandValue == $whenValue) {
                    return $this->evaluate($clause['then'], $row, $context);
                }
            }
        } else {
            // Searched CASE: evaluate each WHEN condition as boolean
            foreach ($node->whenClauses as $clause) {
                if ($this->evaluateAsBool($clause['when'], $row, $context)) {
                    return $this->evaluate($clause['then'], $row, $context);
                }
            }
        }

        // No match - return ELSE value or NULL
        if ($node->elseResult !== null) {
            return $this->evaluate($node->elseResult, $row, $context);
        }

        return null;
    }

    /**
     * Evaluate scalar subquery
     *
     * Executes the subquery and returns:
     * - The single value if exactly one row/column
     * - NULL if no rows
     * - Throws if multiple rows (SQL standard for scalar context)
     */
    private function evaluateSubquery(SubqueryNode $node, ?object $row, array $context): mixed
    {
        if ($this->subqueryExecutor === null) {
            throw new \RuntimeException("Subquery executor not configured");
        }

        // Execute the subquery, passing the outer row for correlated subqueries
        $results = ($this->subqueryExecutor)($node->query, $row);

        // Collect results (might be a generator)
        $rows = [];
        foreach ($results as $resultRow) {
            $rows[] = $resultRow;
            // For scalar context, we only need to check if there's more than one
            if (count($rows) > 1) {
                throw new \RuntimeException("Scalar subquery returned more than one row");
            }
        }

        // No rows = NULL
        if (empty($rows)) {
            return null;
        }

        // Get the first (and only) column value
        $resultRow = $rows[0];
        $props = get_object_vars($resultRow);

        if (count($props) > 1) {
            throw new \RuntimeException("Scalar subquery returned more than one column");
        }

        return reset($props); // Return first column value
    }

    /**
     * Evaluate niladic function (CURRENT_DATE, CURRENT_TIME, CURRENT_TIMESTAMP)
     */
    private function evaluateNiladicFunction(NiladicFunctionNode $node): string
    {
        return match ($node->name) {
            'CURRENT_DATE' => date('Y-m-d'),
            'CURRENT_TIME' => date('H:i:s'),
            'CURRENT_TIMESTAMP' => date('Y-m-d H:i:s'),
            default => throw new \RuntimeException("Unknown niladic function: {$node->name}")
        };
    }
}
