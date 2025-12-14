<?php

namespace mini\Parsing\SQL\AST;

/**
 * BETWEEN operation node
 *
 * Represents: expr BETWEEN start AND end
 * Or: expr NOT BETWEEN start AND end
 */
class BetweenOperation extends ASTNode
{
    public string $type = 'BETWEEN';

    public function __construct(
        public ASTNode $expression,
        public ASTNode $low,
        public ASTNode $high,
        public bool $negated = false
    ) {}
}
