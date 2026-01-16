<?php

namespace mini\Parsing\SQL\AST;

/**
 * CREATE TABLE statement node
 */
class CreateTableStatement extends ASTNode
{
    public string $type = 'CREATE_TABLE_STATEMENT';
    public IdentifierNode $table;
    public bool $ifNotExists = false;
    /** @var ColumnDefinition[] */
    public array $columns = [];
    /** @var TableConstraint[] */
    public array $constraints = [];
}
