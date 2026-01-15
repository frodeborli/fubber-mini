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

    /**
     * Create a deep clone of this AST node
     *
     * Recursively clones all child nodes to ensure complete independence.
     */
    public function deepClone(): static
    {
        $clone = (new \ReflectionClass($this))->newInstanceWithoutConstructor();

        foreach (get_object_vars($this) as $name => $value) {
            $clone->$name = self::cloneValue($value);
        }

        return $clone;
    }

    /**
     * Recursively clone a value (ASTNode, array, or scalar)
     */
    private static function cloneValue(mixed $value): mixed
    {
        if ($value instanceof ASTNode) {
            return $value->deepClone();
        }

        if (is_array($value)) {
            return array_map(fn($v) => self::cloneValue($v), $value);
        }

        return $value;
    }
}
