<?php

namespace mini\Parsing\SQL\AST;

/**
 * WITH clause wrapper (Common Table Expressions)
 *
 * Supports:
 * - WITH cte AS (SELECT ...) SELECT ...
 * - WITH RECURSIVE cte AS (...) SELECT ...
 * - WITH cte1 AS (...), cte2 AS (...) SELECT ...
 */
class WithStatement extends ASTNode
{
    public string $type = 'WITH_STATEMENT';

    /**
     * @param array $ctes Array of CTE definitions: ['name' => string, 'columns' => ?array, 'query' => ASTNode]
     * @param bool $recursive Whether WITH RECURSIVE was specified
     * @param ASTNode $query The main query (SelectStatement or UnionNode)
     */
    public function __construct(
        public array $ctes,
        public bool $recursive,
        public ASTNode $query
    ) {}
}
