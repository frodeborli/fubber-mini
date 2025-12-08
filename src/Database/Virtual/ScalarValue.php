<?php

namespace mini\Database\Virtual;

/**
 * A scalar value wrapper implementing ValueInterface
 *
 * Wraps a single PHP value (string, int, float, null) for use in SQL evaluation.
 * Treats the scalar as a single-element set for containment checks.
 */
class ScalarValue implements ValueInterface
{
    public function __construct(
        private mixed $value
    ) {}

    public function contains(mixed $value): bool
    {
        // Loose comparison to match SQL semantics
        return $this->value == $value;
    }

    public function compareTo(mixed $value): int
    {
        return $this->value <=> $value;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function toArray(): array
    {
        return [$this->value];
    }

    public function isScalar(): bool
    {
        return true;
    }
}
