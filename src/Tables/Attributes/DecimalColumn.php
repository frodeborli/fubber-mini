<?php

namespace mini\Tables\Attributes;

use Attribute;

/**
 * DECIMAL column attribute for precise numeric storage
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class DecimalColumn extends Column
{
    public function __construct(
        ?string $name = null,
        ?int $precision = 10,
        ?int $scale = 2,
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
            type: 'number',
            nullable: $nullable,
            default: $default,
            precision: $precision,
            scale: $scale,
            minimum: $minimum,
            maximum: $maximum,
            exclusiveMinimum: $exclusiveMinimum,
            exclusiveMaximum: $exclusiveMaximum,
            multipleOf: $multipleOf
        );
    }
}
