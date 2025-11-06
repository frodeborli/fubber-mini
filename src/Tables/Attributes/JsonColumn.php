<?php

namespace mini\Tables\Attributes;

use Attribute;

/**
 * JSON column attribute for JSON object/array storage
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class JsonColumn extends Column
{
    public function __construct(
        ?string $name = null,
        bool $nullable = false,
        mixed $default = null,
        ?array $properties = null,     // Schema for object properties
        ?array $required = null,       // Required object properties
        ?array $items = null           // Schema for array items
    ) {
        parent::__construct(
            name: $name,
            type: 'json',
            nullable: $nullable,
            default: $default,
            properties: $properties,
            required: $required,
            items: $items
        );
    }
}
