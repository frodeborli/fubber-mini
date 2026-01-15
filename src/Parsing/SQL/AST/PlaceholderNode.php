<?php

namespace mini\Parsing\SQL\AST;

/**
 * Placeholder node (? or :name)
 *
 * Placeholders can have bound values attached. When a value is bound,
 * the placeholder knows both its token (for SQL rendering) and its
 * actual value (for evaluation and parameter collection).
 */
class PlaceholderNode extends ASTNode implements ValueNodeInterface
{
    public string $type = 'PLACEHOLDER';
    public string $token; // '?' or ':name'

    /**
     * The bound value for this placeholder (null if not bound)
     */
    public mixed $boundValue = null;

    /**
     * Whether this placeholder has a bound value
     *
     * Needed because null is a valid bound value.
     */
    public bool $isBound = false;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /**
     * Bind a value to this placeholder
     */
    public function bind(mixed $value): void
    {
        $this->boundValue = $value;
        $this->isBound = true;
    }
}
