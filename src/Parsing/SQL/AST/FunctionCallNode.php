<?php

namespace mini\Parsing\SQL\AST;

/**
 * Function call node (e.g., COUNT(*), MAX(col), COUNT(DISTINCT col))
 */
class FunctionCallNode extends ASTNode
{
    public string $type = 'FUNCTION_CALL';
    public string $name;
    /** @var ASTNode[] */
    public array $arguments = [];
    public bool $distinct = false;

    public function __construct(string $name, array $arguments, bool $distinct = false)
    {
        $this->name = $name;
        $this->arguments = $arguments;
        $this->distinct = $distinct;
    }
}
