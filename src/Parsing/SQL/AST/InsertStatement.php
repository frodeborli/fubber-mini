<?php

namespace mini\Parsing\SQL\AST;

/**
 * INSERT statement node
 *
 * Supports:
 * - INSERT INTO table VALUES (...)
 * - INSERT INTO table SELECT ...
 * - INSERT OR REPLACE INTO table VALUES (...)
 * - REPLACE INTO table VALUES (...)
 */
class InsertStatement extends ASTNode
{
    public string $type = 'INSERT_STATEMENT';
    public IdentifierNode $table;
    /** @var IdentifierNode[] */
    public array $columns = [];
    /** @var array[] Array of arrays of expressions */
    public array $values = [];
    /** @var ?SelectStatement For INSERT INTO ... SELECT ... syntax */
    public ?SelectStatement $select = null;
    /** @var bool True for REPLACE or INSERT OR REPLACE (upsert semantics) */
    public bool $replace = false;
}
