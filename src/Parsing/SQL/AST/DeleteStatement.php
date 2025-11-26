<?php

namespace mini\Parsing\SQL\AST;

/**
 * DELETE statement node
 */
class DeleteStatement extends ASTNode
{
    public string $type = 'DELETE_STATEMENT';
    public IdentifierNode $table;
    public ?ASTNode $where = null;
}
