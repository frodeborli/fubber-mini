<?php

namespace mini\Parsing\SQL\AST;

/**
 * INSERT statement node
 */
class InsertStatement extends ASTNode
{
    public string $type = 'INSERT_STATEMENT';
    public IdentifierNode $table;
    /** @var IdentifierNode[] */
    public array $columns = [];
    /** @var array[] Array of arrays of expressions */
    public array $values = [];
}
