<?php

namespace mini\Tables\Attributes;

use Attribute;

/**
 * BOOLEAN column attribute for true/false storage
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class BooleanColumn extends Column
{
    public function __construct(
        ?string $name = null,
        bool $nullable = false,
        mixed $default = null
    ) {
        parent::__construct(
            name: $name,
            type: 'boolean',
            nullable: $nullable,
            default: $default
        );
    }
}
