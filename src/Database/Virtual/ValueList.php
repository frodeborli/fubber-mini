<?php

namespace mini\Database\Virtual;

/**
 * A list of values implementing ValueInterface
 *
 * Wraps an array of PHP values for use in IN clause evaluation.
 * Unlike LazySubquery, this is already materialized.
 */
class ValueList implements ValueInterface
{
    public function __construct(
        private array $values
    ) {}

    public function contains(mixed $value): bool
    {
        // Loose comparison to match SQL semantics
        return in_array($value, $this->values, false);
    }

    public function compareTo(mixed $value): int
    {
        $scalar = $this->getValue();
        return $scalar <=> $value;
    }

    public function getValue(): mixed
    {
        if (count($this->values) === 0) {
            throw new \RuntimeException("Value list is empty (expected exactly 1 for scalar context)");
        }

        if (count($this->values) > 1) {
            throw new \RuntimeException("Value list has " . count($this->values) . " elements (expected exactly 1 for scalar context)");
        }

        return $this->values[0];
    }

    public function toArray(): array
    {
        return $this->values;
    }

    public function isScalar(): bool
    {
        return count($this->values) === 1;
    }
}
