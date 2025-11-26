<?php

namespace mini\Parsing\SQL\AST;

/**
 * UPDATE statement node
 */
class UpdateStatement extends ASTNode
{
    public string $type = 'UPDATE_STATEMENT';
    public IdentifierNode $table;
    /** @var array[] Array of ['column' => IdentifierNode, 'value' => ASTNode] */
    public array $updates = [];
    public ?ASTNode $where = null;
}
