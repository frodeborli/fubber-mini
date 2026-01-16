<?php

namespace mini\Parsing\SQL\AST;

/**
 * DROP INDEX statement node
 */
class DropIndexStatement extends ASTNode
{
    public string $type = 'DROP_INDEX_STATEMENT';
    public string $name;
    public ?IdentifierNode $table = null; // Some dialects require ON table
    public bool $ifExists = false;
}
