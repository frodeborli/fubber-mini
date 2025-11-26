<?php

namespace mini\Database\Attributes;

/**
 * Creates database index
 *
 * Inspired by Entity Framework Core's [Index] attribute.
 *
 * For single-column indexes, apply to the property.
 * For composite indexes, apply to the class level.
 *
 * Single column example:
 * ```php
 * #[Index]
 * public string $email;
 *
 * #[Index(unique: true)]
 * public string $username;
 * ```
 *
 * Composite index example:
 * ```php
 * #[Index(columns: ['last_name', 'first_name'])]
 * #[Index(columns: ['email'], unique: true)]
 * class User {
 *     public string $first_name;
 *     public string $last_name;
 *     public string $email;
 * }
 * ```
 *
 * Descending indexes:
 * ```php
 * #[Index(columns: ['created_at'], descending: true)]
 * class Post { }
 *
 * // Per-column control for composite
 * #[Index(columns: ['category', 'created_at'], descending: [false, true])]
 * class Article { }
 * ```
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class Index
{
    /**
     * @param array|null $columns Column names for composite index (class-level only)
     * @param string|null $name Index name (auto-generated if not provided)
     * @param bool $unique Whether this is a unique index
     * @param bool|array $descending True for all DESC, or array of bool per column
     */
    public function __construct(
        public ?array $columns = null,
        public ?string $name = null,
        public bool $unique = false,
        public bool|array $descending = false,
    ) {
    }
}
