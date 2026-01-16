<?php

namespace mini\Parsing\SQL\AST;

/**
 * CREATE INDEX statement node
 */
class CreateIndexStatement extends ASTNode
{
    public string $type = 'CREATE_INDEX_STATEMENT';
    public string $name;
    public IdentifierNode $table;
    public bool $unique = false;
    public bool $ifNotExists = false;
    /** @var IndexColumn[] */
    public array $columns = [];
}

/**
 * Index column specification
 */
class IndexColumn extends ASTNode
{
    public string $type = 'INDEX_COLUMN';
    public string $name;
    public ?string $order = null; // ASC, DESC
}
