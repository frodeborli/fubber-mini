<?php

namespace mini\Database\Attributes;

/**
 * Maps entity class to database table
 *
 * Query: `model(User::class)->tableName` — never use ReflectionClass directly.
 *
 * Example:
 * ```php
 * #[Table('users')]
 * class User extends Model { ... }
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
