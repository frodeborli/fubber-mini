<?php

namespace mini\Parsing\SQL\AST;

/**
 * Table-level constraint in CREATE TABLE
 */
class TableConstraint extends ASTNode
{
    public string $type = 'TABLE_CONSTRAINT';
    public ?string $name = null;
    public string $constraintType; // PRIMARY KEY, UNIQUE, FOREIGN KEY, CHECK
    /** @var string[] */
    public array $columns = [];
    public ?string $references = null;
    /** @var string[] */
    public array $referencesColumns = [];
    public ?string $onDelete = null;
    public ?string $onUpdate = null;
    public ?ASTNode $checkExpression = null;
}
