<?php

namespace mini\Parsing\SQL\AST;

/**
 * Subquery node - a SELECT statement used as a value expression
 *
 * Subqueries can appear in various contexts:
 * - IN clause: WHERE id IN (SELECT user_id FROM orders)
 * - Scalar comparison: WHERE count = (SELECT MAX(count) FROM stats)
 * - EXISTS: WHERE EXISTS (SELECT 1 FROM orders WHERE user_id = users.id)
 *
 * The context determines how the subquery result is interpreted:
 * - IN: uses all rows from first column
 * - Scalar: expects exactly one row/column, errors otherwise
 * - EXISTS: checks if any rows returned
 */
class SubqueryNode extends ASTNode implements ValueNodeInterface
{
    public string $type = 'SUBQUERY';
    public SelectStatement|UnionNode|WithStatement $query;

    public function __construct(SelectStatement|UnionNode|WithStatement $query)
    {
        $this->query = $query;
    }
}
