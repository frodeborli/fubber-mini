<?php

namespace mini\Attributes;

use Attribute;

/**
 * TEXT column attribute for large string storage
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class TextColumn extends Column
{
    public function __construct(
        ?string $name = null,
        bool $nullable = false,
        mixed $default = null,
        ?int $minLength = null,
        ?int $maxLength = null,
        ?string $pattern = null,
        ?string $format = null
    ) {
        parent::__construct(
            name: $name,
            type: 'string',
            nullable: $nullable,
            default: $default,
            minLength: $minLength,
            maxLength: $maxLength,
            pattern: $pattern,
            format: $format
        );
    }
}