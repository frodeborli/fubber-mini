<?php

namespace mini\Parsing\SQL\AST;

/**
 * Column definition in CREATE TABLE
 */
class ColumnDefinition extends ASTNode
{
    public string $type = 'COLUMN_DEFINITION';
    public string $name;
    public ?string $dataType = null;
    public ?int $length = null;
    public ?int $precision = null;
    public ?int $scale = null;
    public bool $primaryKey = false;
    public bool $autoIncrement = false;
    public bool $notNull = false;
    public bool $unique = false;
    public ?ASTNode $default = null;
    public ?string $references = null;
    public ?string $referencesColumn = null;
}
