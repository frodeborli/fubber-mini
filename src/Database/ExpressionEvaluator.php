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
    CastNode,
    ExtractNode,
    DistinctFromOperation,
    RowValueNode,
    ExistsOperation
};
use mini\Parsing\SQL\DatetimeText;

/**
 * Evaluates SQL AST expressions against a row context
 *
 * Used by VirtualDatabase to evaluate WHERE conditions, SELECT expressions, etc.
 *
 * Scalar functions are not built into this class: they live in a registry that
 * is seeded from {@see StandardFunctions} and can be extended or overridden
 * with {@see registerFunction()} (which is what
 * {@see VirtualDatabase::createFunction()} calls). Only constructs the grammar
 * itself carries - CAST(x AS type), LIKE ... ESCAPE, CURRENT_DATE - are
 * evaluated natively here, because a PHP callable cannot express their syntax.
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
     * Scalar function registry, keyed by UPPERCASE function name
     *
     * @var array<string, array{fn: callable, argCount: int}>
     */
    private array $functions;

    /**
     * @param array<string, array{fn: callable, argCount: int}>|null $functions
     *        Scalar function registry; null installs {@see StandardFunctions::all()}
     */
    public function __construct(?array $functions = null)
    {
        $this->functions = $functions ?? StandardFunctions::all();
    }

    /**
     * Register (or replace) a scalar function
     *
     * Names are case-insensitive, and a registration with an existing name
     * replaces it - including the built-ins from {@see StandardFunctions}.
     *
     * @param string $name Function name (case-insensitive)
     * @param callable $fn Called with the evaluated arguments: function(...$args): mixed
     * @param int $argCount Expected argument count (-1 for variadic)
     */
    public function registerFunction(string $name, callable $fn, int $argCount = -1): void
    {
        $this->functions[\strtoupper($name)] = ['fn' => $fn, 'argCount' => $argCount];
    }

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

        // CAST(expr AS type) - a CastNode is a FunctionCallNode, so it has to
        // be checked first
        if ($node instanceof CastNode) {
            return $this->evaluateCast($node, $row, $context);
        }

        // EXTRACT(field FROM source) - likewise a FunctionCallNode subclass
        if ($node instanceof ExtractNode) {
            return $this->evaluateExtract($node, $row, $context);
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

        // a IS [NOT] DISTINCT FROM b - NULL-safe comparison, never UNKNOWN
        if ($node instanceof DistinctFromOperation) {
            $distinct = $this->valuesAreDistinct(
                $this->evaluate($node->left, $row, $context),
                $this->evaluate($node->right, $row, $context),
            );
            return ($node->negated ? !$distinct : $distinct) ? 1 : 0;
        }

        // A row value is not a scalar - it only means something to the operators
        // that take row operands, and those intercept it before recursing here.
        if ($node instanceof RowValueNode) {
            throw new \RuntimeException(
                'A row value constructor is only allowed as an operand of a comparison or IN'
            );
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

        // Row value constructors: (a, b) = (1, 2), (a, b) < (1, 2), ...
        if ($node->left instanceof RowValueNode || $node->right instanceof RowValueNode) {
            return $this->compareRowValues($node, $row, $context);
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

    /**
     * Are two values distinct? (`IS DISTINCT FROM`)
     *
     * NULL is treated as a value that equals only itself, so the answer is
     * always TRUE or FALSE - never UNKNOWN.
     */
    private function valuesAreDistinct(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return !($a === null && $b === null);
        }

        return !self::valuesEqual($a, $b);
    }

    /**
     * SQL:2003 comparison of two row values: `(a, b) = (1, 2)`, `(a, b) < (1, 2)`
     *
     * `=` / `<>` compare element-wise: equal when every pair is equal, unequal
     * as soon as one pair is definitely unequal, UNKNOWN (NULL) otherwise - so
     * `(1, NULL) = (1, 2)` is UNKNOWN but `(1, NULL) = (9, 2)` is FALSE.
     *
     * The ordering operators compare lexicographically: the first pair that
     * differs decides, and a NULL reached before that makes the whole
     * comparison UNKNOWN.
     *
     * @return bool|null TRUE / FALSE / UNKNOWN
     */
    private function compareRowValues(BinaryOperation $node, ?object $row, array $context): ?bool
    {
        $op = strtoupper($node->operator);

        if (!$node->left instanceof RowValueNode || !$node->right instanceof RowValueNode) {
            throw new \RuntimeException(
                "Row value constructor compared against a scalar with '$op'; both operands must be row values"
            );
        }

        $left = $node->left->values;
        $right = $node->right->values;

        if (count($left) !== count($right)) {
            throw new \RuntimeException(
                'Row values in a comparison must have the same number of elements ('
                . count($left) . ' vs ' . count($right) . ')'
            );
        }

        if (!in_array($op, ['=', '!=', '<>', '<', '<=', '>', '>='], true)) {
            throw new \RuntimeException("Operator '$op' does not accept row value operands");
        }

        if ($op === '=' || $op === '!=' || $op === '<>') {
            $unknown = false;
            foreach ($left as $i => $expr) {
                $a = $this->evaluate($expr, $row, $context);
                $b = $this->evaluate($right[$i], $row, $context);
                if ($a === null || $b === null) {
                    $unknown = true;
                    continue;
                }
                if (!self::valuesEqual($a, $b)) {
                    // Definitely unequal: `=` is FALSE, `<>` is TRUE, whatever
                    // the other elements say.
                    return $op !== '=';
                }
            }
            if ($unknown) {
                return null;
            }
            return $op === '=';
        }

        // Lexicographic ordering
        foreach ($left as $i => $expr) {
            $a = $this->evaluate($expr, $row, $context);
            $b = $this->evaluate($right[$i], $row, $context);
            if ($a === null || $b === null) {
                return null;
            }
            $cmp = self::compareValues($a, $b);
            if ($cmp !== 0) {
                return match ($op) {
                    '<', '<=' => $cmp < 0,
                    '>', '>=' => $cmp > 0,
                };
            }
        }

        // All elements equal
        return $op === '<=' || $op === '>=';
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

        $entry = $this->functions[$name] ?? null;
        if ($entry === null) {
            throw new \RuntimeException("Unknown function: $name");
        }

        $args = array_map(fn($arg) => $this->evaluate($arg, $row, $context), $node->arguments);

        $expected = $entry['argCount'];
        if ($expected >= 0 && count($args) !== $expected) {
            $plural = $expected === 1 ? 'argument' : 'arguments';
            throw new \RuntimeException(
                "$name() expects $expected $plural, " . count($args) . " given"
            );
        }

        return ($entry['fn'])(...$args);
    }

    /**
     * CAST(expr AS type) - SQLite conversion semantics.
     *
     * SQLite is this engine's reference implementation, so the messy cases
     * follow it: a TEXT value converts by taking the longest numeric prefix
     * (CAST('12abc' AS INTEGER) is 12, CAST('abc' AS INTEGER) is 0), a REAL
     * converts to INTEGER by truncation towards zero (1.7 -> 1, -1.7 -> -1),
     * and NULL casts to NULL for every target type.
     *
     * The type name is matched by affinity the way SQLite does it, so a length
     * or precision (VARCHAR(255), DECIMAL(10,2)) is accepted and ignored.
     */
    private function evaluateCast(CastNode $node, ?object $row, array $context): mixed
    {
        $value = $this->evaluate($node->arguments[0], $row, $context);

        if ($value === null) {
            return null;
        }

        return match ($node->affinity()) {
            'INTEGER' => self::castToInt($value),
            'REAL' => self::castToFloat($value),
            // PHP strings are byte strings, so BLOB and TEXT coincide here
            'TEXT', 'BLOB' => self::castToText($value),
            'NUMERIC' => self::castToNumeric($value),
            default => throw new \RuntimeException("Unsupported CAST type: {$node->castType}"),
        };
    }

    /**
     * EXTRACT(field FROM source) - SQL:2003 F052.
     *
     * Mini stores datetimes as text, so the source is read as text in the
     * canonical shapes the engine writes: `Y-m-d`, `H:i:s` and `Y-m-d H:i:s`,
     * with optional fractional seconds. A date with no time part has an hour,
     * minute and second of zero - the same answer SQLite gives for
     * `strftime('%H','2020-05-06')`.
     *
     * Validation is {@see DatetimeText}, the same code the parser applies to
     * `DATE '...'` literals, and for the same reason: the text that reaches
     * this method comes from CSV, JSON and remote-API tables, which is exactly
     * where malformed datetimes live. Being strict about a literal the parser
     * can see while reporting month 13 for '2020-13-45' out of a column would
     * put the check on the only path that never needed it. Where SQLite
     * returns NULL (`strftime('%m','2020-13-45')`), Mini throws.
     *
     * Everything else fails loudly too. A time has no year, and a string that
     * is not a datetime at all has no fields, so asking for one is a bug in
     * the query rather than a value to guess at.
     *
     * Fields come back as integers, never as the zero-padded text strftime
     * would produce. SECOND is an exact numeric and stays a float only when
     * the source carries a non-zero fraction, so that '07' and '07.0' land in
     * the same GROUP BY and DISTINCT bucket.
     */
    private function evaluateExtract(ExtractNode $node, ?object $row, array $context): mixed
    {
        $value = $this->evaluate($node->arguments[0], $row, $context);

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new \RuntimeException(
                "EXTRACT({$node->field} FROM ...) needs a date, time or timestamp as text, got "
                    . get_debug_type($value)
            );
        }

        try {
            $parts = DatetimeText::parse($value);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException("EXTRACT({$node->field} FROM ...) " . $e->getMessage(), 0, $e);
        }

        if ($parts[$node->field] === null) {
            throw new \RuntimeException(
                "EXTRACT({$node->field} FROM ...) needs a date, but '$value' is a time"
            );
        }

        return $parts[$node->field];
    }

    private static function castToInt(mixed $value): int
    {
        if (is_int($value)) return $value;
        if (is_bool($value)) return (int)$value;
        if (is_float($value)) return (int)$value; // truncates towards zero

        // Longest *integer* prefix, as SQLite does it: '1.9abc' and '3e2' are
        // 1 and 3, not 1.9 and 300; 'abc' is 0. Out-of-range values clamp.
        $prefix = self::matchPrefix('/^\s*[+-]?\d+/', (string)$value);
        $float = (float)$prefix;
        if ($float >= 9.2233720368547758e18) return \PHP_INT_MAX;
        if ($float <= -9.2233720368547758e18) return \PHP_INT_MIN;
        return (int)$prefix;
    }

    private static function castToFloat(mixed $value): float
    {
        if (is_float($value)) return $value;
        if (is_int($value) || is_bool($value)) return (float)$value;

        return (float)self::realPrefix((string)$value);
    }

    private static function castToText(mixed $value): string
    {
        if (is_string($value)) return $value;
        if (is_bool($value)) return $value ? '1' : '0';
        if (is_float($value)) {
            // SQLite keeps a REAL looking real: CAST(1.0 AS TEXT) is '1.0'
            $text = (string)$value;
            return preg_match('/^-?\d+$/', $text) === 1 ? $text . '.0' : $text;
        }
        return (string)$value;
    }

    /**
     * NUMERIC/DECIMAL: a text value that is integral becomes INTEGER, anything
     * else REAL. A value that is already numeric is returned unchanged.
     */
    private static function castToNumeric(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) return $value;
        if (is_bool($value)) return (int)$value;

        $float = (float)self::realPrefix((string)$value);
        if ($float == floor($float) && abs($float) < 9.2233720368547758e18) {
            return (int)$float;
        }
        return $float;
    }

    /**
     * The longest prefix of a string that reads as a real number, or '0'.
     *
     * SQLite's TEXT -> REAL conversion: leading whitespace is skipped, the
     * longest well-formed numeric prefix (decimal point and exponent included)
     * is taken, and a string with no numeric prefix at all converts to 0.
     */
    private static function realPrefix(string $text): string
    {
        return self::matchPrefix('/^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?/', $text);
    }

    private static function matchPrefix(string $pattern, string $text): string
    {
        if (preg_match($pattern, $text, $m) === 1) {
            $prefix = trim($m[0]);
            if (is_numeric($prefix)) {
                return $prefix;
            }
        }
        return '0';
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
        if ($node->left instanceof RowValueNode) {
            return $this->evaluateRowIn($node, $row, $context);
        }

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

    /**
     * `(a, b) IN ((1, 2), (3, 4))` and `(a, b) IN (SELECT x, y FROM t)`
     *
     * Defined by SQL:2003 as the OR of the element-wise row comparisons, under
     * three-valued logic: TRUE as soon as one candidate row matches, UNKNOWN if
     * none matched but a NULL could have made one match, FALSE otherwise. An
     * empty candidate set is FALSE (and NOT IN is TRUE), the same as scalar IN.
     */
    private function evaluateRowIn(InOperation $node, ?object $row, array $context): int|null
    {
        /** @var RowValueNode $rowValue */
        $rowValue = $node->left;
        $degree = $rowValue->degree();

        $left = [];
        foreach ($rowValue->values as $expr) {
            $left[] = $this->evaluate($expr, $row, $context);
        }

        if ($node->isSubquery()) {
            if ($this->subqueryExecutor === null) {
                throw new \RuntimeException('Subquery executor not configured for IN clause');
            }
            $candidates = [];
            foreach (($this->subqueryExecutor)($node->values->query, $row) as $resultRow) {
                $candidates[] = array_values(get_object_vars($resultRow));
            }
        } else {
            $candidates = [];
            foreach ($node->values as $valueNode) {
                if (!$valueNode instanceof RowValueNode) {
                    throw new \RuntimeException(
                        'A row value on the left of IN requires row values on the right'
                    );
                }
                $candidate = [];
                foreach ($valueNode->values as $expr) {
                    $candidate[] = $this->evaluate($expr, $row, $context);
                }
                $candidates[] = $candidate;
            }
        }

        $unknown = false;
        foreach ($candidates as $candidate) {
            if (count($candidate) !== $degree) {
                throw new \RuntimeException(
                    'Row values in an IN predicate must have the same number of elements ('
                    . $degree . ' vs ' . count($candidate) . ')'
                );
            }

            $equal = true;
            foreach ($left as $i => $value) {
                if ($value === null || $candidate[$i] === null) {
                    $equal = null;
                    continue;
                }
                if (!self::valuesEqual($value, $candidate[$i])) {
                    $equal = false;
                    break;
                }
            }

            if ($equal === true) {
                return $node->negated ? 0 : 1;
            }
            if ($equal === null) {
                $unknown = true;
            }
        }

        if ($unknown) {
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

        $escape = null;
        if ($node->escape !== null) {
            $escape = $this->evaluate($node->escape, $row, $context);
            if ($escape === null) {
                return null;
            }
            $escape = (string)$escape;
            if (strlen($escape) !== 1) {
                throw new \RuntimeException('ESCAPE expression must be a single character');
            }
        }

        // NULL operand makes both LIKE and NOT LIKE UNKNOWN
        if ($value === null || $pattern === null) {
            return null;
        }

        $matches = (bool)preg_match(self::likeRegex((string)$pattern, $escape), (string)$value);

        return $node->negated ? !$matches : $matches;
    }

    /**
     * Compile a SQL LIKE pattern into a (case-insensitive) regex.
     *
     * `%` matches any run of characters, `_` matches exactly one. When an
     * ESCAPE character is given, the character following it is literal - that
     * is how `100#%` ESCAPE '#' matches the string "100%" and nothing else.
     * SQLite treats an escape before any other character as that plain
     * character, and a pattern ending in the escape character matches nothing.
     */
    public static function likeRegex(string $pattern, ?string $escape = null): string
    {
        $regex = '';
        $len = strlen($pattern);

        for ($i = 0; $i < $len; $i++) {
            $char = $pattern[$i];

            if ($escape !== null && $char === $escape) {
                if ($i + 1 >= $len) {
                    // Dangling escape: SQLite matches nothing at all
                    return '/(?!)/';
                }
                $regex .= preg_quote($pattern[++$i], '/');
                continue;
            }

            $regex .= match ($char) {
                '%' => '.*',
                '_' => '.',
                default => preg_quote($char, '/'),
            };
        }

        return '/^' . $regex . '$/i';
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
