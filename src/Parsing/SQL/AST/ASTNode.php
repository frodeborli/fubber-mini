<?php

namespace mini\Parsing\SQL\AST;

/**
 * Abstract base class for all AST nodes
 *
 * Provides JSON serialization for debugging and inspection
 */
abstract class ASTNode implements \JsonSerializable
{
    public function jsonSerialize(): mixed
    {
        return get_object_vars($this);
    }
}
