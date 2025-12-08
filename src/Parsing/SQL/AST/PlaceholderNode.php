<?php

namespace mini\Parsing\SQL\AST;

/**
 * Placeholder node (? or :name)
 */
class PlaceholderNode extends ASTNode implements ValueNodeInterface
{
    public string $type = 'PLACEHOLDER';
    public string $token; // '?' or ':name'

    public function __construct(string $token)
    {
        $this->token = $token;
    }
}
