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
    NiladicFunctionNode,
    ExistsOperation
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

        // EXISTS operation
        if ($node instanceof ExistsOperation) {
            return $this->evaluateExists($node, $row, $context);
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

    /**
     * Evaluate an expression as a SQL truth value: TRUE, FALSE or UNKNOWN (null).
     *
     * Unlike {@see evaluateAsBool()} this preserves UNKNOWN so that AND/OR/NOT
     * can implement three-valued logic.
     */
    private function evaluateAsNullableBool(ASTNode $node, ?object $row, array $context): ?bool
    {
        $value = $this->evaluate($node, $row, $context);

        return $value === null ? null : (bool) $value;
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
            $val = (string)$node->value;
            // A decimal point or an exponent makes it an approximate numeric
            // literal - `1e3` must not go through (int) casting.
            return (str_contains($val, '.') || str_contains($val, 'e') || str_contains($val, 'E'))
                ? (float)$val
                : (int)$val;
        }

        // String
        return $node->value;
    }

    private function evaluateIdentifier(IdentifierNode $node, ?object $row, array $context): mixed
    {
        if ($row === null) {
            throw new \RuntimeException("Cannot evaluate column reference without row context: " . $node->getFullName());
        }

        $columnName = $node->getName();

        // Check if it's a wildcard (shouldn't happen in expression context)
        if ($columnName === '*') {
            throw new \RuntimeException("Wildcard (*) not allowed in expression context");
        }

        // Try the simple column name first
        if (property_exists($row, $columnName)) {
            return $row->$columnName;
        }

        // Try the full qualified name
        $fullName = $node->getFullName();
        if (property_exists($row, $fullName)) {
            return $row->$fullName;
        }

        // For JOINs: try matching unqualified name against qualified properties
        // e.g., 'e3' should match 't3.e3' in the row.
        //
        // More than one match means the reference is ambiguous across the joined
        // tables. Returning the first one made the answer depend on the order of
        // the tables in FROM (WHERE name = 'Alice' found a row with
        // "users u JOIN products p" and no row with "products p JOIN users u"),
        // so reject it instead of guessing.
        if (!str_contains($columnName, '.')) {
            $suffix = '.' . $columnName;
            $match = null;
            $matchedProp = null;
            foreach ($row as $prop => $value) {
                if (!str_ends_with($prop, $suffix)) {
                    continue;
                }
                if ($matchedProp !== null) {
                    throw new \RuntimeException(
                        "Ambiguous column name: $columnName (matches $matchedProp, $prop)"
                    );
                }
                $match = $value;
                $matchedProp = $prop;
            }
            if ($matchedProp !== null) {
                return $match;
            }
        }

        throw new \RuntimeException("Column not found: $columnName");
    }

    private function evaluateBinaryOp(BinaryOperation $node, ?object $row, array $context): mixed
    {
        $op = strtoupper($node->operator);

        // Short-circuit evaluation for AND/OR, with SQL three-valued logic.
        // FALSE dominates AND, TRUE dominates OR; otherwise any UNKNOWN (NULL)
        // operand makes the result UNKNOWN.
        if ($op === 'AND') {
            $left = $this->evaluateAsNullableBool($node->left, $row, $context);
            if ($left === false) return false;
            $right = $this->evaluateAsNullableBool($node->right, $row, $context);
            if ($right === false) return false;
            return ($left === null || $right === null) ? null : true;
        }

        if ($op === 'OR') {
            $left = $this->evaluateAsNullableBool($node->left, $row, $context);
            if ($left === true) return true;
            $right = $this->evaluateAsNullableBool($node->right, $row, $context);
            if ($right === true) return true;
            return ($left === null || $right === null) ? null : false;
        }

        // Evaluate both sides
        $left = $this->evaluate($node->left, $row, $context);
        $right = $this->evaluate($node->right, $row, $context);

        // NULL handling: every operator - including = and != - yields NULL
        // (UNKNOWN) when either operand is NULL. NULL = NULL is UNKNOWN, not
        // TRUE; use IS NULL to test for NULL.
        if ($left === null || $right === null) {
            return null;
        }

        return match ($op) {
            // Comparison
            '=' => self::valuesEqual($left, $right),
            '!=', '<>' => !self::valuesEqual($left, $right),
            '<' => self::compareValues($left, $right) < 0,
            '<=' => self::compareValues($left, $right) <= 0,
            '>' => self::compareValues($left, $right) > 0,
            '>=' => self::compareValues($left, $right) >= 0,

            // Arithmetic
            '+' => $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            // PHP semantics: int/int yields int when exact, float otherwise
            '/' => $right != 0 ? $left / $right : null,
            '%' => $right != 0 ? $left % $right : null,

            // String concatenation (|| in standard SQL)
            '||' => (string)$left . (string)$right,

            default => throw new \RuntimeException("Unsupported operator: $op"),
        };
    }

    /**
     * SQL equality for two non-NULL values.
     *
     * Two character strings compare as characters. PHP 8's `==` compares two
     * *numeric* strings numerically, which made '1' = '01', '5' = '5.0' and
     * ' 5' = '5' all true - while the table layer (InMemoryTable::eq, the index
     * lookups) compares them as strings. The same predicate therefore answered
     * differently depending on whether the planner pushed it down or evaluated
     * it row by row, and `code <> '1'` silently dropped every row holding a
     * different spelling of 1.
     *
     * Mixed string/number operands keep PHP's coercion - that is Mini's
     * documented pragmatic-PHP stance, and it matches the table layer.
     */
    public static function valuesEqual(mixed $a, mixed $b): bool
    {
        if (is_string($a) && is_string($b)) {
            return $a === $b;
        }

        return $a == $b;
    }

    /**
     * SQL ordering comparison for two non-NULL values. See {@see valuesEqual()}.
     */
    public static function compareValues(mixed $a, mixed $b): int
    {
        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) <=> 0;
        }

        return $a <=> $b;
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
            'TRIM' => $this->fnTrim('trim', $args),
            'LTRIM' => $this->fnTrim('ltrim', $args),
            'RTRIM' => $this->fnTrim('rtrim', $args),
            'SUBSTR', 'SUBSTRING' => $this->fnSubstr($args),
            'CONCAT' => implode('', array_map(fn($a) => (string)($a ?? ''), $args)),
            'REPLACE' => isset($args[0], $args[1], $args[2])
                ? str_replace((string)$args[1], (string)$args[2], (string)$args[0])
                : null,
            'INSTR' => isset($args[0], $args[1])
                ? (($pos = strpos((string)$args[0], (string)$args[1])) !== false ? $pos + 1 : 0)
                : null,

            // Numeric functions
            'ABS' => isset($args[0]) ? abs($this->numericArg('ABS', $args[0])) : null,
            'ROUND' => $this->fnRound($args),
            'FLOOR' => isset($args[0]) ? floor($this->numericArg('FLOOR', $args[0])) : null,
            'CEIL', 'CEILING' => isset($args[0]) ? ceil($this->numericArg($name, $args[0])) : null,

            // NULL handling
            'COALESCE' => $this->fnCoalesce($args),
            'NULLIF' => isset($args[0], $args[1]) && $args[0] == $args[1] ? null : ($args[0] ?? null),
            'IFNULL', 'NVL' => $args[0] ?? $args[1] ?? null,

            // Type conversion
            'CAST' => $args[0] ?? null, // Simplified - just returns the value

            default => throw new \RuntimeException("Unknown function: $name"),
        };
    }

    /**
     * SUBSTR(X, Y[, Z]) - 1-indexed substring.
     *
     * Positions are character positions starting at 1. A negative Y counts back
     * from the end of the string. A negative Z selects the abs(Z) characters
     * *preceding* position Y. Positions outside the string contribute nothing
     * rather than shifting the window, so SUBSTR('abcdef', 0, 2) is 'a'.
     * Any NULL argument yields NULL.
     */
    private function fnSubstr(array $args): ?string
    {
        if (!isset($args[0])) return null;
        // NULL start/length propagate (a supplied-but-NULL argument is UNKNOWN)
        if (array_key_exists(1, $args) && $args[1] === null) return null;
        if (array_key_exists(2, $args) && $args[2] === null) return null;

        $str = (string)$args[0];
        $len = strlen($str);

        $start = (int)($args[1] ?? 1);
        if ($start < 0) {
            $start = $len + $start + 1;
        }

        if (array_key_exists(2, $args)) {
            $length = (int)$args[2];
            if ($length < 0) {
                $start += $length;
                $length = -$length;
            }
            $end = $start + $length; // exclusive
        } else {
            $end = $len + 1;
        }

        // Clamp the window to the string; out-of-range positions select nothing
        if ($start < 1) $start = 1;
        if ($end > $len + 1) $end = $len + 1;
        if ($end <= $start) return '';

        return substr($str, $start - 1, $end - $start);
    }

    /**
     * TRIM/LTRIM/RTRIM with an optional character set (SQL:2003 TRIM(c FROM s)).
     */
    private function fnTrim(string $fn, array $args): ?string
    {
        if (!isset($args[0])) return null;
        if (array_key_exists(1, $args) && $args[1] === null) return null;

        if (array_key_exists(1, $args)) {
            $chars = (string)$args[1];
            // Trimming an empty character set removes nothing
            return $chars === '' ? (string)$args[0] : $fn((string)$args[0], $chars);
        }

        return $fn((string)$args[0]);
    }

    /**
     * ROUND(X[, D]) - a NULL precision makes the whole result NULL.
     */
    private function fnRound(array $args): int|float|null
    {
        if (!isset($args[0])) return null;
        if (array_key_exists(1, $args) && $args[1] === null) return null;

        return round($this->numericArg('ROUND', $args[0]), (int)($args[1] ?? 0));
    }

    /**
     * Coerce a scalar function argument to a number, or fail at the SQL level.
     *
     * PHP's math builtins raise a TypeError for non-numeric strings, which would
     * surface to the caller as a raw PHP engine message. Numeric strings are
     * accepted (SQL type coercion); anything else is an SQL error.
     */
    private function numericArg(string $function, mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return (int)$value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return trim($value) + 0;
        }

        $type = get_debug_type($value);
        throw new \RuntimeException("$function() expects a numeric argument, $type given");
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

    /**
     * Evaluate IN/NOT IN with proper three-valued NULL logic
     *
     * SQL IN semantics:
     * - If left is NULL: result is NULL (unless empty list: IN()=FALSE, NOT IN()=TRUE)
     * - If definite match found: IN=TRUE, NOT IN=FALSE
     * - If no match but NULLs in list: result is NULL
     * - If no match and no NULLs: IN=FALSE, NOT IN=TRUE
     */
    private function evaluateIn(InOperation $node, ?object $row, array $context): int|null
    {
        $left = $this->evaluate($node->left, $row, $context);

        if ($node->isSubquery()) {
            // IN (SELECT ...) - execute subquery and check membership
            if ($this->subqueryExecutor === null) {
                throw new \RuntimeException("Subquery executor not configured for IN clause");
            }

            $subqueryNode = $node->values; // SubqueryNode
            $results = ($this->subqueryExecutor)($subqueryNode->query, $row);

            $hasRows = false;
            $hasNull = false;
            foreach ($results as $resultRow) {
                $hasRows = true;
                // Get first column value from each row
                $props = get_object_vars($resultRow);
                $value = reset($props);
                if ($value === null) {
                    $hasNull = true;
                } elseif ($left !== null && self::valuesEqual($left, $value)) {
                    return $node->negated ? 0 : 1;
                }
            }

            // Empty set: IN()=FALSE, NOT IN()=TRUE
            if (!$hasRows) {
                return $node->negated ? 1 : 0;
            }

            // Left is NULL: result is NULL
            if ($left === null) {
                return null;
            }

            // No match found, but NULLs in list: result is NULL
            if ($hasNull) {
                return null;
            }

            return $node->negated ? 1 : 0;
        }

        // Literal list
        $values = $node->values;
        $isEmpty = true;
        foreach ($values as $_) {
            $isEmpty = false;
            break;
        }

        // Empty set: IN()=FALSE, NOT IN()=TRUE
        if ($isEmpty) {
            return $node->negated ? 1 : 0;
        }

        // Left is NULL: result is NULL
        if ($left === null) {
            return null;
        }

        $hasNull = false;
        foreach ($values as $valueNode) {
            $value = $this->evaluate($valueNode, $row, $context);
            if ($value === null) {
                $hasNull = true;
            } elseif (self::valuesEqual($left, $value)) {
                return $node->negated ? 0 : 1;
            }
        }

        // No match found, but NULLs in list: result is NULL
        if ($hasNull) {
            return null;
        }

        return $node->negated ? 1 : 0;
    }

    private function evaluateIsNull(IsNullOperation $node, ?object $row, array $context): bool
    {
        $value = $this->evaluate($node->expression, $row, $context);
        $isNull = $value === null;

        return $node->negated ? !$isNull : $isNull;
    }

    private function evaluateLike(LikeOperation $node, ?object $row, array $context): ?bool
    {
        $value = $this->evaluate($node->left, $row, $context);
        $pattern = $this->evaluate($node->pattern, $row, $context);

        // NULL operand makes both LIKE and NOT LIKE UNKNOWN
        if ($value === null || $pattern === null) {
            return null;
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

    private function evaluateBetween(BetweenOperation $node, ?object $row, array $context): ?bool
    {
        $value = $this->evaluate($node->expression, $row, $context);
        $low = $this->evaluate($node->low, $row, $context);
        $high = $this->evaluate($node->high, $row, $context);

        // NULL operand makes both BETWEEN and NOT BETWEEN UNKNOWN
        if ($value === null || $low === null || $high === null) {
            return null;
        }

        $inRange = self::compareValues($value, $low) >= 0
            && self::compareValues($value, $high) <= 0;

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

            // SQL: CASE NULL WHEN x never matches (NULL = x is UNKNOWN)
            if ($operandValue !== null) {
                foreach ($node->whenClauses as $clause) {
                    $whenValue = $this->evaluate($clause['when'], $row, $context);
                    // Also skip if WHEN value is NULL (x = NULL is UNKNOWN)
                    if ($whenValue !== null && self::valuesEqual($operandValue, $whenValue)) {
                        return $this->evaluate($clause['then'], $row, $context);
                    }
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
     * Evaluate EXISTS operation - returns true if subquery returns any rows
     */
    private function evaluateExists(ExistsOperation $node, ?object $row, array $context): int
    {
        if ($this->subqueryExecutor === null) {
            throw new \RuntimeException("Subquery executor not configured");
        }

        // Execute the subquery, passing the outer row for correlated subqueries
        $results = ($this->subqueryExecutor)($node->subquery->query, $row);

        // Check if any rows exist (only need to check first row)
        $hasRows = false;
        foreach ($results as $_) {
            $hasRows = true;
            break;
        }

        if ($node->negated) {
            $hasRows = !$hasRows;
        }

        // SQL has no boolean type in a result set: EXISTS yields 1 or 0, the
        // same shape IN produces. Returning PHP false here would render as an
        // empty string when the predicate is projected in a SELECT list.
        return $hasRows ? 1 : 0;
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
