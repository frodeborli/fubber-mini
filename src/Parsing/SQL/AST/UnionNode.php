<?php

namespace mini\Parsing\SQL\AST;

/**
 * UNION operation node
 *
 * Represents: SELECT ... UNION [ALL] SELECT ...
 */
class UnionNode extends ASTNode
{
    public string $type = 'UNION';

    public function __construct(
        public ASTNode $left,
        public ASTNode $right,
        public bool $all = false,
    ) {}
}
