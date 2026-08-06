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

    /** ESCAPE character expression, or null when the pattern has no escape */
    public ?ASTNode $escape;

    public function __construct(ASTNode $left, ASTNode $pattern, bool $negated = false, ?ASTNode $escape = null)
    {
        $this->left = $left;
        $this->pattern = $pattern;
        $this->negated = $negated;
        $this->escape = $escape;
    }
}
