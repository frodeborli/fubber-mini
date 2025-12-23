<?php

namespace mini\Parsing\SQL\AST;

/**
 * JOIN clause node
 *
 * Represents a single JOIN in a SELECT statement.
 * Supports INNER, LEFT, RIGHT, FULL, and CROSS joins.
 */
class JoinNode extends ASTNode
{
    public string $type = 'JOIN';

    /**
     * @param string $joinType JOIN type: 'INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS'
     * @param IdentifierNode|SubqueryNode $table Table being joined (or derived table)
     * @param ASTNode|null $condition ON condition (null for CROSS JOIN)
     * @param string|null $alias Optional table alias (required for derived tables)
     */
    public function __construct(
        public string $joinType,
        public IdentifierNode|SubqueryNode $table,
        public ?ASTNode $condition = null,
        public ?string $alias = null
    ) {}
}
