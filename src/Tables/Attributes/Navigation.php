<?php

namespace mini\Tables\Attributes;

use Attribute;

/**
 * Navigation property attribute for relationships
 *
 * Marks a property as a navigation property that should NOT be mapped to the database.
 * Instead, it represents a relationship to another entity.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Navigation
{
    public function __construct(
        public string $targetClass,
        public string $foreignKey,
        public string $targetKey = 'id',
        public bool $nullable = true
    ) {}
}
