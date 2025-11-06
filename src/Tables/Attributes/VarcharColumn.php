<?php

namespace mini\Tables\Attributes;

use Attribute;

/**
 * VARCHAR column attribute for string storage with length constraint
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class VarcharColumn extends Column
{
    public function __construct(
        ?string $name = null,
        ?int $length = 255,
        bool $nullable = false,
        mixed $default = null,
        ?int $minLength = null,
        ?int $maxLength = null,
        ?string $pattern = null,
        ?string $format = null,
        ?array $enum = null
    ) {
        parent::__construct(
            name: $name,
            type: 'string',
            nullable: $nullable,
            default: $default,
            length: $length,
            minLength: $minLength,
            maxLength: $maxLength,
            pattern: $pattern,
            format: $format,
            enum: $enum
        );
    }
}
