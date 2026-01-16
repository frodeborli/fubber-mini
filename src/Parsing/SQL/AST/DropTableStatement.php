<?php

namespace mini\Parsing\SQL\AST;

/**
 * DROP TABLE statement node
 */
class DropTableStatement extends ASTNode
{
    public string $type = 'DROP_TABLE_STATEMENT';
    public IdentifierNode $table;
    public bool $ifExists = false;
}
