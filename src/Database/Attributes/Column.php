<?php

namespace mini\Database\Attributes;

/**
 * Maps property to database column
 *
 * Inspired by Entity Framework Core's [Column] attribute.
 *
 * Example:
 * ```php
 * #[Column(name: 'user_name', type: 'VARCHAR(255)', order: 1)]
 * public string $name;
 *
 * #[Column(type: 'TIMESTAMP')]
 * public \DateTimeImmutable $created_at;
 * ```
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Column
{
    /**
     * @param string|null $name Column name (defaults to property name)
     * @param string|null $type SQL type (e.g., 'VARCHAR(255)', 'INTEGER', 'TIMESTAMP')
     * @param int|null $order Column order (0-based index for table definition)
     */
    public function __construct(
        public ?string $name = null,
        public ?string $type = null,
        public ?int $order = null,
    ) {
    }
}
