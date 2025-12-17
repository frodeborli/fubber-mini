<?php

namespace mini\Parsing\SQL\AST;

/**
 * EXISTS operation AST node
 *
 * Represents: EXISTS (SELECT ...) or NOT EXISTS (SELECT ...)
 */
class ExistsOperation extends ASTNode
{
    public string $type = 'EXISTS';

    public function __construct(
        public SubqueryNode $subquery,
        public bool $negated = false,
    ) {}
}
