<?php

namespace mini\Parsing\SQL\AST;

/**
 * Niladic function node (SQL standard functions without parentheses)
 *
 * Examples: CURRENT_DATE, CURRENT_TIME, CURRENT_TIMESTAMP
 */
class NiladicFunctionNode extends ASTNode implements ValueNodeInterface
{
    public string $type = 'NILADIC_FUNCTION';
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
