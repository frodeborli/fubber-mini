<?php

namespace mini\Parsing\SQL\AST;

/**
 * CASE expression node
 *
 * Supports two forms:
 * 1. Simple CASE: CASE expr WHEN value1 THEN result1 [WHEN ...] [ELSE result] END
 * 2. Searched CASE: CASE WHEN condition1 THEN result1 [WHEN ...] [ELSE result] END
 */
class CaseWhenNode extends ASTNode
{
    public string $type = 'CASE_WHEN';

    /**
     * @param ASTNode|null $operand For simple CASE - the expression being compared. Null for searched CASE.
     * @param array $whenClauses Array of ['when' => ASTNode, 'then' => ASTNode] pairs
     * @param ASTNode|null $elseResult The ELSE result, or null if no ELSE clause
     */
    public function __construct(
        public ?ASTNode $operand,
        public array $whenClauses,
        public ?ASTNode $elseResult = null
    ) {}
}
