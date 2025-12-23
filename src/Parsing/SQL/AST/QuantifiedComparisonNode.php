<?php

namespace mini\Parsing\SQL\AST;

/**
 * Quantified comparison node - col op ALL/ANY (SELECT ...)
 *
 * SQL:1999 quantified comparisons:
 * - ALL: True if comparison is true for all rows returned by subquery
 * - ANY/SOME: True if comparison is true for at least one row
 *
 * Examples:
 * - WHERE price > ALL (SELECT price FROM products WHERE category = 'tools')
 * - WHERE price > ANY (SELECT price FROM products WHERE category = 'tools')
 */
class QuantifiedComparisonNode extends ASTNode implements ValueNodeInterface
{
    public string $type = 'QUANTIFIED_COMPARISON';

    /**
     * @param ASTNode $left Left operand (column or expression)
     * @param string $operator Comparison operator (=, <, >, <=, >=, <>, !=)
     * @param string $quantifier 'ALL' or 'ANY'
     * @param SubqueryNode $subquery The subquery to compare against
     */
    public function __construct(
        public ASTNode $left,
        public string $operator,
        public string $quantifier,
        public SubqueryNode $subquery
    ) {}
}
