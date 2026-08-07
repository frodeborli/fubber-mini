<?php

namespace mini\Parsing\SQL\AST;

/**
 * `a IS [NOT] DISTINCT FROM b` — SQL:2003 F291, NULL-safe comparison
 *
 * Two values are *distinct* when they are not the same value, treating NULL as
 * a value that equals only itself. Unlike `=` and `<>`, the result is never
 * UNKNOWN:
 *
 * ```
 * NULL IS DISTINCT FROM NULL      -- FALSE
 * NULL IS DISTINCT FROM 1         -- TRUE
 * 1    IS DISTINCT FROM 1         -- FALSE
 * NULL IS NOT DISTINCT FROM NULL  -- TRUE
 * ```
 *
 * It is a distinct node rather than a `BinaryOperation` with an exotic operator
 * string because the VirtualDatabase pushdown paths pattern-match on
 * `BinaryOperation` operators, and a NULL-safe operator that looks like `=`
 * would be constant-folded to "matches nothing" by the NULL-propagation rules
 * that are correct for every other comparison.
 */
class DistinctFromOperation extends ASTNode
{
    public string $type = 'IS_DISTINCT_FROM';

    /**
     * @param bool $negated True for `IS NOT DISTINCT FROM`
     */
    public function __construct(
        public ASTNode $left,
        public ASTNode $right,
        public bool $negated = false,
    ) {}
}
