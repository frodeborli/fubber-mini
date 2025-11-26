<?php

namespace mini\Parsing\SQL\AST;

/**
 * Identifier node (table names, column names)
 */
class IdentifierNode extends ASTNode
{
    public string $type = 'IDENTIFIER';
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
