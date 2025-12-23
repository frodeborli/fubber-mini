<?php

namespace mini\Parsing\SQL\AST;

/**
 * Window function node - func() OVER (...)
 *
 * SQL:2003 window functions with OVER clause:
 * - ROW_NUMBER() OVER (ORDER BY col)
 * - RANK() OVER (PARTITION BY cat ORDER BY col DESC)
 * - DENSE_RANK(), etc.
 */
class WindowFunctionNode extends ASTNode implements ValueNodeInterface
{
    public string $type = 'WINDOW_FUNCTION';

    /**
     * @param FunctionCallNode $function The window function (ROW_NUMBER, RANK, etc.)
     * @param ASTNode[] $partitionBy PARTITION BY expressions (optional)
     * @param array $orderBy ORDER BY clauses [{expr: ASTNode, direction: 'ASC'|'DESC'}, ...]
     */
    public function __construct(
        public FunctionCallNode $function,
        public array $partitionBy = [],
        public array $orderBy = []
    ) {}
}
