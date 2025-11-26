<?php

namespace mini\Database\Attributes;

/**
 * Marks property as primary key
 *
 * Inspired by Entity Framework Core's [Key] attribute.
 *
 * Example:
 * ```php
 * #[PrimaryKey]
 * public ?int $id = null;
 *
 * // Non-auto-increment primary key
 * #[PrimaryKey(autoIncrement: false)]
 * public string $uuid;
 * ```
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class PrimaryKey
{
    /**
     * @param bool $autoIncrement Whether this is an auto-increment column
     */
    public function __construct(
        public bool $autoIncrement = true,
    ) {
    }
}
