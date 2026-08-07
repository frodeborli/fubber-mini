<?php

namespace mini\Parsing\SQL\AST;

/**
 * Row value constructor: `(a, b)` — SQL:2003 <row value constructor>
 *
 * A row value is not a scalar, so it can only appear where the standard says a
 * row value is expected:
 *
 * - both sides of a comparison: `WHERE (a, b) = (1, 2)`, `(a, b) < (1, 2)`
 * - the operands of IN: `WHERE (a, b) IN ((1, 2), (3, 4))`
 * - the right-hand side of an IN subquery: `WHERE (a, b) IN (SELECT x, y FROM t)`
 *
 * Anywhere else it is a syntax error the evaluator reports as such, rather than
 * silently collapsing to its first element the way some engines do.
 *
 * A single parenthesised expression `(a)` is *not* a row value — it is just a
 * grouped expression, which is what SQL:2003 says too.
 */
class RowValueNode extends ASTNode
{
    public string $type = 'ROW_VALUE';

    /**
     * @param ASTNode[] $values Element expressions, at least two
     */
    public function __construct(public array $values) {}

    /** Number of elements — the row's "degree" in standard terminology */
    public function degree(): int
    {
        return count($this->values);
    }
}
