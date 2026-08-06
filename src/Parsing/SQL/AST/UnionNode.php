<?php

namespace mini\Parsing\SQL\AST;

/**
 * Set operation node (UNION, INTERSECT, EXCEPT)
 *
 * Represents: SELECT ... UNION|INTERSECT|EXCEPT [ALL] SELECT ...
 */
class UnionNode extends ASTNode
{
    public string $type = 'SET_OPERATION';

    /**
     * Trailing ORDER BY / LIMIT / OFFSET.
     *
     * Per SQL:2003 a trailing `ORDER BY` (and the LIMIT/OFFSET that follows it)
     * belongs to the whole `<query expression>`, not to the last operand — the
     * operands of a set operator are `<query term>`s which cannot carry their
     * own sort. The parser therefore lifts them off the last branch onto this
     * node.
     *
     * @var array|null ORDER BY items, same shape as SelectStatement::$orderBy
     */
    public ?array $orderBy = null;
    public ?ASTNode $limit = null;
    public ?ASTNode $offset = null;

    public function __construct(
        public ASTNode $left,
        public ASTNode $right,
        public bool $all = false,
        public string $operator = 'UNION',
    ) {}
}
