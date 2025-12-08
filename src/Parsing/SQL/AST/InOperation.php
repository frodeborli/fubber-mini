<?php

namespace mini\Parsing\SQL\AST;

/**
 * IN / NOT IN operation node
 *
 * Examples:
 * - col IN (1, 2, 3)           → values is array of LiteralNode
 * - col IN (SELECT id FROM t)  → values is SubqueryNode
 * - col NOT IN (...)           → negated = true
 */
class InOperation extends ASTNode
{
    public string $type = 'IN_OP';
    public ASTNode $left;
    /** @var ASTNode[]|SubqueryNode Array of value expressions or a subquery */
    public array|SubqueryNode $values;
    public bool $negated;

    public function __construct(ASTNode $left, array|SubqueryNode $values, bool $negated = false)
    {
        $this->left = $left;
        $this->values = $values;
        $this->negated = $negated;
    }

    /**
     * Check if values is a subquery
     */
    public function isSubquery(): bool
    {
        return $this->values instanceof SubqueryNode;
    }
}
