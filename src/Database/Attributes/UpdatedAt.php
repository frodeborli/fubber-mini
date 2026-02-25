<?php

namespace mini\Database\Attributes;

/**
 * Marks property as an update timestamp
 *
 * Query: Parsed internally by Dehydrator — never use ReflectionClass directly.
 *
 * During dehydration, the property value is always set to the current datetime.
 *
 * Works with DateTimeImmutable, DateTime, and string properties.
 *
 * Example:
 * ```php
 * #[UpdatedAt]
 * public ?\DateTimeImmutable $updated_at = null;
 *
 * #[UpdatedAt]
 * public string $updated_at;
 * ```
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class UpdatedAt
{
}
