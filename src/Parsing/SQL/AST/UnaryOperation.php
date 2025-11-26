<?php

namespace mini\Parsing\SQL\AST;

/**
 * Unary operation node (e.g., -5, NOT expr)
 */
class UnaryOperation extends ASTNode
{
    public string $type = 'UNARY_OP';
    public string $operator;
    public ASTNode $expression;

    public function __construct(string $operator, ASTNode $expression)
    {
        $this->operator = $operator;
        $this->expression = $expression;
    }
}
