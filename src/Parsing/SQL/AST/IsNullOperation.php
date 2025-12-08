<?php

namespace mini\Parsing\SQL\AST;

/**
 * IS NULL / IS NOT NULL operation node
 */
class IsNullOperation extends ASTNode
{
    public string $type = 'IS_NULL';
    public ASTNode $expression;
    public bool $negated;

    public function __construct(ASTNode $expression, bool $negated = false)
    {
        $this->expression = $expression;
        $this->negated = $negated;
    }
}
