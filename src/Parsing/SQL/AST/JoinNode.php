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
     * @param IdentifierNode $table Table being joined
     * @param ASTNode|null $condition ON condition (null for CROSS JOIN)
     * @param string|null $alias Optional table alias
     */
    public function __construct(
        public string $joinType,
        public IdentifierNode $table,
        public ?ASTNode $condition = null,
        public ?string $alias = null
    ) {}
}
