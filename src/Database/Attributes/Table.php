<?php

namespace mini\Database\Attributes;

/**
 * Maps entity class to database table
 *
 * Inspired by Entity Framework Core's [Table] attribute.
 *
 * Example:
 * ```php
 * #[Table(name: 'users')]
 * class User {
 *     // ...
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Table
{
    /**
     * @param string|null $name Table name (defaults to class name)
     */
    public function __construct(
        public ?string $name = null,
    ) {
    }
}
