<?php

namespace mini\Attributes;

use Attribute;

/**
 * INTEGER column attribute for whole number storage
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IntegerColumn extends Column
{
    public function __construct(
        ?string $name = null,
        bool $nullable = false,
        mixed $default = null,
        ?float $minimum = null,
        ?float $maximum = null,
        ?float $exclusiveMinimum = null,
        ?float $exclusiveMaximum = null,
        ?float $multipleOf = null
    ) {
        parent::__construct(
            name: $name,
            type: 'integer',
            nullable: $nullable,
            default: $default,
            minimum: $minimum,
            maximum: $maximum,
            exclusiveMinimum: $exclusiveMinimum,
            exclusiveMaximum: $exclusiveMaximum,
            multipleOf: $multipleOf
        );
    }
}