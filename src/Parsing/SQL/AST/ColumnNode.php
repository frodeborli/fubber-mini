<?php

namespace mini\Parsing\SQL\AST;

/**
 * Column reference in SELECT clause
 */
class ColumnNode extends ASTNode
{
    public string $type = 'COLUMN';
    public ASTNode $expression;
    public ?string $alias;

    public function __construct(ASTNode $expression, ?string $alias = null)
    {
        $this->expression = $expression;
        $this->alias = $alias;
    }
}
