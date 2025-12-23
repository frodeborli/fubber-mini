<?php

namespace mini\Parsing\SQL\AST;

/**
 * Set operation node (UNION, INTERSECT, EXCEPT)
 *
 * Represents: SELECT ... UNION|INTERSECT|EXCEPT [ALL] SELECT ...
 */
class UnionNode extends ASTNode
{
    public string $type = 'SET_OPERATION';

    public function __construct(
        public ASTNode $left,
        public ASTNode $right,
        public bool $all = false,
        public string $operator = 'UNION',
    ) {}
}
