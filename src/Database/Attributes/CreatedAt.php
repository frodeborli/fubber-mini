<?php

namespace mini\Database\Attributes;

/**
 * Marks property as a creation timestamp
 *
 * Query: Parsed internally by Dehydrator — never use ReflectionClass directly.
 *
 * During dehydration, if the property value is null or uninitialized,
 * it will be set to the current datetime.
 *
 * Works with DateTimeImmutable, DateTime, and string properties.
 *
 * Example:
 * ```php
 * #[CreatedAt]
 * public ?\DateTimeImmutable $created_at = null;
 *
 * #[CreatedAt]
 * public string $created_at;
 * ```
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class CreatedAt
{
}
