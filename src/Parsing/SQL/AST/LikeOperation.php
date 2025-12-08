<?php

namespace mini\Parsing\SQL\AST;

/**
 * LIKE / NOT LIKE operation node
 */
class LikeOperation extends ASTNode
{
    public string $type = 'LIKE';
    public ASTNode $left;
    public ASTNode $pattern;
    public bool $negated;

    public function __construct(ASTNode $left, ASTNode $pattern, bool $negated = false)
    {
        $this->left = $left;
        $this->pattern = $pattern;
        $this->negated = $negated;
    }
}
