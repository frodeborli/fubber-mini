<?php

namespace mini\Parsing\SQL\AST;

/**
 * Binary operation node (e.g., a = b, x > 5, col AND col2)
 */
class BinaryOperation extends ASTNode
{
    public string $type = 'BINARY_OP';
    public ASTNode $left;
    public string $operator;
    public ASTNode $right;

    public function __construct(ASTNode $left, string $operator, ASTNode $right)
    {
        $this->left = $left;
        $this->operator = $operator;
        $this->right = $right;
    }
}
