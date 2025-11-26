<?php

namespace mini\Parsing\SQL\AST;

/**
 * IN operation node (e.g., col IN (1, 2, 3) or col IN (SELECT...))
 */
class InOperation extends ASTNode
{
    public string $type = 'IN_OP';
    public ASTNode $left;
    public bool $isSubquery;
    /** @var ASTNode[]|SelectStatement Either array of expressions or a subquery */
    public array|SelectStatement $values;

    public function __construct(ASTNode $left, bool $isSubquery, array|SelectStatement $values)
    {
        $this->left = $left;
        $this->isSubquery = $isSubquery;
        $this->values = $values;
    }
}
