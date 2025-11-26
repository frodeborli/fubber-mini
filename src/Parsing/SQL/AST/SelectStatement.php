<?php

namespace mini\Parsing\SQL\AST;

/**
 * SELECT statement node
 */
class SelectStatement extends ASTNode
{
    public string $type = 'SELECT_STATEMENT';
    public array $columns = [];
    public ?IdentifierNode $from = null;
    public ?ASTNode $where = null;
    public ?array $orderBy = null;
    public ?int $limit = null;
}
