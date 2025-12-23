<?php

namespace mini\Parsing\SQL\AST;

/**
 * SELECT statement node
 */
class SelectStatement extends ASTNode
{
    public string $type = 'SELECT_STATEMENT';
    public bool $distinct = false;
    public array $columns = [];
    /** Table name (IdentifierNode) or derived table (SubqueryNode) */
    public IdentifierNode|SubqueryNode|null $from = null;
    public ?string $fromAlias = null;
    /** @var JoinNode[] */
    public array $joins = [];
    public ?ASTNode $where = null;
    /** @var array[] Array of ['column' => ASTNode] */
    public ?array $groupBy = null;
    public ?ASTNode $having = null;
    public ?array $orderBy = null;
    public ?ASTNode $limit = null;
    public ?ASTNode $offset = null;
}
