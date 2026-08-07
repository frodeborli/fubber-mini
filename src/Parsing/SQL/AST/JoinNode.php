<?php

namespace mini\Parsing\SQL\AST;

/**
 * JOIN clause node
 *
 * Represents a single JOIN in a SELECT statement.
 * Supports INNER, LEFT, RIGHT, FULL, and CROSS joins.
 *
 * The join condition is expressed in one of three SQL:2003 forms, exactly one
 * of which is present:
 *
 * - `ON <search condition>`  -> $condition
 * - `USING (a, b, ...)`      -> $using (named join columns)
 * - `NATURAL`                -> $natural (join columns are the common ones)
 *
 * CROSS JOIN has none of them.
 */
class JoinNode extends ASTNode
{
    public string $type = 'JOIN';

    /**
     * @param string $joinType JOIN type: 'INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS'
     * @param IdentifierNode|SubqueryNode $table Table being joined (or derived table)
     * @param ASTNode|null $condition ON condition (null for CROSS/USING/NATURAL joins)
     * @param string|null $alias Optional table alias (required for derived tables)
     * @param list<string>|null $using USING column names (null when not a USING join)
     * @param bool $natural True for NATURAL joins (join columns resolved at plan time)
     */
    public function __construct(
        public string $joinType,
        public IdentifierNode|SubqueryNode $table,
        public ?ASTNode $condition = null,
        public ?string $alias = null,
        public ?array $using = null,
        public bool $natural = false
    ) {}
}
