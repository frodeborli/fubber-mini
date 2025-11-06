<?php

namespace mini\Tables\Attributes;

use Attribute;

/**
 * Entity attribute for class-level table mapping
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Entity
{
    public function __construct(
        public string $table,
        public ?string $schema = null
    ) {}

    /**
     * Get the full table name including schema if specified
     */
    public function getFullTableName(): string
    {
        return $this->schema ? "{$this->schema}.{$this->table}" : $this->table;
    }
}
