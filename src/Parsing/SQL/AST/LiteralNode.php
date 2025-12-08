<?php

namespace mini\Parsing\SQL\AST;

/**
 * Literal value node (strings, numbers, NULL)
 */
class LiteralNode extends ASTNode implements ValueNodeInterface
{
    public string $type = 'LITERAL';
    public mixed $value;
    public string $valueType; // 'string' or 'number'

    public function __construct(mixed $value, string $valueType)
    {
        $this->value = $value;
        $this->valueType = $valueType;
    }
}
