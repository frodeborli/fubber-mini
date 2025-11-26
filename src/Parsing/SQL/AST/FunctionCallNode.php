<?php

namespace mini\Parsing\SQL\AST;

/**
 * Function call node (e.g., COUNT(*), MAX(col))
 */
class FunctionCallNode extends ASTNode
{
    public string $type = 'FUNCTION_CALL';
    public string $name;
    /** @var ASTNode[] */
    public array $arguments = [];

    public function __construct(string $name, array $arguments)
    {
        $this->name = $name;
        $this->arguments = $arguments;
    }
}
